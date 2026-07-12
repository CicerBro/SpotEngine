<?php

declare(strict_types=1);

use App\Services\Nntp\SpotterId;

test('fromModulus matches Spotweb spotter id calculation', function () {
    $spotterId = SpotterId::fromModulus('rKflaUf7aBQ6pFGm34N/sf1klvSsk9RmCLQClebgdFfo8lXr1l7NKuty77wo4qsn');

    expect($spotterId)->toBe('ziCA');
});

test('fromModulus returns null for invalid moduli', function () {
    expect(SpotterId::fromModulus(''))->toBeNull()
        ->and(SpotterId::fromModulus('!!!'))->toBeNull();
});
