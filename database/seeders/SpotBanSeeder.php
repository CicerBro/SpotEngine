<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SpotBanType;
use App\Enums\SpotListKind;
use App\Models\SpotBan;
use Illuminate\Database\Seeder;

class SpotBanSeeder extends Seeder
{
    public function run(): void
    {
        SpotBan::query()->firstOrCreate(
            [
                'kind' => SpotListKind::Blacklist,
                'type' => SpotBanType::Tag,
                'value' => SpotBan::normalizeValue(SpotBanType::Tag, 'timskuik'),
            ],
            [
                'name' => 'timskuik',
            ],
        );
    }
}
