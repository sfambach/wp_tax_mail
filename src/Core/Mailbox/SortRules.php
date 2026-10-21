<?php declare(strict_types=1);

namespace TaxMail\Core\Mailbox;

/**
 * His sorting rules (D-949), written one per line as «sender | subject | folder» so he can read and change them in a
 * plain text field. The first rule that matches wins. They only sort mails that do not become an order proposal: a
 * rule on «ebay.com» must never swallow a purchase.
 *
 * @see docs/pakete/mail/konzept.md (modeller repository) §3a
 */
final class SortRules
{
    /** @param list<SortRule> $rules */
    public function __construct(public readonly array $rules = [])
    {
    }

    /** Lines that name no folder or neither sender nor subject are dropped; the screen shows what was kept. */
    public static function fromText(string $text): self
    {
        $rules = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));

            if (count($parts) !== 3 || $parts[2] === '' || ($parts[0] === '' && $parts[1] === '')) {
                continue;
            }

            $rules[] = new SortRule($parts[0], $parts[1], trim($parts[2], " /"));
        }

        return new self($rules);
    }

    public function toText(): string
    {
        return implode("\n", array_map(static fn (SortRule $rule): string => $rule->sender . ' | ' . $rule->subject . ' | ' . $rule->folder, $this->rules));
    }

    /** @return string|null the folder as he wrote it */
    public function folderFor(string $sender, string $subject): ?string
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($sender, $subject)) {
                return $rule->folder;
            }
        }

        return null;
    }

    /**
     * «Verworfen» and «Nicht erkannt» stand for the configured folders, so a rule survives renaming them; anything
     * else is a folder of his own, written with «/».
     */
    public static function resolve(string $folder, FolderPlan $plan): string
    {
        return match (mb_strtolower($folder)) {
            'verworfen', mb_strtolower(basename($plan->discarded))       => $plan->discarded,
            'nicht erkannt', mb_strtolower(basename($plan->unrecognised)) => $plan->unrecognised,
            'importiert', mb_strtolower(basename($plan->imported))       => $plan->imported,
            default                                                      => $folder,
        };
    }

    /** @return list<string> every folder the rules point at, resolved */
    public function folders(FolderPlan $plan): array
    {
        return array_values(array_unique(array_map(static fn (SortRule $rule): string => self::resolve($rule->folder, $plan), $this->rules)));
    }
}
