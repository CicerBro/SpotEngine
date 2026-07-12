<?php

declare(strict_types=1);

use App\Enums\SpotBanType;
use App\Enums\SpotListKind;
use App\Models\SpotBan;
use App\Services\SpotwebListImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('importFromXml stores unique poster key id bans from Spotweb keys', function () {
    $xml = <<<'XML'
<Keys>
  <Key Name="12garfield56">rKflaUf7aBQ6pFGm34N/sf1klvSsk9RmCLQClebgdFfo8lXr1l7NKuty77wo4qsn</Key>
  <Key Name="12garfield56-duplicate">rKflaUf7aBQ6pFGm34N/sf1klvSsk9RmCLQClebgdFfo8lXr1l7NKuty77wo4qsn</Key>
  <Key Name="invalid">!!!</Key>
</Keys>
XML;

    $result = app(SpotwebListImporter::class)->importFromXml(SpotListKind::Blacklist, $xml);

    $ban = SpotBan::query()->where('type', SpotBanType::PosterKeyId)->first();

    expect($result)->toBe([
        'total' => 3,
        'imported' => 1,
        'skipped' => 2,
    ])->and($ban)->not->toBeNull()
        ->and($ban->kind)->toBe(SpotListKind::Blacklist)
        ->and($ban->name)->toBe('12garfield56')
        ->and($ban->value)->toBe('ziCA');
});

test('importFromXml backfills names on existing bans', function () {
    SpotBan::query()->create([
        'kind' => SpotListKind::Blacklist,
        'type' => SpotBanType::PosterKeyId,
        'value' => 'ziCA',
    ]);

    $xml = <<<'XML'
<Keys>
  <Key Name="12garfield56">rKflaUf7aBQ6pFGm34N/sf1klvSsk9RmCLQClebgdFfo8lXr1l7NKuty77wo4qsn</Key>
</Keys>
XML;

    app(SpotwebListImporter::class)->importFromXml(SpotListKind::Blacklist, $xml);

    expect(SpotBan::query()->where('value', 'ziCA')->value('name'))->toBe('12garfield56');
});

test('importFromXml is idempotent for existing bans', function () {
    $xml = <<<'XML'
<Keys>
  <Key Name="12garfield56">rKflaUf7aBQ6pFGm34N/sf1klvSsk9RmCLQClebgdFfo8lXr1l7NKuty77wo4qsn</Key>
</Keys>
XML;

    $importer = app(SpotwebListImporter::class);

    expect($importer->importFromXml(SpotListKind::Blacklist, $xml)['imported'])->toBe(1)
        ->and($importer->importFromXml(SpotListKind::Blacklist, $xml)['imported'])->toBe(0)
        ->and(SpotBan::query()->where('type', SpotBanType::PosterKeyId)->count())->toBe(1);
});

test('importFromXml stores whitelist entries separately from blacklist', function () {
    $xml = <<<'XML'
<Keys>
  <Key Name="BenBotX">6tGs9Ts9IC712/iQc4RNVwe98vRwob+/BH/N7q5cfyHPOSUscJ4m0ZE04l6plQjN</Key>
</Keys>
XML;

    app(SpotwebListImporter::class)->importFromXml(SpotListKind::Whitelist, $xml);

    expect(SpotBan::query()->where('kind', SpotListKind::Whitelist)->count())->toBe(1)
        ->and(SpotBan::query()->where('kind', SpotListKind::Blacklist)->count())->toBe(0);
});
