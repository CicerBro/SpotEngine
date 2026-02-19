<?php

declare(strict_types=1);

namespace App\Enums;

enum SearchField: string
{
    case Title = 'title';
    case Description = 'description';
    case Both = 'both';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Title;
    }

    public function label(): string
    {
        return match ($this) {
            self::Title => 'Title',
            self::Description => 'Description',
            self::Both => 'Title + Description',
        };
    }
}
