<?php

declare(strict_types=1);

namespace App\Services\Nntp;

/**
 * Single-connection NNTP client for NZB body and article retrieval.
 *
 * This class is standalone and completely independent from ParallelNntp — they
 * share no sockets, no state, and no base class. NntpClient handles serial
 * operations (BODY, HEAD, XOVER) on a single connection with optional GZIP
 * compression. For high-throughput parallel HEAD fetches across many connections,
 * see ParallelNntp.
 */
class NntpClient
{
    private mixed $socket = null;

    // Compression support
    private bool $compressionSupported = true;

    private bool $compressionEnabled = false;

    private ?string $lastResponse = null;

    // Buffer size for reading (larger = faster)
    private int $readBufferSize = 65536;

    public function __construct(private readonly string $host, private readonly int $port = 563, private readonly bool $ssl = true, private readonly string $username = '', private readonly string $password = '', private readonly int $timeout = 60) {}

    /**
     * Create from config array
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            $config['host'],
            (int) $config['port'],
            (bool) $config['ssl'],
            $config['username'] ?? '',
            $config['password'] ?? '',
            (int) ($config['timeout'] ?? 60)
        );
    }

    /**
     * Connect to the NNTP server
     */
    public function connect(bool $enableCompression = true): void
    {
        $protocol = $this->ssl ? 'ssl' : 'tcp';
        $address = "$protocol://{$this->host}:{$this->port}";

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $this->socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! $this->socket) {
            throw new NntpException("Failed to connect to $address: $errstr ($errno)");
        }

        // Set socket options for performance
        stream_set_timeout($this->socket, $this->timeout);
        stream_set_read_buffer($this->socket, $this->readBufferSize);

        // Read greeting
        $response = $this->readResponse();

        if (! str_starts_with($response, '200') && ! str_starts_with($response, '201')) {
            throw new NntpException("Unexpected greeting: $response");
        }

        // Authenticate if credentials provided
        if ($this->username !== '' && $this->username !== '0') {
            $this->authenticate();
        }

        // Try to enable compression for faster downloads
        if ($enableCompression) {
            $this->enableCompression();
        }
    }

    /**
     * Authenticate with username/password
     */
    private function authenticate(): void
    {
        $this->sendCommand("AUTHINFO USER {$this->username}");
        $response = $this->readResponse();

        if (str_starts_with($response, '381')) {
            $this->sendCommand("AUTHINFO PASS {$this->password}");
            $response = $this->readResponse();
        }

        if (! str_starts_with($response, '281')) {
            throw new NntpException("Authentication failed: $response");
        }
    }

    /**
     * Try to enable XFEATURE GZIP compression
     * This can speed up header downloads by 10-50x
     */
    public function enableCompression(): bool
    {
        if ($this->compressionEnabled) {
            return true;
        }

        if (! $this->compressionSupported) {
            return false;
        }

        try {
            $this->sendCommand('XFEATURE COMPRESS GZIP');
            $response = $this->readResponse();

            if (str_starts_with($response, '290')) {
                $this->compressionEnabled = true;

                return true;
            }
        } catch (\Throwable) {
            // Compression not supported
        }

        $this->compressionSupported = false;

        return false;
    }

    /**
     * Check if compression is enabled
     */
    public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }

    /**
     * Select a newsgroup
     *
     * @return array{count: int, first: int, last: int, group: string}
     */
    public function group(string $groupName): array
    {
        $this->sendCommand("GROUP $groupName");
        $response = $this->readResponse();

        if (! str_starts_with($response, '211')) {
            throw new NntpException("Failed to select group $groupName: $response");
        }

        // Parse: 211 count first last group
        $parts = explode(' ', $response);

        return [
            'count' => (int) ($parts[1] ?? 0),
            'first' => (int) ($parts[2] ?? 0),
            'last' => (int) ($parts[3] ?? 0),
            'group' => $parts[4] ?? $groupName,
        ];
    }

    /**
     * Get article overview (XOVER)
     * Uses GZIP compression when available for much faster downloads
     *
     * @return array<int, array{number: int, subject: string, from: string, date: string, message_id: string, references: string, bytes: int, lines: int, xref: string, headers: array}>
     */
    public function xover(int $start, int $end): array
    {
        $this->sendCommand("XOVER $start-$end");
        $response = $this->readResponse();

        if (! str_starts_with($response, '224')) {
            throw new NntpException("XOVER failed: $response");
        }

        // Get text response (handles compression if enabled)
        $lines = $this->getTextResponse();

        $articles = [];

        foreach ($lines as $line) {
            if ($line === '' || $line === '.') {
                continue;
            }

            $parts = explode("\t", $line);

            if (\count($parts) >= 8) {
                $number = (int) $parts[0];
                $articles[$number] = [
                    'number' => $number,
                    'subject' => $this->decodeHeader($parts[1]),
                    'from' => $this->decodeHeader($parts[2]),
                    'date' => $parts[3],
                    'message_id' => trim($parts[4], '<>'),
                    'references' => $parts[5],
                    'bytes' => (int) $parts[6],
                    'lines' => (int) $parts[7],
                    'xref' => $parts[8] ?? '',
                    'headers' => [],
                ];
            }
        }

        return $articles;
    }

    /**
     * Get specific header for a range of articles (XHDR or HDR)
     * This is MUCH faster than calling HEAD for each article
     * Uses GZIP compression when available
     *
     * @return array<int, string> Article number => header value
     */
    public function xhdr(string $header, int $start, int $end): array
    {
        // Try XHDR first (older servers), then HDR (RFC 3977)
        $commands = ["XHDR $header $start-$end", "HDR $header $start-$end"];
        $response = null;
        $success = false;

        foreach ($commands as $cmd) {
            $this->sendCommand($cmd);
            $response = $this->readResponse();

            // 221 = XHDR success, 225 = HDR success
            if (str_starts_with($response, '221') || str_starts_with($response, '225')) {
                $success = true;
                break;
            }

            // If command not recognized, try next
            if (str_starts_with($response, '500') || str_starts_with($response, '501') || str_starts_with($response, '400')) {
                continue;
            }

            // Other error - throw
            throw new NntpException("Header fetch failed: $response");
        }

        // Check if we got a success response
        if (! $success) {
            throw new NntpException("Neither XHDR nor HDR supported: $response");
        }

        // Get text response (handles compression if enabled)
        $lines = $this->getTextResponse();

        $results = [];

        foreach ($lines as $line) {
            if ($line === '' || $line === '.') {
                continue;
            }

            // Format: "article_number header_value"
            $spacePos = strpos($line, ' ');
            if ($spacePos !== false) {
                $articleNum = (int) substr($line, 0, $spacePos);
                $value = substr($line, $spacePos + 1);

                // Skip "(none)" values
                if ($value !== '(none)' && $value !== '') {
                    $results[$articleNum] = $this->decodeHeader($value);
                }
            }
        }

        return $results;
    }

    /**
     * Batch fetch multiple headers for a range of articles
     * Returns array keyed by article number, each containing requested headers
     *
     * @param  array<string>  $headers  List of header names to fetch
     * @return array<int, array<string, string>>
     */
    public function xhdrMultiple(array $headers, int $start, int $end): array
    {
        $results = [];

        foreach ($headers as $header) {
            $headerData = $this->xhdr($header, $start, $end);

            foreach ($headerData as $articleNum => $value) {
                if (! isset($results[$articleNum])) {
                    $results[$articleNum] = [];
                }
                $results[$articleNum][strtolower($header)] = $value;
            }
        }

        return $results;
    }

    /**
     * Get article headers (HEAD)
     *
     * Spotnet uses a non-standard approach where long headers like X-XML
     * are split across multiple lines with the SAME header name repeated,
     * rather than using proper continuation lines (starting with whitespace).
     *
     * We handle both:
     * 1. Standard continuation lines (starting with whitespace)
     * 2. Spotnet-style repeated headers (same header name, values concatenated)
     *
     * @return array<string, string>
     */
    public function head(int|string $articleId): array
    {
        $id = \is_int($articleId) ? $articleId : "<$articleId>";
        $this->sendCommand("HEAD $id");
        $response = $this->readResponse();

        if (! str_starts_with($response, '221')) {
            throw new NntpException("HEAD failed: $response");
        }

        $headers = [];
        $currentHeader = '';
        $currentValue = '';

        while (true) {
            $line = $this->readLine();

            if ($line === '.' || $line === false) {
                break;
            }

            // Continuation line (starts with whitespace)
            if (preg_match('/^\s+/', $line)) {
                $currentValue .= trim($line);

                continue;
            }

            // Parse new header line
            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $headerName = strtolower(substr($line, 0, $colonPos));
                $headerValue = trim(substr($line, $colonPos + 1));

                // Check if this is the same header as before (Spotnet-style continuation)
                if ($headerName === $currentHeader) {
                    // Append to current value (no space needed - it's a split value)
                    $currentValue .= $headerValue;
                } else {
                    // Different header - save the previous one
                    if ($currentHeader !== '' && $currentHeader !== '0') {
                        $headers[$currentHeader] = $this->decodeHeader($currentValue);
                    }

                    // Start new header
                    $currentHeader = $headerName;
                    $currentValue = $headerValue;
                }
            }
        }

        // Save last header
        if ($currentHeader !== '' && $currentHeader !== '0') {
            $headers[$currentHeader] = $this->decodeHeader($currentValue);
        }

        return $headers;
    }

    /**
     * Get full article (ARTICLE)
     *
     * @return array{headers: array<string, string>, body: string}
     */
    public function article(int|string $articleId): array
    {
        $id = \is_int($articleId) ? $articleId : "<$articleId>";
        $this->sendCommand("ARTICLE $id");
        $response = $this->readResponse();

        if (! str_starts_with($response, '220')) {
            throw new NntpException("ARTICLE failed: $response");
        }

        $headers = [];
        $body = '';
        $inHeaders = true;
        $currentHeader = '';
        $currentValue = '';

        while (true) {
            $line = $this->readLine();

            if ($line === '.' || $line === false) {
                break;
            }

            // Unescape dot-stuffing
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }

            if ($inHeaders) {
                // Empty line marks end of headers
                if ($line === '') {
                    if ($currentHeader !== '' && $currentHeader !== '0') {
                        $headers[$currentHeader] = $this->decodeHeader($currentValue);
                    }
                    $inHeaders = false;

                    continue;
                }

                // Continuation line
                if (preg_match('/^\s+/', $line)) {
                    $currentValue .= ' '.trim($line);

                    continue;
                }

                // Save previous header
                if ($currentHeader !== '' && $currentHeader !== '0') {
                    $headers[$currentHeader] = $this->decodeHeader($currentValue);
                }

                // Parse new header
                $colonPos = strpos($line, ':');
                if ($colonPos !== false) {
                    $currentHeader = strtolower(substr($line, 0, $colonPos));
                    $currentValue = trim(substr($line, $colonPos + 1));
                }
            } else {
                $body .= $line."\n";
            }
        }

        return [
            'headers' => $headers,
            'body' => rtrim($body),
        ];
    }

    /**
     * Get article body only (BODY)
     * Handles binary content properly for NZB retrieval
     *
     * NNTP transmits data line-by-line with CRLF terminators.
     * For Spotnet binary data, the line breaks are added by the server for transport
     * and are NOT part of the original data. The original newlines in the binary
     * are escaped as =C (and CRs as =B) using Spotnet special encoding.
     *
     * Therefore, we strip ALL CRLF line endings and concatenate directly.
     */
    public function body(int|string $articleId): string
    {
        // Handle message ID format - add angle brackets if needed
        if (\is_string($articleId)) {
            $articleId = trim($articleId, '<>');
            $id = "<$articleId>";
        } else {
            $id = (string) $articleId;
        }

        $this->sendCommand("BODY $id");
        $response = $this->readResponse();

        if (! str_starts_with($response, '222')) {
            throw new NntpException("BODY failed for $id: $response");
        }

        // Read binary body data
        // NNTP uses CRLF line endings, and dot-stuffing for lines starting with .
        // For Spotnet data, line breaks are just transport artifacts - concatenate directly
        $body = '';

        while (true) {
            $line = fgets($this->socket, 65536);

            if ($line === false) {
                break;
            }

            // Strip trailing CRLF (transport line ending, not part of data)
            $line = rtrim($line, "\r\n");

            // Check for terminator (single dot on a line by itself)
            if ($line === '.') {
                break;
            }

            // Unescape dot-stuffing (lines starting with ..)
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }

            // Concatenate directly without adding any separator
            // The line breaks were just for NNTP transport
            $body .= $line;
        }

        return $body;
    }

    /**
     * Read text response from server
     * Handles GZIP compression if enabled
     *
     * @return array<string> Lines of text
     */
    private function getTextResponse(): array
    {
        // Check if compression is enabled and response indicates compressed data
        if ($this->compressionEnabled && $this->lastResponse &&
            stripos($this->lastResponse, 'COMPRESS=GZIP') !== false) {
            return $this->getCompressedTextResponse();
        }

        // Standard uncompressed response
        $lines = [];

        while (true) {
            $line = $this->readLine();

            if ($line === '.' || $line === false) {
                break;
            }

            // Unescape dot-stuffing
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Read GZIP compressed text response
     * Used when XFEATURE COMPRESS GZIP is enabled
     *
     * @return array<string> Lines of text
     */
    private function getCompressedTextResponse(): array
    {
        $data = '';
        $possibleEnd = false;

        while (! feof($this->socket)) {
            // If we found a possible ending (.\r\n), verify it's real
            if ($possibleEnd) {
                // Set non-blocking temporarily to check if more data
                stream_set_blocking($this->socket, false);
                $buffer = fgets($this->socket, $this->readBufferSize);
                stream_set_blocking($this->socket, true);

                // If buffer is empty after possible end, it was the real end
                if (in_array($buffer, ['', '0', false], true)) {
                    break;
                }

                // Not the end, continue reading
                $possibleEnd = false;
                $data .= $buffer;

                if (str_ends_with($buffer, ".\r\n")) {
                    $possibleEnd = true;
                }

                continue;
            }

            $buffer = fgets($this->socket, $this->readBufferSize);

            if ($buffer === false) {
                break;
            }

            $data .= $buffer;

            if (str_ends_with($buffer, ".\r\n")) {
                $possibleEnd = true;
            }
        }

        // Remove the trailing .\r\n
        if (str_ends_with($data, ".\r\n")) {
            $data = substr($data, 0, -3);
        }

        // Try to decompress
        $decompressed = @gzuncompress($data);

        if ($decompressed === false) {
            // Maybe it's not compressed, try raw
            $decompressed = $data;
        }

        // Split into lines
        return explode("\r\n", trim($decompressed));
    }

    /**
     * Send QUIT and close connection
     */
    public function quit(): void
    {
        if ($this->socket) {
            try {
                $this->sendCommand('QUIT');
                $this->readResponse();
            } catch (\Throwable) {
                // Ignore errors during quit
            }

            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->socket !== null && ! feof($this->socket);
    }

    /**
     * Send a command
     */
    private function sendCommand(string $command): void
    {
        if (! $this->socket) {
            throw new NntpException('Not connected');
        }

        $result = fwrite($this->socket, "$command\r\n");

        if ($result === false) {
            throw new NntpException('Failed to send command');
        }
    }

    /**
     * Read response line
     */
    private function readResponse(): string
    {
        $line = $this->readLine();

        if ($line === false) {
            throw new NntpException('Connection closed by server');
        }

        $this->lastResponse = $line;

        return $line;
    }

    /**
     * Read a single line
     */
    private function readLine(): string|false
    {
        if (! $this->socket) {
            return false;
        }

        $line = fgets($this->socket, $this->readBufferSize);

        if ($line === false) {
            return false;
        }

        return rtrim($line, "\r\n");
    }

    /**
     * Decode MIME encoded header
     */
    private function decodeHeader(string $header): string
    {
        // Decode RFC 2047 encoded words
        if (str_contains($header, '=?')) {
            $decoded = iconv_mime_decode($header, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $header;
    }

    public function __destruct()
    {
        $this->quit();
    }
}

/**
 * NNTP Exception
 */
class NntpException extends \Exception {}
