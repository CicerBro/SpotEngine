<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'spot_id',
    'downloaded_at',
])]
#[WithoutTimestamps]
class UserDownload extends Model
{
    use HasFactory;
    use MassPrunable;

    public function prunable(): Builder
    {
        $days = (int) config('spotengine.downloads.retention_days', 90);

        // Hacky guard against disabled pruning in config
        if ($days < 1) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('downloaded_at', '<', now()->subDays($days));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'downloaded_at' => 'immutable_datetime',
        ];
    }
}
