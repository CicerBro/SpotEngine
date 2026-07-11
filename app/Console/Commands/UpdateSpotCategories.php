<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\SpotwebCategories;
use App\Models\Category;
use Illuminate\Console\Command;

#[\Illuminate\Console\Attributes\Description('Update spot categories from Spotweb SpotCategories definitions')]
#[\Illuminate\Console\Attributes\Signature('spot:categories:update')]
class UpdateSpotCategories extends Command
{
    public function handle(): int
    {
        $rows = SpotwebCategories::toCategoryRows();

        $this->info('Updating '.\count($rows).' categories from Spotweb definitions.');

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
