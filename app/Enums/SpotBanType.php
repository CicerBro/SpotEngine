<?php

declare(strict_types=1);

namespace App\Enums;

enum SpotBanType: string
{
    case Poster = 'poster';
    case Tag = 'tag';
    case PosterKeyId = 'poster_key_id';
}
