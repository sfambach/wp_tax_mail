<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use Taxmod\Core\Import\OrderProposal;
use TaxMail\Core\Proposal\WonAuctionMatch;
use TaxMail\Core\Write\ExistingOrder;
use TaxMail\Core\Write\TargetModel;
use WP_Error;

/**
 * The only way into the modeller: its abilities, called in-process as the logged-in user — the same path and the
 * same rights as the Python importer had over HTTP (D-936). No table of the modeller is read directly.
 */
final class ModelGateway
{
    /** @var array<string, ExistingOrder>|null platform|number (lower case) => order */
    private ?array $orders = null;

    /** @var array<string, array{0: string, 1: ?string, 2: ?string, 3: string}> platform, merchant, ordered, title per order key */
    private array $facts = [];

    public function __construct(private readonly TargetModel $target)
    {
    }

    public static function available(): bool
    {
        return function_exists('wp_get_ability') && wp_get_ability('taxmod/write-data') !== null;
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, int>|WP_Error ref => record id
     */
    public function write(array $changes): array|WP_Error
    {
        $result = $this->execute('taxmod/write-data', ['changes' => $changes]);

        if ($result instanceof WP_Error) {
            return $result;
        }

        $this->orders = null;

        return array_map('intval', (array) ($result['refs'] ?? []));
    }

    public function findOrder(string $platform, string $orderNumber): ExistingOrder|WP_Error|null
    {
        if ($this->orders === null) {
            $loaded = $this->loadOrders();

            if ($loaded instanceof WP_Error) {
                return $loaded;
            }

            $this->orders = $loaded;
        }

        return $this->orders[self::key($platform, $orderNumber)] ?? null;
    }

    /** The order a won auction or invoice without order number belongs to, by seller or title within 14 days. */
    public function findWonAuction(OrderProposal $won): ExistingOrder|WP_Error|null
    {
        if ($this->orders === null) {
            $loaded = $this->loadOrders();

            if ($loaded instanceof WP_Error) {
                return $loaded;
            }

            $this->orders = $loaded;
        }

        foreach ($this->facts as $key => [$platform, $merchant, $ordered, $title]) {
            if (mb_strtolower($platform) === mb_strtolower($won->platform) && WonAuctionMatch::matches($won, $merchant, $ordered, $title)) {
                return $this->orders[$key];
            }
        }

        return null;
    }

    /** @return array<string, ExistingOrder>|WP_Error */
    private function loadOrders(): array|WP_Error
    {
        $node = $this->execute('taxmod/read-node', ['node' => $this->target->ordersNode]);

        if ($node instanceof WP_Error) {
            return $node;
        }

        $platformField = $this->target->orderField('platform');
        $numberField = $this->target->orderField('order_number');
        $trackingField = $this->target->orderField('tracking');
        $receivedField = $this->target->orderField('received_at');
        $merchantField = $this->target->orderField('merchant');
        $orderedField = $this->target->orderField('ordered_at');
        $titleField = $this->target->orderField('title');
        $orders = [];
        $this->facts = [];

        foreach ((array) ($node['records'] ?? []) as $record) {
            $platform = '';
            $number = '';
            $tracking = [];
            $received = null;
            $merchant = null;
            $ordered = null;
            $title = '';

            foreach ((array) ($record['values'] ?? []) as $value) {
                match ((int) $value['relation_id']) {
                    $platformField => $platform = (string) $value['value'],
                    $numberField   => $number = (string) $value['value'],
                    $trackingField => $tracking[] = (string) $value['value'],
                    $receivedField => $received = (string) $value['value'],
                    $merchantField => $merchant = (string) $value['value'],
                    $orderedField  => $ordered = (string) $value['value'],
                    $titleField    => $title .= ' ' . (string) $value['value'],
                    default        => null,
                };
            }

            if ($number !== '') {
                $orders[self::key($platform, $number)] = new ExistingOrder((int) $record['id'], $tracking, $received);
                $this->facts[self::key($platform, $number)] = [$platform, $merchant, $ordered, $title];
            }
        }

        return $orders;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    private function execute(string $name, array $input): array|WP_Error
    {
        $ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;

        if ($ability === null) {
            return new WP_Error('taxmail_no_modeller', __('The Taxonomy Modeller is not active.', 'taxmail'));
        }

        $result = $ability->execute($input);

        return $result instanceof WP_Error ? $result : (array) $result;
    }

    private static function key(string $platform, string $orderNumber): string
    {
        return mb_strtolower(trim($platform)) . '|' . mb_strtolower(trim($orderNumber));
    }
}
