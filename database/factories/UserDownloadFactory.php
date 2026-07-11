<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Spot;
use App\Models\User;
use App\Models\UserDownload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserDownload>
 */
class UserDownloadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'spot_id' => Spot::factory(),
            'downloaded_at' => now(),
        ];
    }
}
