<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Secret;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\SecretBox;

/** The mailbox password sealed by tax_mail before 0.0.22 opens with the modeller's vault under the same context. */
final class StoredPasswordTest extends TestCase
{
    public function testPasswordSealedBeforeTheVaultMovedStillOpens(): void
    {
        // Exactly what tax_mail's own SecretBox wrote up to 0.0.21.
        $key = sodium_crypto_generichash('taxmail-secret|salt-a', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $stored = 'sb1:' . base64_encode($nonce . sodium_crypto_secretbox('pä$$wört', $nonce, $key));

        self::assertSame('pä$$wört', (new SecretBox('salt-a', 'taxmail-secret'))->open($stored));
    }
}
