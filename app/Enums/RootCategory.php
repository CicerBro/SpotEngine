<?php

declare(strict_types=1);

namespace App\Enums;

enum RootCategory: string
{
    case Image = '01';
    case Audio = '02';
    case Games = '03';
    case Applications = '04';

    public function cssColorVar(): string
    {
        return match ($this) {
            self::Image => '--color-cat-image',
            self::Audio => '--color-cat-audio',
            self::Games => '--color-cat-games',
            self::Applications => '--color-cat-applications',
        };
    }

    public function rowBackgroundClass(): string
    {
        return match ($this) {
            self::Image => 'bg-blue-500/5 hover:bg-blue-500/10 dark:bg-blue-500/[0.08] dark:hover:bg-blue-500/[0.14]',
            self::Audio => 'bg-amber-500/5 hover:bg-amber-500/10 dark:bg-amber-500/[0.08] dark:hover:bg-amber-500/[0.14]',
            self::Games => 'bg-green-500/5 hover:bg-green-500/10 dark:bg-green-500/[0.08] dark:hover:bg-green-500/[0.14]',
            self::Applications => 'bg-red-500/5 hover:bg-red-500/10 dark:bg-red-500/[0.08] dark:hover:bg-red-500/[0.14]',
        };
    }

    public function usesLightBadgeText(): bool
    {
        return false;
    }

    /**
     * Subcategory types to prefer for badge display, ordered by priority.
     *
     * @return string[]
     */
    public function preferredBadgeTypes(): array
    {
        return match ($this) {
            self::Games, self::Applications => ['platform', 'format', 'type'],
            default => ['format', 'type', 'platform'],
        };
    }
}
