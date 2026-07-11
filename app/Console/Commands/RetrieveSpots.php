<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OverlappedSpotRetrieverService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

#[\Illuminate\Console\Attributes\Description('Fetch new spots from Usenet and store them in the database')]
#[\Illuminate\Console\Attributes\Signature('spot:retrieve
                            {--initial-scan : XOVER only — fast bulk index; run spot:enrich afterwards to populate X-XML}
                            {--backfill : Fetch older spots below current position (run repeatedly until complete)}
                            {--reset-backfill : Reset backfill progress and start over}
                            {--limit= : Maximum number of articles per run}
                            {--connections= : Parallel NNTP connections — only used with --initial-scan (default from config)}')]
class RetrieveSpots extends Command implements Isolatable
{
    #[\Override]
    protected $isolated = true;

    public function handle(OverlappedSpotRetrieverService $service): int
    {
        ini_set('memory_limit', config('spotengine.retrieval.memory_limit', '512M'));

        $exitCode = $this->validateAndConfirmSettings();
        if ($exitCode !== null) {
            return $exitCode;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, $service->shutdown(...));
            pcntl_signal(SIGTERM, $service->shutdown(...));
        }

        $limit = $this->option('limit') !== null && $this->option('limit') !== '' ? (int) $this->option('limit') : null;
        $connections = $this->option('connections') !== null && $this->option('connections') !== ''
            ? (int) $this->option('connections')
            : (int) config('spotengine.nntp.connections');

        try {
            $result = $service->retrieve(
                backfill: (bool) $this->option('backfill'),
                resetBackfill: (bool) $this->option('reset-backfill'),
                initialScan: (bool) $this->option('initial-scan'),
                limit: $limit,
                connections: $connections,
                onBatchComplete: function (int $batchStart, int $batchEnd, int $processed, int $parsed, int $inserted): void {
                    $this->line("Batch {$batchStart}-{$batchEnd}: {$processed} processed, {$parsed} parsed, {$inserted} inserted");
                },
            );

            if ($result['processed'] === 0) {
                if ($this->option('backfill') && $result['last_article'] === 0) {
                    $this->warn('Nothing to backfill. Run a forward retrieval first.');
                } elseif ($this->option('backfill')) {
                    $this->info('Backfill is already complete.');
                } else {
                    $this->info('Already up to date.');
                }

                return self::SUCCESS;
            }

            $this->info('Retrieval complete.');
            $this->table(['Metric', 'Value'], [
                ['Processed', $result['processed']],
                ['Inserted', $result['inserted']],
                ['Last article', $result['last_article']],
            ]);

            if ($this->option('initial-scan')) {
                $this->newLine();
                $this->components->info('Initial scan complete — spots are browsable now with basic metadata (title, category, size, poster).');
                $this->newLine();
                $this->line('  Run the enrich command to fetch full X-XML data for all indexed spots:');
                $this->newLine();
                $this->line('    <fg=green>php artisan spot:enrich</>');
                $this->newLine();
                $this->line('  This fetches descriptions, image references, NZB segments and verifies');
                $this->line('  signatures. It can run in the background — spots opened by users are');
                $this->line('  enriched lazily in real-time regardless.');
                $this->newLine();
            }
        } catch (\Throwable $e) {
            // Silence error when Control+C'ing out of the command
            if ($e->getMessage() === 'Call to a member function quit() on null') {
                return self::SUCCESS;
            }
            $this->error("Retrieval failed: {$e->getMessage()}");
            $service->shutdown();

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Validate NNTP config and confirm full retrieval when applicable. Returns null to continue, or exit code to return.
     */
    private function validateAndConfirmSettings(): ?int
    {
        $nntpConfig = config('spotengine.nntp');
        if (empty($nntpConfig['host']) || ! \is_string($nntpConfig['host']) || trim($nntpConfig['host']) === '') {
            $this->error('NNTP is not configured. Set NNTP_HOST in your .env (and NNTP_USERNAME/NNTP_PASSWORD if your server requires auth).');

            return self::FAILURE;
        }
        if (empty($nntpConfig['groups']['spots']) || trim((string) $nntpConfig['groups']['spots']) === '') {
            $this->error('NNTP spots group is not configured. Set NNTP_GROUP_SPOTS in your .env.');

            return self::FAILURE;
        }
        $hasUser = ! in_array(trim((string) ($nntpConfig['username'] ?? '')), ['', '0'], true);
        $hasPass = ! in_array(trim((string) ($nntpConfig['password'] ?? '')), ['', '0'], true);
        if ($hasUser !== $hasPass) {
            $this->error('NNTP credentials are incomplete. Set both NNTP_USERNAME and NNTP_PASSWORD in your .env, or leave both empty for anonymous access.');

            return self::FAILURE;
        }

        return null;
    }
}
