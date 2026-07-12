<?php

declare(strict_types=1);

namespace App\Services\Nntp;

use App\Services\Nntp\Contracts\NntpDriverInterface;

/**
 * Single-connection NNTP driver for serial operations (NZB body and article retrieval).
 *
 * Implements NntpDriverInterface for use as a drop-in driver with one connection.
 * headBatch() runs serially (one HEAD at a time). For high-throughput parallel
 * HEAD fetches across many connections, see ParallelNntpDriver.
 */
class SingleNntpDriver implements NntpDriverInterface
{
    private mixed $socket = null;

    private bool $compressionSupported = true;

    private bool $compressionEnabled = false;

    private ?NntpResponse $lastResponse = null;

    private int $readBufferSize = 65536;

    private ?NntpProtocol $protocol = null;

    private readonly NntpEndpoint $endpoint;

    public function __construct(
        string $host,
        int $port = 563,
        bool $ssl = true,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly int $timeout = 60,
        bool $verifyPeer = true,
        bool $allowSelfSigned = false,
    ) {
        $this->endpoint = new NntpEndpoint(
            host: $host,
            port: $port,
            ssl: $ssl,
            username: $username,
            password: $password,
            timeout: $timeout,
            verifyPeer: $verifyPeer,
            allowSelfSigned: $allowSelfSigned,
        );
    }

    public function __destruct()
    {
        $this->quit();
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        return self::fromEndpoint(NntpEndpoint::fromArray($config));
    }

    public static function fromEndpoint(NntpEndpoint $endpoint): self
    {
        return new self(
            $endpoint->host,
            $endpoint->port,
            $endpoint->ssl,
            $endpoint->username,
            $endpoint->password,
            $endpoint->timeout,
            $endpoint->verifyPeer,
            $endpoint->allowSelfSigned,
        );
    }

    public function connect(bool $showProgress = true): void
    {
        $address = $this->endpoint->address();
        $context = stream_context_create($this->endpoint->streamContextOptions());

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
        $this->protocol = NntpProtocol::forResource($this->socket, $this->readBufferSize);

        $response = $this->readResponse();

        if (! $response->is(ResponseCode::ReadyPostingAllowed, ResponseCode::ReadyPostingProhibited)) {
            throw NntpException::unexpected('connect', $response);
        }

        if ($this->username !== '' && $this->username !== '0') {
            $this->authenticate();
        }

        $this->enableCompression();
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

            if ($response->is(ResponseCode::CompressionEnabled)) {
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

        if (! $response->is(ResponseCode::GroupSelected)) {
            throw NntpException::unexpected("GROUP {$groupName}", $response);
        }

        $parts = explode(' ', $response->statusText);

        return [
            'count' => (int) $parts[0],
            'first' => (int) ($parts[1] ?? 0),
            'last' => (int) ($parts[2] ?? 0),
            'group' => $parts[3] ?? $groupName,
        ];
    }

    /** @return array<int, array{subject: string, from: string, date: string, message_id: string}> */
    public function xover(int $start, int $end): array
    {
        $this->sendCommand("XOVER $start-$end");
        $response = $this->readResponse();

        if (! $response->is(ResponseCode::OverviewFollows)) {
            throw NntpException::unexpected('XOVER', $response);
        }

        $lines = $this->getTextResponse();
        $articles = [];

        foreach ($lines as $line) {
            if ($line === '' || $line === '.') {
                continue;
            }

            // XOVER format: num\tsubject\tfrom\tdate\tmessage-id\treferences\tbytes\tlines
            $parts = explode("\t", (string) $line);

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

            if ($response->is(ResponseCode::HeadFollows, ResponseCode::HeaderFollows)) {
                $success = true;
                break;
            }

            if ($response->is(ResponseCode::UnknownCommand, ResponseCode::SyntaxError, ResponseCode::ServiceDiscontinued)) {
                continue;
            }

            throw NntpException::unexpected($cmd, $response);
        }

        if (! $success) {
            throw new NntpException(
                'Neither XHDR nor HDR is supported',
                $response->codeValue(),
                $response->statusText,
                'XHDR',
            );
        }

        $lines = $this->getTextResponse();
        $results = [];

        foreach ($lines as $line) {
            if ($line === '' || $line === '.') {
                continue;
            }

            $spacePos = strpos((string) $line, ' ');

            if ($spacePos !== false) {
                $articleNum = (int) substr((string) $line, 0, $spacePos);
                $value = substr((string) $line, $spacePos + 1);

                if ($value !== '(none)' && $value !== '') {
                    $results[$articleNum] = $this->decodeHeader($value);
                }
            }
        }

        return $results;
    }

    /**
     * Fetch HEAD for many articles on this single connection.
     *
     * Each article is fetched serially via {@see head()}, one command at a time.
     * For parallel HEAD throughput across multiple connections, use
     * {@see ParallelNntpDriver::headBatch()}.
     *
     * Streaming callbacks receive a typed outcome. The legacy return value
     * continues to use null for every unsuccessful HEAD response.
     *
     * @param  array<int|string>  $articles
     * @param  callable(int|string, HeadBatchResult): void|null  $onArticle
     * @return array<int|string, array<string, string>|null>
     */
    public function headBatch(array $articles, bool $showProgress = true, ?callable $onArticle = null): array
    {
        $results = [];

        foreach ($articles as $id) {
            try {
                $headers = $this->head($id);
            } catch (NntpException $exception) {
                if ($onArticle !== null) {
                    if ($exception->responseCode !== ResponseCode::NoSuchArticleId->value) {
                        throw $exception;
                    }

                    $onArticle($id, HeadBatchResult::missing());

                    continue;
                }

                $headers = null;
            }

            if ($onArticle !== null) {
                $onArticle($id, HeadBatchResult::success($headers));
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

        if (! $response->is(ResponseCode::HeadFollows)) {
            throw NntpException::unexpected('HEAD', $response);
        }

        return (new SpotnetHeaderParser)->parse($this->getTextResponse());
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

        if (! $response->is(ResponseCode::ArticleFollows)) {
            throw NntpException::unexpected('ARTICLE', $response);
        }

        $lines = $this->getTextResponse();
        $separator = array_search('', $lines, true);
        $headerLines = $separator === false ? $lines : array_slice($lines, 0, $separator);
        $bodyLines = $separator === false ? [] : array_slice($lines, $separator + 1);

        return [
            'headers' => (new SpotnetHeaderParser)->parse($headerLines),
            'body' => implode("\n", $bodyLines),
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

        if (! $response->is(ResponseCode::BodyFollows)) {
            throw NntpException::unexpected("BODY {$id}", $response);
        }

        return $this->protocol()->readBodyResponse();
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
            $this->protocol = null;
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
            $this->protocol = null;
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

    private function authenticate(): void
    {
        $this->sendCommand("AUTHINFO USER {$this->username}");
        $response = $this->readResponse();

        if ($response->is(ResponseCode::AuthenticationContinue)) {
            $this->sendCommand("AUTHINFO PASS {$this->password}");
            $response = $this->readResponse();
        }

        if (! $response->is(ResponseCode::AuthenticationAccepted)) {
            throw NntpException::unexpected('AUTHINFO', $response);
        }
    }

    private function getTextResponse(): array
    {
        if ($this->compressionEnabled && $this->lastResponse instanceof NntpResponse &&
            stripos($this->lastResponse->statusText, 'COMPRESS=GZIP') !== false) {
            return $this->getCompressedTextResponse();
        }

        return $this->protocol()->readTextResponse();
    }

    /** @return array<string> */
    private function getCompressedTextResponse(): array
    {
        return $this->protocol()->readCompressedTextResponse();
    }

    private function sendCommand(string $command): void
    {
        $this->protocol()->writeCommand($command);
    }

    private function readResponse(): NntpResponse
    {
        return $this->lastResponse = $this->protocol()->readResponse();
    }

    private function protocol(): NntpProtocol
    {
        if (! $this->socket) {
            throw new NntpException('Not connected');
        }

        return $this->protocol ??= NntpProtocol::forResource($this->socket, $this->readBufferSize);
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
}
