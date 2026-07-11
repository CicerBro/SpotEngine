<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserDownloadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    /** @use HasFactory<UserDownloadFactory> */
    use HasFactory;

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
