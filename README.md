# Taxmod Mail

Mail import for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: 0.0.1, not released.** Fetching (hourly WP-Cron and a button), the proposal table, the review page and writing through `taxmod/write-data` are built and tested against a local WordPress. **No shop parser yet**, so nothing is recognised; the IMAP part is tested only against a scripted server, not against Strato. The draft concept (German) lives with the modeller:
[`docs/pakete/mail/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/mail/konzept.md).

## Idea in short

- A WP-Cron job fetches new order mails from the purchasing mailbox over IMAP (pure PHP library, no `ext-imap`).
- One parser per shop (eBay, Mouser, Reichelt, Conrad, AliExpress, JLCPCB) turns a mail into an order proposal.
- Proposals are stored by this module, not in the model. The owner reviews them and picks the line items to import.
- Only the picked items are written, through the modeller's `taxmod/write-data` path.
- Independent of the stock module; it only fires WordPress actions that other modules may listen to.

## Setup

Mailbox credentials go into `wp-config.php`, not into the database:

```php
define( 'TAXMAIL_IMAP_HOST', 'imap.example.com' );
define( 'TAXMAIL_IMAP_PORT', 993 );
define( 'TAXMAIL_IMAP_USER', 'einkauf@example.com' );
define( 'TAXMAIL_IMAP_PASSWORD', '...' );
```

Then *Taxonomy Modeller → Mail import settings → Test connection*. It logs in, reports whether the server can move mails,
and creates the target folders that are missing.

## Use

*Taxonomy Modeller → Mail import* lists every open proposal with its items. Untick what should not go into the
model, then **Take** (writes the order and the ticked items in one act) or **Discard**. The mail then moves to the
«Importiert» or «Verworfen» folder. Mails no parser knows go to «Nicht erkannt» right away.

Target nodes and fields are ids in the option `taxmail_targets` (defaults: fambach.net, measured 2026-10-20).

## Extending

A mail connection is a class implementing `TaxMail\Core\Parser\OrderMailParser`, announced through the
`taxmail_parsers` filter. A module that takes over a shop (e.g. tax_ebay for eBay) adds the parser key to
`taxmail_blocked_parsers`; that parser is then off.

## AI disclosure

This repository is created and maintained with the help of AI (Claude by Anthropic). All changes are reviewed by the owner.
