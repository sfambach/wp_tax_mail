<?php declare(strict_types=1);

namespace TaxMail\Core\Parser;

use TaxMail\Core\Parser\Shop\AliExpress;
use TaxMail\Core\Parser\Shop\Conrad;
use TaxMail\Core\Parser\Shop\Jlcpcb;
use TaxMail\Core\Parser\Shop\Mouser;
use TaxMail\Core\Parser\Shop\Reichelt;

/**
 * The parsers this plugin brings. eBay is not among them: tax_ebay reads eBay through its API (D-942, M7).
 */
final class ShippedParsers
{
    /** @return list<OrderMailParser> */
    public static function all(): array
    {
        return [new Reichelt(), new Mouser(), new Conrad(), new AliExpress(), new Jlcpcb()];
    }
}
