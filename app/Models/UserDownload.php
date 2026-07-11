<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'user_id',
    'spot_id',
    'downloaded_at',
])]
#[\Illuminate\Database\Eloquent\Attributes\WithoutTimestamps]
class UserDownload extends Model
{
    /** @use HasFactory<\Database\Factories\UserDownloadFactory> */
    use HasFactory;

    #[\Override]
    protected function casts(): array
    {
        return [
            'downloaded_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }
}
