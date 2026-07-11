<?php

declare(strict_types=1);

use App\Services\Nntp\SigningService;

test('verify returns true for valid standard base64 signature', function () {
    [$xmlContent, $sigBase64, $userKeyXml] = generateTestSignature();

    $signer = new SigningService;

    expect($signer->verify($xmlContent, $sigBase64, $userKeyXml))->toBeTrue();
});

test('verify returns true for valid spotnet-escaped base64 signature', function () {
    [$xmlContent, $sigBase64, $userKeyXml] = generateTestSignature();

    $escapedSig = str_replace(['+', '/', '='], ['-p', '-s', '-e'], $sigBase64);
    $escapedKeyXml = preg_replace_callback(
        '/<(Modulus|Exponent)>([^<]+)</',
        fn ($m) => "<{$m[1]}>" . str_replace(['+', '/', '='], ['-p', '-s', '-e'], $m[2]) . '<',
        $userKeyXml,
    );

    $signer = new SigningService;

    expect($signer->verify($xmlContent, $escapedSig, $escapedKeyXml))->toBeTrue();
});

test('verify returns false for tampered content', function () {
    [$xmlContent, $sigBase64, $userKeyXml] = generateTestSignature();

    $signer = new SigningService;

    expect($signer->verify($xmlContent . ' tampered', $sigBase64, $userKeyXml))->toBeFalse();
});

test('verify returns false for empty inputs', function () {
    $signer = new SigningService;

    expect($signer->verify('', 'sig', '<RSAKeyValue/>'))->toBeFalse();
    expect($signer->verify('content', '', '<RSAKeyValue/>'))->toBeFalse();
    expect($signer->verify('content', 'sig', ''))->toBeFalse();
});

/**
 * Generate a test RSA key pair, sign XML content, and return [xmlContent, signatureBase64, userKeyXml].
 *
 * @return array{string, string, string}
 */
function generateTestSignature(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($key);

    $modBase64 = base64_encode($details['rsa']['n']);
    $expBase64 = base64_encode($details['rsa']['e']);
    $userKeyXml = "<RSAKeyValue><Modulus>{$modBase64}</Modulus><Exponent>{$expBase64}</Exponent></RSAKeyValue>";

    $xmlContent = '<Spotnet><Posting><Description>Test spot</Description></Posting></Spotnet>';
    openssl_sign($xmlContent, $signature, $key, OPENSSL_ALGO_SHA1);

    return [$xmlContent, base64_encode($signature), $userKeyXml];
}
