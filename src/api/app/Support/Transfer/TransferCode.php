<?php

namespace App\Support\Transfer;

use JsonException;

/**
 * Projects as one line of text for another deploy.me: gzipped JSON sealed
 * with a passphrase (PBKDF2-SHA256 → AES-256-GCM). The other machine has its
 * own APP_KEY, so the passphrase is the only key: without it the code says
 * nothing, and a changed character makes it refuse to open.
 */
final class TransferCode
{
    public const PREFIX = 'deployme1:';

    public const FORMAT = 'deployme.projects';

    public const VERSION = 1;

    public const MIN_PASSPHRASE = 10;

    // OWASP's figure for PBKDF2-HMAC-SHA256: about a third of a second here.
    private const ITERATIONS = 600_000;

    // What a code may unpack to: far above a few dozen projects with their env files.
    private const MAX_JSON = 20 * 1024 * 1024;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function seal(array $payload, string $passphrase): string
    {
        $plain = (string) gzencode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 9);
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key($passphrase, $salt), OPENSSL_RAW_DATA, $iv, $tag, self::PREFIX, 16);

        return self::PREFIX.rtrim(strtr(base64_encode($salt.$iv.$tag.$cipher), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TransferCodeInvalid with a message to show
     */
    public static function open(string $code, string $passphrase): array
    {
        // Pasted from a chat or a note, it may have picked up line breaks.
        $code = (string) preg_replace('/\s+/', '', $code);

        if (! str_starts_with($code, self::PREFIX)) {
            throw new TransferCodeInvalid('That isn’t a deploy.me transfer code: one starts with '.self::PREFIX);
        }

        $raw = base64_decode(strtr(substr($code, strlen(self::PREFIX)), '-_', '+/'), true);

        if ($raw === false || strlen($raw) <= 44) {
            throw new TransferCodeInvalid('The code is cut short. Copy all of it, from '.self::PREFIX.' to the end.');
        }

        $plain = openssl_decrypt(substr($raw, 44), 'aes-256-gcm', self::key($passphrase, substr($raw, 0, 16)), OPENSSL_RAW_DATA, substr($raw, 16, 12), substr($raw, 28, 16), self::PREFIX);

        if ($plain === false) {
            throw new TransferCodeInvalid('Wrong passphrase, or the code changed on the way.');
        }

        $json = @gzdecode($plain, self::MAX_JSON);

        try {
            $payload = $json === false ? null : json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $payload = null;
        }

        if (! is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT || ! is_array($payload['projects'] ?? null)) {
            throw new TransferCodeInvalid('The code opened, but there are no projects in it.');
        }

        if (($payload['version'] ?? null) !== self::VERSION) {
            throw new TransferCodeInvalid('This code comes from a newer deploy.me. Update this one first.');
        }

        return $payload;
    }

    private static function key(string $passphrase, string $salt): string
    {
        return hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
    }
}
