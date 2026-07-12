<?php

declare(strict_types=1);

use App\Enums\SpotBanType;
use App\Enums\SpotListKind;
use App\Models\SpotBan;
use App\Services\SpotBanService;
use Database\Seeders\SpotBanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    SpotBanService::clearCache();
});

test('isBanned matches banned tags case-insensitively', function () {
    SpotBan::query()->create([
        'kind' => SpotListKind::Blacklist,
        'type' => SpotBanType::Tag,
        'value' => 'timskuik',
    ]);

    $service = app(SpotBanService::class);

    expect($service->isBanned('Poster', 'timskuik'))->toBeTrue()
        ->and($service->isBanned('Poster', 'TimSkuik'))->toBeTrue()
        ->and($service->isBanned('Poster', 'HDTV'))->toBeFalse();
});

test('isBanned matches banned posters case-insensitively', function () {
    SpotBan::query()->create([
        'kind' => SpotListKind::Blacklist,
        'type' => SpotBanType::Poster,
        'value' => 'tester',
    ]);

    $service = app(SpotBanService::class);

    expect($service->isBanned('tester', null))->toBeTrue()
        ->and($service->isBanned('Tester', 'anything'))->toBeTrue()
        ->and($service->isBanned('Other Poster', null))->toBeFalse();
});

test('isBanned matches banned poster key ids', function () {
    SpotBan::query()->create([
        'kind' => SpotListKind::Blacklist,
        'type' => SpotBanType::PosterKeyId,
        'value' => 'ziCA',
    ]);

    $service = app(SpotBanService::class);

    expect($service->isBanned(null, null, 'ziCA'))->toBeTrue()
        ->and($service->isBanned('Poster', 'tag', 'other'))->toBeFalse();
});

test('whitelisted poster key ids bypass blacklist matches', function () {
    SpotBan::query()->create([
        'kind' => SpotListKind::Blacklist,
        'type' => SpotBanType::PosterKeyId,
        'value' => 'ziCA',
    ]);

    SpotBan::query()->create([
        'kind' => SpotListKind::Whitelist,
        'type' => SpotBanType::PosterKeyId,
        'value' => 'ziCA',
    ]);

    $service = app(SpotBanService::class);

    expect($service->isBanned(null, null, 'ziCA'))->toBeFalse();
});

test('isBanned ignores empty poster and tag values', function () {
    SpotBan::query()->create([
        'kind' => SpotListKind::Blacklist,
        'type' => SpotBanType::Tag,
        'value' => 'timskuik',
    ]);

    $service = app(SpotBanService::class);

    expect($service->isBanned(null, null))->toBeFalse()
        ->and($service->isBanned('', ''))->toBeFalse();
});

test('spot ban cache is cleared when bans change', function () {
    SpotBan::query()->create([
        'kind' => SpotListKind::Blacklist,
        'type' => SpotBanType::Tag,
        'value' => 'timskuik',
    ]);

    $service = app(SpotBanService::class);
    expect($service->isBanned(null, 'timskuik'))->toBeTrue();

    SpotBan::query()->where('value', 'timskuik')->delete();

    expect(Cache::has('spot_bans.tag'))->toBeFalse()
        ->and($service->isBanned(null, 'timskuik'))->toBeFalse();
});

test('spot ban seeder seeds timskuik tag ban', function () {
    $this->seed(SpotBanSeeder::class);

    expect(SpotBan::query()->where('type', SpotBanType::Tag)->where('value', 'timskuik')->exists())->toBeTrue();
});
