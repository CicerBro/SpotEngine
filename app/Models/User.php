<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

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
 * @property CarbonImmutable|null $spots_read_until
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, UserDownload> $downloads
 */
#[Fillable([
    'username',
    'name',
    'email',
    'password',
    'is_admin',
    'api_token',
    'last_login_at',
    'spots_read_until',
])]
#[Hidden([
    'password',
    'remember_token',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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

    public function isSpotUnread(Spot $spot): bool
    {
        if ($this->spots_read_until === null) {
            return true;
        }

        return $spot->spot_posted_at->isAfter($this->spots_read_until);
    }

    public function unreadSpotCount(): int
    {
        $query = Spot::query();

        if ($this->spots_read_until !== null) {
            $query->where('spot_posted_at', '>', $this->spots_read_until);
        }

        return $query->count();
    }

    public function markAllSpotsRead(): void
    {
        $latestPostedAt = Spot::query()->max('spot_posted_at');

        $this->spots_read_until = $latestPostedAt !== null
            ? CarbonImmutable::parse($latestPostedAt)
            : now();

        $this->save();
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

    #[\Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'spots_read_until' => 'immutable_datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    protected function username(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Str::lower($value),
        );
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Str::lower($value),
        );
    }
}
