<?php

declare(strict_types=1);

namespace App\Services\Nntp;

use App\Services\Nntp\Contracts\NntpDriverInterface;

/**
 * Single-connection NNTP driver for serial operations (NZB body and article retrieval).
 *
 * Implements NntpDriverInterface for use as a drop-in driver with one connection.
 * headParallel() runs serially (one HEAD at a time). For high-throughput parallel
 * HEAD fetches across many connections, see ParallelNntpDriver.
 */
class SingleNntpDriver implements NntpDriverInterface
{
    private mixed $socket = null;

    private bool $compressionSupported = true;

    private bool $compressionEnabled = false;

    private ?string $lastResponse = null;

    private int $readBufferSize = 65536;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 563,
        private readonly bool $ssl = true,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly int $timeout = 60,
    ) {}

    /** @param array<string, mixed> $config */
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

    public function connect(bool $showProgress = true): void
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

        stream_set_timeout($this->socket, $this->timeout);
        stream_set_read_buffer($this->socket, $this->readBufferSize);

        $response = $this->readResponse();

        if (! str_starts_with($response, '200') && ! str_starts_with($response, '201')) {
            throw new NntpException("Unexpected greeting: $response");
        }

        if ($this->username !== '' && $this->username !== '0') {
            $this->authenticate();
        }

        $this->enableCompression();
    }

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

    public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }

    /** @return array{count: int, first: int, last: int, group: string} */
    public function group(string $groupName): array
    {
        $this->sendCommand("GROUP $groupName");
        $response = $this->readResponse();

        if (! str_starts_with($response, '211')) {
            throw new NntpException("Failed to select group $groupName: $response");
        }

        $parts = explode(' ', $response);

        return [
            'count' => (int) ($parts[1] ?? 0),
            'first' => (int) ($parts[2] ?? 0),
            'last' => (int) ($parts[3] ?? 0),
            'group' => $parts[4] ?? $groupName,
        ];
    }

    /** @return array<int, array{subject: string, from: string, date: string, message_id: string}> */
    public function xover(int $start, int $end): array
    {
        $this->sendCommand("XOVER $start-$end");
        $response = $this->readResponse();

        if (! str_starts_with($response, '224')) {
            throw new NntpException("XOVER failed: $response");
        }

        $lines = $this->getTextResponse();
        $articles = [];

        foreach ($lines as $line) {
            if ($line === '' || $line === '.') {
                continue;
            }

            // XOVER format: num\tsubject\tfrom\tdate\tmessage-id\treferences\tbytes\tlines
            $parts = explode("\t", $line);

            if (\count($parts) >= 5) {
                $articles[(int) $parts[0]] = [
                    'subject' => $parts[1],
                    'from' => $parts[2],
                    'date' => $parts[3],
                    'message_id' => trim($parts[4], '<>'),
                ];
            }
        }

        return $articles;
    }

    /**
     * @return array<int, string> Article number => header value
     */
    public function xhdr(string $header, int $start, int $end): array
    {
        $commands = ["XHDR $header $start-$end", "HDR $header $start-$end"];
        $response = null;
        $success = false;

        foreach ($commands as $cmd) {
            $this->sendCommand($cmd);
            $response = $this->readResponse();

            if (str_starts_with($response, '221') || str_starts_with($response, '225')) {
                $success = true;
                break;
            }

            if (str_starts_with($response, '500') || str_starts_with($response, '501') || str_starts_with($response, '400')) {
                continue;
            }

            throw new NntpException("Header fetch failed: $response");
        }

        if (! $success) {
            throw new NntpException("Neither XHDR nor HDR supported: $response");
        }

        $lines = $this->getTextResponse();
        $results = [];

        foreach ($lines as $line) {
            if ($line === '' || $line === '.') {
                continue;
            }

            $spacePos = strpos($line, ' ');

            if ($spacePos !== false) {
                $articleNum = (int) substr($line, 0, $spacePos);
                $value = substr($line, $spacePos + 1);

                if ($value !== '(none)' && $value !== '') {
                    $results[$articleNum] = $this->decodeHeader($value);
                }
            }
        }

        return $results;
    }

    /**
     * Fetch headers for a list of article numbers serially.
     * Articles that return 430 (No Such Article) are passed as null to $onArticle
     * or stored as null in the returned array.
     *
     * @param  array<int|string>  $articles
     * @param  callable(?array<string,string>): void|null  $onArticle
     * @return array<int|string, array<string, string>|null>
     */
    public function headParallel(array $articles, bool $showProgress = true, ?callable $onArticle = null): array
    {
        $results = [];

        foreach ($articles as $id) {
            try {
                $headers = $this->head($id);
            } catch (NntpException) {
                $headers = null;
            }

            if ($onArticle !== null) {
                $onArticle($headers);
            } else {
                $results[$id] = $headers;
            }
        }

        return $results;
    }

    /**
     * Get article headers (HEAD).
     *
     * Spotnet uses a non-standard approach where long headers like X-XML
     * are split across multiple lines with the SAME header name repeated,
     * rather than using proper continuation lines (starting with whitespace).
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

            if (preg_match('/^\s+/', $line)) {
                $currentValue .= trim($line);

                continue;
            }

            $colonPos = strpos($line, ':');

            if ($colonPos !== false) {
                $headerName = strtolower(substr($line, 0, $colonPos));
                $headerValue = trim(substr($line, $colonPos + 1));

                if ($headerName === $currentHeader) {
                    $currentValue .= $headerValue;
                } else {
                    if ($currentHeader !== '' && $currentHeader !== '0') {
                        $headers[$currentHeader] = $this->decodeHeader($currentValue);
                    }

                    $currentHeader = $headerName;
                    $currentValue = $headerValue;
                }
            }
        }

        if ($currentHeader !== '' && $currentHeader !== '0') {
            $headers[$currentHeader] = $this->decodeHeader($currentValue);
        }

        return $headers;
    }

    /**
     * Get full article (ARTICLE).
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

            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }

            if ($inHeaders) {
                if ($line === '') {
                    if ($currentHeader !== '' && $currentHeader !== '0') {
                        $headers[$currentHeader] = $this->decodeHeader($currentValue);
                    }
                    $inHeaders = false;

                    continue;
                }

                if (preg_match('/^\s+/', $line)) {
                    $currentValue .= ' '.trim($line);

                    continue;
                }

                if ($currentHeader !== '' && $currentHeader !== '0') {
                    $headers[$currentHeader] = $this->decodeHeader($currentValue);
                }

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
     * Get article body only (BODY).
     *
     * NNTP transmits data line-by-line with CRLF terminators.
     * For Spotnet binary data, the line breaks are added by the server for transport
     * and are NOT part of the original data. The original newlines in the binary
     * are escaped as =C (and CRs as =B) using Spotnet special encoding.
     */
    public function body(int|string $articleId): string
    {
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

        $body = '';

        while (true) {
            $line = fgets($this->socket, 65536);

            if ($line === false) {
                break;
            }

            $line = rtrim($line, "\r\n");

            if ($line === '.') {
                break;
            }

            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }

            $body .= $line;
        }

        return $body;
    }

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
     * Close the socket without sending QUIT.
     * Call this in a forked child process to prevent the child's destructors
     * from terminating the parent's open connection.
     */
    public function detach(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function getConnectionCount(): int
    {
        return 1;
    }

    public function isConnected(): bool
    {
        return $this->socket !== null && ! feof($this->socket);
    }

    private function getTextResponse(): array
    {
        if ($this->compressionEnabled && $this->lastResponse &&
            stripos($this->lastResponse, 'COMPRESS=GZIP') !== false) {
            return $this->getCompressedTextResponse();
        }

        $lines = [];

        while (true) {
            $line = $this->readLine();

            if ($line === '.' || $line === false) {
                break;
            }

            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /** @return array<string> */
    private function getCompressedTextResponse(): array
    {
        $data = '';
        $possibleEnd = false;

        while (! feof($this->socket)) {
            if ($possibleEnd) {
                stream_set_blocking($this->socket, false);
                $buffer = fgets($this->socket, $this->readBufferSize);
                stream_set_blocking($this->socket, true);

                if (in_array($buffer, ['', '0', false], true)) {
                    break;
                }

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

        if (str_ends_with($data, ".\r\n")) {
            $data = substr($data, 0, -3);
        }

        $decompressed = @gzuncompress($data);

        if ($decompressed === false) {
            $decompressed = $data;
        }

        return explode("\r\n", trim($decompressed));
    }

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

    private function readResponse(): string
    {
        $line = $this->readLine();

        if ($line === false) {
            throw new NntpException('Connection closed by server');
        }

        $this->lastResponse = $line;

        return $line;
    }

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

    private function decodeHeader(string $header): string
    {
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
