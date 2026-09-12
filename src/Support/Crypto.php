<?php

declare(strict_types=1);

namespace AIPanel\Support;

/**
 * Authenticated symmetric encryption for secrets at rest (node agent keys).
 * AES-256-GCM under APP_KEY.
 */
final class Crypto
{
    private string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('APP_KEY must be 32 random bytes, base64-encoded (php bin/console.php key:generate).');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Malformed ciphertext.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed (wrong key or tampered ciphertext).');
        }

        return $plain;
    }
}
