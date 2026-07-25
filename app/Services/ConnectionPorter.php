<?php

namespace App\Services;

use App\Models\Connection;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Exports and imports saved connections as .dbmconn files: either an
 * encrypted envelope (XSalsa20-Poly1305 secretbox, Argon2id-derived key)
 * or plain JSON at the user's explicit request.
 */
class ConnectionPorter
{
    public const FORMAT_VERSION = 1;

    private const EXPORTED_FIELDS = [
        'name', 'color', 'host', 'port', 'username', 'password', 'database',
        'use_ssh', 'ssh_host', 'ssh_port', 'ssh_username', 'ssh_auth_type',
        'ssh_password', 'ssh_key_path', 'default_database',
    ];

    /**
     * @param  Collection<int, Connection>  $connections
     */
    public function export(Collection $connections, ?string $passphrase = null): string
    {
        $payload = json_encode([
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'connections' => $connections
                ->map(fn (Connection $connection) => collect(self::EXPORTED_FIELDS)
                    ->mapWithKeys(fn ($field) => [$field => $connection->$field])
                    ->all())
                ->values()
                ->all(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($passphrase === null) {
            return json_encode([
                'format' => 'dbmconn',
                'format_version' => self::FORMAT_VERSION,
                'encrypted' => false,
                'payload' => json_decode($payload, true),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->deriveKey($passphrase, $salt);

        $ciphertext = sodium_crypto_secretbox($payload, $nonce, $key);
        sodium_memzero($key);

        return json_encode([
            'format' => 'dbmconn',
            'format_version' => self::FORMAT_VERSION,
            'encrypted' => true,
            'kdf' => 'argon2id',
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'data' => base64_encode($ciphertext),
        ]);
    }

    /**
     * Whether file contents look like an encrypted export (so the UI knows
     * to ask for a passphrase).
     */
    public function isEncrypted(string $contents): bool
    {
        $envelope = json_decode($contents, true);

        return is_array($envelope) && ($envelope['encrypted'] ?? false) === true;
    }

    /**
     * Parse a .dbmconn file and return the connection attribute arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function import(string $contents, ?string $passphrase = null): array
    {
        $envelope = json_decode($contents, true);

        if (! is_array($envelope) || ($envelope['format'] ?? null) !== 'dbmconn') {
            throw new RuntimeException('This file is not a TabulaSQL connection export.');
        }

        if (($envelope['format_version'] ?? 0) > self::FORMAT_VERSION) {
            throw new RuntimeException('This export was made by a newer version of TabulaSQL.');
        }

        if ($envelope['encrypted'] ?? false) {
            if ($passphrase === null || $passphrase === '') {
                throw new RuntimeException('This export is encrypted. A passphrase is required.');
            }

            $salt = base64_decode($envelope['salt'] ?? '', true);
            $nonce = base64_decode($envelope['nonce'] ?? '', true);
            $ciphertext = base64_decode($envelope['data'] ?? '', true);

            if ($salt === false || $nonce === false || $ciphertext === false) {
                throw new RuntimeException('The export file is corrupted.');
            }

            $key = $this->deriveKey($passphrase, $salt);
            $payload = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
            sodium_memzero($key);

            if ($payload === false) {
                throw new RuntimeException('Wrong passphrase (or the file is corrupted).');
            }

            $decoded = json_decode($payload, true);
        } else {
            $decoded = $envelope['payload'] ?? null;
        }

        if (! is_array($decoded) || ! is_array($decoded['connections'] ?? null)) {
            throw new RuntimeException('The export file is corrupted.');
        }

        return array_map(
            fn (array $connection) => array_intersect_key($connection, array_flip(self::EXPORTED_FIELDS)),
            $decoded['connections']
        );
    }

    private function deriveKey(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }
}
