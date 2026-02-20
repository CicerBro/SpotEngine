<?php

declare(strict_types=1);

if (! function_exists('log_debug')) {
    /**
     * Log a message only when APP_DEBUG is true. Use for verbose/debug logs that clutter production.
     *
     * @param  array<string, mixed>  $context
     */
    function log_debug(string $message, array $context = []): void
    {
        if (! config('app.debug')) {
            return;
        }

        \Illuminate\Support\Facades\Log::info($message, $context);
    }
}

if (! function_exists('nestedCachePath')) {
    /**
     * Build a nested cache path using the first two hex characters of the hash.
     *
     * Pattern: {basePath}/{first_char}/{second_char}/{full_hash}.{ext}
     * This creates 16 L1 dirs x 16 L2 dirs = 256 leaf directories.
     */
    function nestedCachePath(string $basePath, string $hash, string $extension): string
    {
        return $basePath
            .DIRECTORY_SEPARATOR.$hash[0]
            .DIRECTORY_SEPARATOR.$hash[1]
            .DIRECTORY_SEPARATOR.$hash.'.'.$extension;
    }
}

if (! function_exists('formatBytes')) {
    function formatBytes(int|float|string $size, int $decimals = 2, bool $roundUp = false): string
    {
        $size = (int) $size;

        static $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $maxPower = count($units) - 1;

        if ($size <= 0) {
            return number_format(0, $decimals, '.', ',').' '.$units[0];
        }

        $power = 0;
        $value = (float) $size;

        while ($value >= 1024 && $power < $maxPower) {
            $value /= 1024;
            $power++;
        }

        if ($roundUp && $value >= 1000 && $power < $maxPower) {
            $value /= 1024;
            $power++;
        }

        return number_format($value, $decimals, '.', ',').' '.$units[$power];
    }
}
