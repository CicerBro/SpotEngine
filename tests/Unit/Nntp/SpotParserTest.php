<?php

declare(strict_types=1);

use App\Services\Nntp\SpotParser;

test('parseXml clears libxml errors for invalid xml payloads', function () {
    $parser = new SpotParser;
    $originalUseInternalErrors = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $result = $parser->parseXml('<<<not-valid-xml>>>');

    expect($result)->toBeNull();
    expect(libxml_get_errors())->toBe([]);

    libxml_use_internal_errors($originalUseInternalErrors);
});

test('parseFromOverview parses a valid spotnet article', function () {
    $parser = new SpotParser;

    // Realistic From header: category=1(Image), keyid=7(self-signed), subcats=a11b3c4d44z2
    $overview = [
        'from' => 'Test User <abc123@17a11b03c04d44z02.110917840.20.1771524587.1.NL.Sig123>',
        'subject' => 'BBC Sky at Night - Issue 250, March 2026',
        'date' => 'Wed, 19 Feb 2026 12:00:00 +0000',
        'message_id' => 'test-msg-001@example.com',
    ];

    $spot = $parser->parseFromOverview($overview);

    expect($spot)->not->toBeNull();
    expect($spot['message_id'])->toBe('test-msg-001@example.com');
    expect($spot['poster'])->toBe('Test User');
    expect($spot['title'])->toBe('BBC Sky at Night - Issue 250, March 2026');
    expect($spot['tag'])->toBeNull();
    expect($spot['category_code'])->toBe('01'); // Image
    expect($spot['file_size'])->toBe(110917840);
    expect($spot['description'])->toBeNull();
    expect($spot['nzb_segments'])->toBe([]);
    expect($spot['image_segment'])->toBeNull();
    expect($spot['is_verified'])->toBeFalse();
});

test('parseFromOverview extracts tag from pipe-delimited subject', function () {
    $parser = new SpotParser;

    $overview = [
        'from' => 'Poster <key@27a5b2.12345.0.1700000000.1.NL.Sig>',
        'subject' => 'Some Show S01E01|HDTV',
        'date' => 'Wed, 19 Feb 2026 10:00:00 +0000',
        'message_id' => 'msg002@example.com',
    ];

    $spot = $parser->parseFromOverview($overview);

    expect($spot)->not->toBeNull();
    expect($spot['title'])->toBe('Some Show S01E01');
    expect($spot['tag'])->toBe('HDTV');
    expect($spot['category_code'])->toBe('02'); // Sound (catChar '2')
});

test('parseFromOverview maps all four categories correctly', function () {
    $parser = new SpotParser;

    foreach (['1' => '01', '2' => '02', '3' => '03', '4' => '04'] as $catChar => $expected) {
        $overview = [
            'from' => "P <k@{$catChar}1a0.0.0.1700000000.0.NL.S>",
            'subject' => 'Title',
            'date' => 'Wed, 19 Feb 2026 10:00:00 +0000',
            'message_id' => "msg-{$catChar}@example.com",
        ];

        $spot = $parser->parseFromOverview($overview);
        expect($spot)->not->toBeNull();
        expect($spot['category_code'])->toBe($expected);
    }
});

test('parseFromOverview returns null for non-spotnet articles', function () {
    $parser = new SpotParser;

    // Missing < in From
    expect($parser->parseFromOverview([
        'from' => 'Plain Name no-angle-brackets@example.com',
        'subject' => 'Hello',
        'date' => 'Wed, 19 Feb 2026 10:00:00 +0000',
        'message_id' => 'msg@example.com',
    ]))->toBeNull();

    // Fewer than 6 dot fields in domain
    expect($parser->parseFromOverview([
        'from' => 'Name <key@1a.0.0.NL>',
        'subject' => 'Hello',
        'date' => 'Wed, 19 Feb 2026 10:00:00 +0000',
        'message_id' => 'msg@example.com',
    ]))->toBeNull();

    // Category character out of range
    expect($parser->parseFromOverview([
        'from' => 'Name <key@51a0.0.0.1700000000.0.NL.S>',
        'subject' => 'Hello',
        'date' => 'Wed, 19 Feb 2026 10:00:00 +0000',
        'message_id' => 'msg@example.com',
    ]))->toBeNull();
});

test('parseFromOverview parses subcategories from From header', function () {
    $parser = new SpotParser;

    // field0 = '1' (cat) + '7' (keyid) + 'a11b3c4d44z2' (subcats)
    $overview = [
        'from' => 'P <k@17a11b3c4d44z2.99.0.1700000000.0.NL.S>',
        'subject' => 'Title',
        'date' => 'Wed, 19 Feb 2026 10:00:00 +0000',
        'message_id' => 'msg@example.com',
    ];

    $spot = $parser->parseFromOverview($overview);

    expect($spot)->not->toBeNull();
    expect($spot['subcategories'])->toContain('a11');
    expect($spot['subcategories'])->toContain('b3');
    expect($spot['subcategories'])->toContain('c4');
    expect($spot['subcategories'])->toContain('d44');
    expect($spot['subcategories'])->toContain('z2');
});
