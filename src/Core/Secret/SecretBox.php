<?php declare(strict_types=1);

namespace TaxMail\Core\Secret;

/**
 * Seals a secret for storage and opens it again. The mailbox password has to go to the server as written, so it can
 * only be encrypted, never hashed; the key is derived from material that lives outside the database (his word
 * 2026-10-20: «ja verschlüsselt einbauen»).
 */
final class SecretBox
{
    private const PREFIX = 'sb1:';

    private readonly string $key;

    public function __construct(string $keyMaterial)
    {
        if ($keyMaterial === '') {
            throw new SecretException('no key material');
        }

        $this->key = sodium_crypto_generichash('taxmail-secret|' . $keyMaterial, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function seal(string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, $this->key));
    }

    /** @throws SecretException when the text was not sealed with this key — e.g. after the salts changed */
    public function open(string $sealed): string
    {
        if (! str_starts_with($sealed, self::PREFIX)) {
            throw new SecretException('not a sealed secret');
        }

        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new SecretException('sealed secret is damaged');
        }

        $secret = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );

        if ($secret === false) {
            throw new SecretException('sealed secret does not open with this key');
        }

        return $secret;
    }
}
