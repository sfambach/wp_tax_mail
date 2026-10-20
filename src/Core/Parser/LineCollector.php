<?php declare(strict_types=1);

namespace TaxMail\Core\Parser;

use TaxMail\Core\Proposal\ProposalLine;

/**
 * Items of one mail; the same item (article number, or title start) seen twice is merged, not doubled — the
 * Python importer's Store.add_item, narrowed to one mail.
 */
final class LineCollector
{
    /** @var list<ProposalLine> */
    private array $lines = [];

    public function add(string $title, ?float $quantity, ?float $unitPrice, ?string $articleNumber): void
    {
        foreach ($this->lines as $index => $existing) {
            $sameArticle = $articleNumber !== null && $existing->articleNumber === $articleNumber;
            $sameTitle = $existing->articleNumber === $articleNumber && mb_substr($existing->title, 0, 40) === mb_substr($title, 0, 40);

            if ($sameArticle || $sameTitle) {
                $longer = mb_strlen($title) > mb_strlen($existing->title) && ! str_ends_with($title, '...') && ! str_ends_with($title, '…');
                $this->lines[$index] = new ProposalLine(
                    $longer ? $title : $existing->title,
                    $quantity ?? $existing->quantity,
                    Text::decimal($unitPrice) ?? $existing->unitPrice,
                    $existing->articleNumber,
                );

                return;
            }
        }

        $this->lines[] = new ProposalLine($title, $quantity, Text::decimal($unitPrice), $articleNumber);
    }

    /** @return list<ProposalLine> */
    public function lines(): array
    {
        return $this->lines;
    }
}
