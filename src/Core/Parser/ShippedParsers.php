<?php declare(strict_types=1);

namespace TaxMail\Core\Parser;

use TaxMail\Core\Parser\Shop\AliExpress;
use TaxMail\Core\Parser\Shop\Conrad;
use TaxMail\Core\Parser\Shop\Ebay;
use TaxMail\Core\Parser\Shop\Jlcpcb;
use TaxMail\Core\Parser\Shop\Mouser;
use TaxMail\Core\Parser\Shop\Reichelt;

/**
 * The parsers this plugin brings. eBay is among them until tax_ebay reads eBay through its API; while tax_ebay is active
 * the eBay parser is blocked (D-942: «parser ebay ist aus wenn tax_ebay aktiv ist»).
 */
final class ShippedParsers
{
    /** @return list<OrderMailParser> */
    public static function all(): array
    {
        return [new Ebay(), new Reichelt(), new Mouser(), new Conrad(), new AliExpress(), new Jlcpcb()];
    }
}
