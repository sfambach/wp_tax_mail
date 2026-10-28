<?php declare(strict_types=1);

namespace TaxMail\Core\Export;

use Taxmod\Core\Import\Money;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;

/**
 * Amazon's «Order History.csv» from «Meine Daten anfordern» — the orders from before the mail parser, one proposal per
 * order. A row is one item of one shipment; its «Total Amount» is the item's gross price times the quantity plus the
 * row's shipping charge, so the order total is their sum. Addresses and payment columns are not read. A cancelled
 * order is left out. A closed one counts as shipped, not delivered: Amazon writes no delivery date, and the inbox
 * would write the day it is taken as «received» (the WE thread, 2026-10-28: «kein erfundenes Eingegangen am»).
 *
 * @see docs/pakete/mail/konzept.md (modeller)
 */
final class AmazonOrderHistory
{
    /** Departments that mean material for the workshop, for the choice «only technical». */
    public const TECHNICAL = [
        'Electronics', 'Personal Computers', 'Tools & Home Improvement', 'Tools & Hardware', 'Werkzeug', 'Camera',
        'Wireless Phone Accessory', 'Office Product', 'Automotive', 'Battery', 'Software Download', 'CD-ROM',
    ];

    /** Departments whose items are never material, whatever their name says (a book on 3D printing, a coffee adapter). */
    public const NEVER_TECHNICAL = [
        'Grocery', 'Wine', 'Apparel', 'Shoes', 'Sports Apparel', 'Taschenbuch', 'Gebundene Ausgabe', 'Perfect Paperback',
        'Paperback', 'Hardcover', 'Unbekannter Einband', 'Taschenbuchformat', 'Taschenbuch für den Massenmarkt',
        'Health and Beauty', 'Baby Product', 'Audio CD', 'DVD', 'Blu-ray', 'MP3 Music', 'MP3 CD', 'Ecard Gift Certificate',
        'Paper Gift Certificate', 'Calendar', 'Jewelry', 'Watch', 'Luggage',
    ];

    /**
     * Words in a product name that mean material. Amazon's department alone misses much of it: capacitors under «Not
     * Available», an SSD under «Accessory», servos under «Toy» (sample of his export, 2026-10-28).
     */
    private const TECHNICAL_WORDS = '/\b(?:kondensator|widerstand|resistor|capacitor|transistor|diode|led\b|leds\b|arduino|esp32|esp8266|esp-?01|raspberry|pi pico|attiny|atmega|stm32|mikrocontroller|microcontroller|platine|lochraster|breadboard|steckbrett|jumper|dupont|löt|solder|flussmittel|entlötlitze|multimeter|oszilloskop|oscilloscope|\w*netzteil|labornetzteil|power supply|step.?down|boost|spannungsregler|regulator|relais|relay|sensor|servo|stepper|schrittmotor|eeprom|eprom|sram|spi\b|i2c\b|uart|usb|hdmi|vga|displayport|ssd\b|festplatte|hard drive|hdd\b|sd.?karte|micro ?sd|speicherkarte|ddr\d|mainboard|motherboard|grafikkarte|prozessor|cpu\b|lüfter|kühlkörper|wärmeleit|switch\b|router|wlan|wifi|ethernet|netzwerkkabel|patchkabel|rj45|keystone|bluetooth|\w*adapter|\w*kabel|\w*stecker|buchse|klemme|crimp|schrumpfschlauch|akku|batterie|lipo|18650|ladegerät|charger|display|oled|lcd|tft|tastatur|keyboard|maus\b|mouse|webcam|kamera|drucker|filament|3d.?druck|pla\b|petg|messschieber|zange|pinzette|schraubendreher|bit.?satz|bohrer|bohrmaschine|akkuschrauber|säge|feile|schraube|mutter|abstandshalter|gehäuse|enclosure|lautsprecher|verstärker|amplifier|potentiometer|poti|taster|schalter|encoder|joystick|commodore|amiga|atari|c64|gameboy|sega|nintendo|playstation|isa\b|floppy|diskette|cf.?karte|compactflash|sata|scsi|programmer|programmiergerät|logic analyzer|logikanalysator|jtag|ttl|rs232|max232|ch340|cp2102|ftdi|tl866|xgecu|kicad|nema|ide-|\w*modul\b|modules?\b|ics?\b|ram\b|buck\b)/iu';

    private const COLUMNS = ['Order ID', 'Order Date', 'Order Status', 'ASIN', 'Product Name', 'Original Quantity',
        'Unit Price', 'Unit Price Tax', 'Shipping Charge', 'Total Amount', 'Currency', 'Ship Date',
        'Carrier Name & Tracking Number', 'Department'];

    /**
     * @param ?string $since first order date kept, «Y-m-d»; null keeps all
     * @param ?list<string> $departments keep an order only if one of its items is in one of these or named like material; null keeps all
     */
    public function __construct(private readonly ?string $since = null, private readonly ?array $departments = null)
    {
    }

    /**
     * @return list<OrderProposal> oldest first
     * @throws \InvalidArgumentException when the text is not that file
     */
    public function read(string $csv): array
    {
        $rows = self::rows($csv);
        $orders = [];

        foreach ($rows as $row) {
            $orders[$row['Order ID']][] = $row;
        }

        $proposals = [];

        foreach ($orders as $number => $items) {
            if ($this->keeps($items)) {
                $proposals[] = self::proposal((string) $number, $items);
            }
        }

        usort($proposals, static fn (OrderProposal $a, OrderProposal $b): int => strcmp((string) $a->orderDate, (string) $b->orderDate));

        return $proposals;
    }

    /** @param non-empty-list<array<string, string>> $items */
    private function keeps(array $items): bool
    {
        if (array_filter($items, static fn (array $item): bool => $item['Order Status'] !== 'Cancelled') === []) {
            return false;
        }

        if ($this->since !== null && substr($items[0]['Order Date'], 0, 10) < $this->since) {
            return false;
        }

        return $this->departments === null || array_filter($items, $this->technical(...)) !== [];
    }

    /**
     * In one of the chosen departments, or named like material outside the departments that never are.
     *
     * @param array<string, string> $item
     */
    private function technical(array $item): bool
    {
        return in_array($item['Department'], (array) $this->departments, true)
            || (! in_array($item['Department'], self::NEVER_TECHNICAL, true) && preg_match(self::TECHNICAL_WORDS, $item['Product Name']) === 1);
    }

    /** @param non-empty-list<array<string, string>> $items */
    private static function proposal(string $number, array $items): OrderProposal
    {
        $items = array_values(array_filter($items, static fn (array $item): bool => $item['Order Status'] !== 'Cancelled'));
        $lines = [];
        $shipping = 0;
        $total = 0;
        $shipped = [];
        $tracking = [];

        foreach ($items as $item) {
            $unit = (Money::toUnits(self::amount($item['Unit Price'])) ?? 0) + (Money::toUnits(self::amount($item['Unit Price Tax'])) ?? 0);
            $lines[] = new ProposalLine(
                trim($item['Product Name']),
                (float) max(1, (int) $item['Original Quantity']),
                Money::format($unit),
                self::given($item['ASIN']),
            );
            $shipping += Money::toUnits(self::amount($item['Shipping Charge'])) ?? 0;
            $total += Money::toUnits(self::amount($item['Total Amount'])) ?? 0;

            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $item['Ship Date'], $date) === 1) {
                $shipped[] = $date[0];
            }

            // «AMZN_DE(DE5342911425)», «DP(JJD000390016672041856)»; several shipments joined by « and ».
            preg_match_all('/\(([A-Z0-9]{8,})\)/', $item['Carrier Name & Tracking Number'], $numbers);
            array_push($tracking, ...$numbers[1]);
        }

        $open = array_filter($items, static fn (array $item): bool => $item['Order Status'] !== 'Closed') !== [];
        sort($shipped);

        return new OrderProposal(
            'Amazon',
            $number,
            $open ? ProposalKind::Ordered : ProposalKind::Shipped,
            substr($items[0]['Order Date'], 0, 10),
            self::given($items[0]['Currency']) ?? 'EUR',
            $lines,
            Money::format($shipping),
            Money::format($total),
            null,
            null,
            $shipped[0] ?? null,
            array_values(array_unique($tracking)),
            'amazon-export',
            'amazon-export:' . $number,
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private static function rows(string $csv): array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv);
        rewind($handle);
        $header = fgetcsv($handle, null, ',', '"', '');

        if (! is_array($header) || array_diff(self::COLUMNS, $header) !== []) {
            fclose($handle);

            throw new \InvalidArgumentException('not Amazon\'s «Order History.csv»: columns missing');
        }

        $rows = [];

        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }

            $row = array_intersect_key(array_combine($header, array_map('strval', $values)), array_flip(self::COLUMNS));

            if ($row['Order ID'] !== '') {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    private static function amount(string $value): ?string
    {
        return is_numeric($value) ? $value : null;
    }

    private static function given(string $value): ?string
    {
        $value = trim($value);

        return $value === '' || $value === 'Not Available' || $value === 'Not Applicable' ? null : $value;
    }
}
