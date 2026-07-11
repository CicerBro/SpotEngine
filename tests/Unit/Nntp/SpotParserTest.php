<?php

declare(strict_types=1);

use App\Services\Nntp\SigningService;
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

test('parseFromOverview returns moderation array for keyid-2 delete command', function () {
    $parser = new SpotParser;

    $overview = signedModerationOverview('delete jK01nt6aOvYFJuXaQIolG@spot.net');

    $result = $parser->parseFromOverview($overview);

    expect($result)->not->toBeNull();
    expect($result['_moderation'])->toBeTrue();
    expect($result['command'])->toBe('delete');
    expect($result['target_message_id'])->toBe('jK01nt6aOvYFJuXaQIolG@spot.net');
    expect($result['poster'])->toBe('Moderator');
    expect($result['is_global_moderator'])->toBeTrue();
});

test('parseFromOverview returns moderation array for dispose and remove commands', function () {
    $parser = new SpotParser;

    foreach (['dispose', 'remove'] as $command) {
        $overview = signedModerationOverview("{$command} target-msgid@spot.net");

        $result = $parser->parseFromOverview($overview);

        expect($result)->not->toBeNull();
        expect($result['_moderation'])->toBeTrue();
        expect($result['command'])->toBe($command);
        expect($result['target_message_id'])->toBe('target-msgid@spot.net');
    }
});

test('parseFromOverview strips angle brackets from moderation target message id', function () {
    $parser = new SpotParser;

    $overview = signedModerationOverview('delete <jK01nt6aOvYFJuXaQIolG@spot.net>');

    $result = $parser->parseFromOverview($overview);

    expect($result)->not->toBeNull();
    expect($result['_moderation'])->toBeTrue();
    expect($result['target_message_id'])->toBe('jK01nt6aOvYFJuXaQIolG@spot.net');
});

test('parseFromOverview rejects unauthenticated keyid-2 moderation commands', function () {
    $result = (new SpotParser)->parseFromOverview([
        'from' => 'Attacker <key@12a0.0.0.1700100000.0.NL.invalid-signature>',
        'subject' => 'delete victim@spot.net',
        'date' => 'Wed, 19 Feb 2026 10:00:00 +0000',
        'message_id' => 'forged-moderation@spot.net',
    ]);

    expect($result)->not->toBeNull()
        ->and($result)->not->toHaveKey('_moderation');
});

test('parseFromOverview skips DISPOSE continuation articles for non-keyid-2', function () {
    $parser = new SpotParser;

    // keyid=7 (not 2), DISPOSE in Subject — multi-part continuation, not moderation
    $overview = [
        'from' => 'User <key@17a0.0.0.1700100000.0.NL.S>',
        'subject' => 'DISPOSE asHskHinM1IOpiXaQJNQX@spot.net - Hieringbiete-Konzèr 2026',
        'date' => 'Wed, 19 Feb 2026 10:00:00 +0000',
        'message_id' => 'disposal@spot.net',
    ];

    expect($parser->parseFromOverview($overview))->toBeNull();
});

test('SigningService returns false for empty inputs', function () {
    $signer = new SigningService;

    expect($signer->verify('', 'sig', 'key'))->toBeFalse();
    expect($signer->verify('xml', '', 'key'))->toBeFalse();
    expect($signer->verify('xml', 'sig', ''))->toBeFalse();
});

test('SigningService returns false for malformed public key xml', function () {
    $signer = new SigningService;

    expect($signer->verify('<Spot/>', base64_encode('fakesig'), '<BadXml/>'))->toBeFalse();
    expect($signer->verify('<Spot/>', base64_encode('fakesig'), '<RSAKeyValue><Modulus>AAAA</Modulus></RSAKeyValue>'))->toBeFalse();
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
    expect($spot['subcategories'])->toContain('01a11');
    expect($spot['subcategories'])->toContain('01b03');
    expect($spot['subcategories'])->toContain('01c04');
    expect($spot['subcategories'])->toContain('01d44');
    expect($spot['subcategories'])->toContain('01z02');
});

test('parseXml only retains safe website schemes', function (string $website, ?string $expected) {
    $xml = "<Spotnet><Posting><Title>Website test</Title><Website>{$website}</Website><Category>01</Category></Posting></Spotnet>";

    expect((new SpotParser)->parseXml($xml)['website'])->toBe($expected);
})->with([
    'https' => ['https://example.com/path', 'https://example.com/path'],
    'http' => ['http://example.com', 'http://example.com'],
    'javascript' => ['javascript:alert(1)', null],
    'data' => ['data:text/html;base64,PHNjcmlwdD4=', null],
    'relative' => ['//example.com/path', null],
]);

/**
 * @return array{subject: string, from: string, date: string, message_id: string}
 */
function signedModerationOverview(string $subject): array
{
    $privateKey = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($privateKey);
    $modulus = base64_encode($details['rsa']['n']);
    $exponent = base64_encode($details['rsa']['e']);

    config(['spotengine.moderation.public_keys.2' => [
        'modulus' => $modulus,
        'exponent' => $exponent,
    ]]);

    $poster = 'Moderator';
    $unsignedHeader = '12a0.0.0.1700100000.0.NL';
    openssl_sign($subject.$unsignedHeader.$poster, $signature, $privateKey, OPENSSL_ALGO_SHA1);
    $preparedSignature = str_replace(['+', '/', '='], ['-p', '-s', '-e'], base64_encode($signature));

    return [
        'from' => "{$poster} <key@{$unsignedHeader}.{$preparedSignature}>",
        'subject' => $subject,
        'date' => 'Wed, 19 Feb 2026 10:00:00 +0000',
        'message_id' => 'authenticated-moderation@spot.net',
    ];
}
