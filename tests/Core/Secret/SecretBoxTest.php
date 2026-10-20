<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Secret;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Secret\SecretBox;
use TaxMail\Core\Secret\SecretException;

final class SecretBoxTest extends TestCase
{
    public function testOpensWhatItSealed(): void
    {
        $box = new SecretBox('salt-a');
        $sealed = $box->seal('pä$$wört');

        self::assertStringNotContainsString('pä$$wört', $sealed);
        self::assertSame('pä$$wört', $box->open($sealed));
    }

    public function testSealsDifferentlyEachTime(): void
    {
        $box = new SecretBox('salt-a');

        self::assertFalse($box->seal('x') === $box->seal('x'));
    }

    public function testRefusesAnotherKey(): void
    {
        $sealed = (new SecretBox('salt-a'))->seal('secret');
        $this->expectException(SecretException::class);
        (new SecretBox('salt-b'))->open($sealed);
    }

    public function testRefusesPlainText(): void
    {
        $this->expectException(SecretException::class);
        (new SecretBox('salt-a'))->open('secret');
    }
}
