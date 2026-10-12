<?php

declare(strict_types=1);

use App\Enums\SpotBanType;
use App\Enums\SpotListKind;
use App\Models\SpotBan;
use App\Services\SpotBanService;
use Database\Seeders\SpotBanSeeder;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

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

    SpotBan::query()->where('value', 'timskuik')->firstOrFail()->delete();

    expect(Cache::has('spot_bans.sets.blacklist.tag'))->toBeFalse()
        ->and($service->isBanned(null, 'timskuik'))->toBeFalse();
});

test('ban changes invalidate memoized matches in the same request', function () {
    $service = app(SpotBanService::class);
    expect($service->isBanned(null, 'blocked'))->toBeFalse();

    $ban = SpotBan::query()->create(['type' => SpotBanType::Tag, 'value' => 'blocked']);
    expect($service->isBanned(null, 'blocked'))->toBeTrue();

    $ban->update(['value' => 'other']);
    expect($service->isBanned(null, 'blocked'))->toBeFalse()
        ->and($service->isBanned(null, 'other'))->toBeTrue();
});

test('repeated moderation decisions load each list once', function () {
    SpotBan::query()->create(['type' => SpotBanType::Tag, 'value' => 'blocked']);
    $this->expectsDatabaseQueryCount(4);
    $service = app(SpotBanService::class);

    for ($i = 0; $i < 10; $i++) {
        expect($service->isBanned('allowed', 'allowed', 'allowed'))->toBeFalse();
    }
});

test('batch moderation uses a snapshot and refreshes changed bans in the next batch', function () {
    $service = app(SpotBanService::class);
    $service->beginBatch();
    expect($service->isBanned(null, 'new'))->toBeFalse();

    SpotBan::query()->create(['type' => SpotBanType::Tag, 'value' => 'new']);
    expect($service->isBanned(null, 'new'))->toBeFalse();

    $service->beginBatch();
    expect($service->isBanned(null, 'new'))->toBeTrue();
    $service->endBatch();
});

test('request moderation reads warmed lists from the backing cache only once', function () {
    foreach (['whitelist.poster_key_id', 'blacklist.tag', 'blacklist.poster', 'blacklist.poster_key_id'] as $list) {
        Cache::put('spot_bans.sets.' . $list, [], 60);
    }

    Event::fake([CacheHit::class]);
    $service = app(SpotBanService::class);

    for ($i = 0; $i < 10; $i++) {
        expect($service->isBanned('allowed', 'allowed', 'allowed'))->toBeFalse();
    }

    Event::assertDispatchedTimes(CacheHit::class, 4);
});

test('spot ban seeder seeds timskuik tag ban', function () {
    $this->seed(SpotBanSeeder::class);

    expect(SpotBan::query()->where('type', SpotBanType::Tag)->where('value', 'timskuik')->exists())->toBeTrue();
});
