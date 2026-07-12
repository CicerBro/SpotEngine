<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SpotListKind;
use App\Services\SpotwebListImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Description('Import Spotweb external blacklist and whitelist spotter IDs into spot_bans.')]
#[Signature('spot:import-lists
                            {--blacklist-url= : Override the Spotweb blacklist XML URL}
                            {--whitelist-url= : Override the Spotweb whitelist XML URL}')]
class ImportSpotLists extends Command
{
    public function handle(SpotwebListImporter $importer): int
    {
        $failed = false;

        foreach (SpotListKind::cases() as $kind) {
            $url = $this->urlOptionFor($kind);

            try {
                $result = $importer->import($kind, $url);
            } catch (Throwable $exception) {
                $this->error("Unable to import Spotweb {$kind->value}: " . $exception->getMessage());
                $failed = true;

                continue;
            }

            $this->info(sprintf(
                '%s: processed %d keys, %d new entries, %d skipped.',
                ucfirst($kind->value),
                $result['total'],
                $result['imported'],
                $result['skipped'],
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function urlOptionFor(SpotListKind $kind): ?string
    {
        $option = match ($kind) {
            SpotListKind::Blacklist => $this->option('blacklist-url'),
            SpotListKind::Whitelist => $this->option('whitelist-url'),
        };

        return \is_string($option) && $option !== '' ? $option : null;
    }
}
