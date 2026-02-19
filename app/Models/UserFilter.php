<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFilter extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'name',
        'filter_data',
        'is_default',
        'sort_order',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'filter_data' => 'array',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
