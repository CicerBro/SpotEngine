<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $newsgroup
 * @property int $last_article_id
 * @property int $first_article_id
 * @property int $last_backfilled_article_id
 * @property CarbonImmutable|null $last_retrieval_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'newsgroup',
    'last_article_id',
    'first_article_id',
    'last_backfilled_article_id',
    'last_retrieval_at',
])]
#[WithoutIncrementing]
#[WithoutTimestamps]
class UsenetState extends Model
{
    #[\Override]
    protected $primaryKey = 'newsgroup';

    #[\Override]
    protected $keyType = 'string';

    public static function forNewsgroup(string $newsgroup): self
    {
        return static::firstOrNew(['newsgroup' => $newsgroup], [
            'last_article_id' => 0,
            'first_article_id' => 0,
            'last_backfilled_article_id' => 0,
        ]);
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'last_article_id' => 'integer',
            'first_article_id' => 'integer',
            'last_backfilled_article_id' => 'integer',
            'last_retrieval_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
