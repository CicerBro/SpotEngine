<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $newsgroup
 * @property int $last_article_id
 * @property int $first_article_id
 * @property int $last_backfilled_article_id
 * @property CarbonImmutable|null $last_retrieval_at
 * @property CarbonImmutable|null $updated_at
 */
class UsenetState extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'newsgroup';

    protected $keyType = 'string';

    protected $fillable = [
        'newsgroup',
        'last_article_id',
        'first_article_id',
        'last_backfilled_article_id',
        'last_retrieval_at',
    ];

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

    public static function forNewsgroup(string $newsgroup): self
    {
        return static::firstOrNew(['newsgroup' => $newsgroup], [
            'last_article_id' => 0,
            'first_article_id' => 0,
            'last_backfilled_article_id' => 0,
        ]);
    }
}
