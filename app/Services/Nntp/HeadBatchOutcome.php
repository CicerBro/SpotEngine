<?php

declare(strict_types=1);

namespace App\Services\Nntp;

enum HeadBatchOutcome
{
    case Success;
    case Missing;
    case TimedOutAfterRetry;
}
