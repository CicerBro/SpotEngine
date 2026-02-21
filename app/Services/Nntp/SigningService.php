<?php

declare(strict_types=1);

namespace App\Services\Nntp;

/**
 * Verifies Spotnet RSA signatures.
 *
 * Spotnet stores the signer's public key in the X-User-Key header as a .NET
 * RSAKeyValue XML document and the signature itself in X-XML-Signature as a
 * base64 string. The signed payload is the raw content of the X-XML header.
 */
class SigningService
{
    /**
     * Verify that the X-XML-Signature over $xmlContent is valid for the public
     * key in $userKeyXml.
     *
     * Returns false on any error (missing data, bad key, bad signature).
     */
    public function verify(string $xmlContent, string $xmlSignature, string $userKeyXml): bool
    {
        if ($xmlContent === '' || $xmlSignature === '' || $userKeyXml === '') {
            return false;
        }

        $publicKey = $this->buildPublicKey($userKeyXml);

        if ($publicKey === false) {
            return false;
        }

        $rawSignature = base64_decode($this->unescapeBase64($xmlSignature), true);

        if ($rawSignature === false || $rawSignature === '') {
            return false;
        }

        // Spotnet uses SHA-1, matching Spotweb's openssl_verify default.
        return openssl_verify($xmlContent, $rawSignature, $publicKey, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * Build an OpenSSL public key resource from a .NET RSAKeyValue XML document:
     *   <RSAKeyValue><Modulus>base64...</Modulus><Exponent>base64...</Exponent></RSAKeyValue>
     *
     * @return \OpenSSLAsymmetricKey|false
     */
    private function buildPublicKey(string $userKeyXml): mixed
    {
        if (! preg_match('/<Modulus>([^<]+)<\/Modulus>/', $userKeyXml, $modMatches)) {
            return false;
        }

        if (! preg_match('/<Exponent>([^<]+)<\/Exponent>/', $userKeyXml, $expMatches)) {
            return false;
        }

        $n = base64_decode($this->unescapeBase64(trim($modMatches[1])), true);
        $e = base64_decode($this->unescapeBase64(trim($expMatches[1])), true);

        if ($n === false || $e === false || $n === '' || $e === '') {
            return false;
        }

        $der = $this->buildSubjectPublicKeyInfo($n, $e);
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem);
    }

    /**
     * Spotnet escapes base64 characters that conflict with NNTP headers:
     * + → -p, / → -s, = → -e.
     */
    private function unescapeBase64(string $value): string
    {
        return str_replace(['-p', '-s', '-e'], ['+', '/', '='], $value);
    }

    /**
     * DER-encode a SubjectPublicKeyInfo structure for an RSA key (n, e).
     *
     * SubjectPublicKeyInfo ::= SEQUENCE {
     *   algorithm AlgorithmIdentifier,        -- OID 1.2.840.113549.1.1.1 + NULL
     *   subjectPublicKey BIT STRING            -- DER(RSAPublicKey)
     * }
     * RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
     */
    private function buildSubjectPublicKeyInfo(string $n, string $e): string
    {
        // Prepend 0x00 if the high bit is set to keep the INTEGER non-negative.
        if (ord($n[0]) > 0x7F) {
            $n = "\x00".$n;
        }

        if (ord($e[0]) > 0x7F) {
            $e = "\x00".$e;
        }

        $rsaPublicKey = $this->derSequence(
            $this->derTag(0x02, $n).$this->derTag(0x02, $e)
        );

        // OID for rsaEncryption (1.2.840.113549.1.1.1) followed by NULL parameters.
        $oid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $algorithmId = $this->derSequence($oid);

        // BIT STRING: leading 0x00 byte indicates zero unused bits.
        $bitString = $this->derTag(0x03, "\x00".$rsaPublicKey);

        return $this->derSequence($algorithmId.$bitString);
    }

    private function derSequence(string $content): string
    {
        return $this->derTag(0x30, $content);
    }

    private function derTag(int $tag, string $content): string
    {
        return chr($tag).$this->derLength(strlen($content)).$content;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        if ($length < 0x100) {
            return "\x81".chr($length);
        }

        return "\x82".chr($length >> 8).chr($length & 0xFF);
    }
}
