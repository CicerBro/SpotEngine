<?php

declare(strict_types=1);

namespace App\Enums;

enum SpotListKind: string
{
    case Blacklist = 'blacklist';
    case Whitelist = 'whitelist';
}
