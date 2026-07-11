<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\SpotwebCategories;
use App\Models\Category;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Description('Update spot categories from Spotweb SpotCategories definitions')]
#[Signature('spot:categories:update {--source-url= : Override the Spotweb categories PHP URL}')]
class UpdateSpotCategories extends Command
{
    public function handle(): int
    {
        $sourceUrl = $this->option('source-url');

        try {
            $rows = SpotwebCategories::fetchCategoryRows(
                \is_string($sourceUrl) && $sourceUrl !== '' ? $sourceUrl : null
            );
        } catch (Throwable $exception) {
            $this->error('Unable to update categories from Spotweb: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Updating ' . \count($rows) . ' categories from Spotweb definitions.');

        $bar = $this->output->createProgressBar(\count($rows));
        $bar->start();

        foreach ($rows as $row) {
            Category::updateOrCreate(
                ['code' => $row['code']],
                [
                    'parent_code' => $row['parent_code'],
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'type' => $row['type'],
                    'sort_order' => $row['sort_order'],
                ]
            );
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        Category::clearCache();

        $this->info('Categories updated. Cache cleared.');

        return self::SUCCESS;
    }
}
