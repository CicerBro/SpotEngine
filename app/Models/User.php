<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $username
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property bool $is_admin
 * @property string $api_token
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, UserDownload> $downloads
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'username',
    'name',
    'email',
    'password',
    'is_admin',
    'api_token',
    'last_login_at',
])]
#[\Illuminate\Database\Eloquent\Attributes\Hidden([
    'password',
    'remember_token',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    #[\Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->api_token)) {
                $user->api_token = self::generateApiKey();
            }
        });
    }

    public static function generateApiKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function regenerateApiKey(): string
    {
        $this->api_token = self::generateApiKey();
        $this->save();

        return $this->api_token;
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(UserDownload::class);
    }
}
