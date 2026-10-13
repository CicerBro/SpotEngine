<?php

declare(strict_types=1);

namespace App\Services\Nntp;

/** Single NNTP connection with a bounded queue of outstanding HEAD requests. */
class PipelinedNntpDriver extends SingleNntpDriver
{
    private const int MAX_PENDING_COMMAND_BYTES = 2048;

    /** Reserve space for TLS record framing when bounding queued writes. */
    private const int COMMAND_FRAMING_ALLOWANCE_BYTES = 64;

    public function __construct(NntpEndpoint $endpoint, private readonly int $pipelineDepth = 10)
    {
        if ($pipelineDepth < 1) {
            throw new \InvalidArgumentException('NNTP pipeline depth must be at least one');
        }

        parent::__construct(
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

    /** @param array<string, mixed> $config */
    #[\Override]
    public static function fromConfig(array $config): self
    {
        return self::fromEndpoint(NntpEndpoint::fromArray($config), (int) ($config['pipeline_depth'] ?? 10));
    }

    #[\Override]
    public static function fromEndpoint(NntpEndpoint $endpoint, int $pipelineDepth = 10): self
    {
        return new self($endpoint, $pipelineDepth);
    }

    /**
     * Replies are consumed in request order. Missing articles preserve alignment;
     * transport, protocol, or callback failures close the connection and propagate.
     *
     * @param  array<int|string>  $articles
     * @param  callable(int|string, HeadBatchResult): void|null  $onArticle
     * @return array<int|string, array<string, string>|null>
     */
    #[\Override]
    public function headBatch(array $articles, bool $showProgress = true, ?callable $onArticle = null): array
    {
        $articles = array_values($articles);
        $total = \count($articles);

        if ($total === 0) {
            return [];
        }

        /** @var \SplQueue<array{id: int|string, bytes: int}> $pending */
        $pending = new \SplQueue;
        $pendingBytes = 0;
        $nextArticle = 0;
        $done = 0;
        $results = [];

        $this->reportHeadProgress($done, $total, $showProgress);

        try {
            while ($nextArticle < $total || ! $pending->isEmpty()) {
                while ($nextArticle < $total && $pending->count() < $this->pipelineDepth) {
                    $articleId = $articles[$nextArticle];
                    $wireId = \is_int($articleId) ? (string) $articleId : "<{$articleId}>";
                    $command = "HEAD {$wireId}";
                    $bytes = strlen($command) + 2 + self::COMMAND_FRAMING_ALLOWANCE_BYTES;

                    if (! $pending->isEmpty() && $pendingBytes + $bytes > self::MAX_PENDING_COMMAND_BYTES) {
                        break;
                    }

                    $this->sendCommand($command);
                    $pending->enqueue(['id' => $articleId, 'bytes' => $bytes]);
                    $pendingBytes += $bytes;
                    $nextArticle++;
                }

                $request = $pending->dequeue();
                $pendingBytes -= $request['bytes'];
                $result = $this->readHeadResult($request['id']);

                if ($onArticle !== null) {
                    $onArticle($request['id'], $result);
                } else {
                    $results[$request['id']] = $result->headers;
                }

                $done++;
                $this->reportHeadProgress($done, $total, $showProgress);
            }
        } catch (\Throwable $exception) {
            $this->detach();

            throw $exception;
        } finally {
            if ($showProgress) {
                echo "\n";
                flush();
            }
        }

        return $results;
    }

    private function readHeadResult(int|string $articleId): HeadBatchResult
    {
        $response = $this->readResponse();

        if ($response->is(ResponseCode::NoSuchArticleId)
            || (\is_int($articleId) && $response->is(ResponseCode::NoSuchArticleNumber))) {
            return HeadBatchResult::missing();
        }

        if (! $response->is(ResponseCode::HeadFollows)) {
            throw NntpException::unexpected('HEAD', $response);
        }

        $headers = (new SpotnetHeaderParser)->parse($this->getTextResponse(
            max(1, (int) config('spotengine.payload_limits.nntp_header_bytes', 2_097_152)),
        ));

        if (\is_string($articleId) && isset($headers['message-id'])
            && trim($headers['message-id'], '<>') !== $articleId) {
            throw new NntpException('Pipelined HEAD response message ID does not match request', operation: 'HEAD');
        }

        return HeadBatchResult::success($headers);
    }
}
