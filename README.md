# Taxmod Mail

Mail import for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: 0.0.18.** Fetching (WP-Cron, daily by default and adjustable in the settings, plus a button), the proposal table, the review page and writing through `taxmod/write-data` are built and tested against a local WordPress. Parsers for eBay, Reichelt, Mouser, Conrad, AliExpress and JLCPCB are ported from the Python importer in `legacy/` and checked against it on synthetic mails; the eBay parser switches itself off while tax_ebay is active. Connected to the Strato mailbox since 2026-10-20; the parsers are not yet checked on real mails. German translation in `languages/`. The draft concept (German) lives with the modeller:
[`docs/pakete/mail/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/mail/konzept.md).

## Idea in short

- A WP-Cron job fetches new order mails from the purchasing mailbox over IMAP (pure PHP library, no `ext-imap`).
- One parser per shop (eBay, Mouser, Reichelt, Conrad, AliExpress, JLCPCB) turns a mail into an order proposal.
- Proposals are stored by this module, not in the model. The owner reviews them and picks the line items to import.
- Only the picked items are written, through the modeller's `taxmod/write-data` path.
- Independent of the stock module; it only fires WordPress actions that other modules may listen to.

## Setup

Enter the mailbox under *Taxonomy Modeller → Mail import settings → Mailbox* (server, port, user, password). The
password is stored encrypted (libsodium secretbox, key derived from the WordPress auth salt in `wp-config.php`), so a
database backup alone does not reveal it; the form never shows it again. If the salts change, the page asks for it anew.

Alternatively the account can live in `wp-config.php`; these constants win over the fields:

```php
define( 'TAXMAIL_IMAP_HOST', 'imap.example.com' );
define( 'TAXMAIL_IMAP_PORT', 993 );
define( 'TAXMAIL_IMAP_USER', 'einkauf@example.com' );
define( 'TAXMAIL_IMAP_PASSWORD', '...' );
```

Then *Taxonomy Modeller → Mail import settings → Test connection*. It logs in, reports whether the server can move mails,
and creates the target folders that are missing.

## Use

The first fetch only sets a starting point: older mails are not read. **Sort backlog** (settings page) then reads
the older mails a slice per click: those whose order is already in the model move to «Importiert», those a parser
can read become proposals, the rest stay in the inbox. **Start over from now** moves what the fetch sorted away («Nicht erkannt», «Verworfen») back to the
inbox, forgets untaken proposals and sets the starting point anew. Testing the connection also subscribes the
folders, so mail programs show them. **Fetch order** (review page) reads the inbox mails that mention one order
number, also from before the starting point.

*Taxonomy Modeller → Mail import* lists every open order once — all its mails (confirmation, shipping, delivery,
forwarded copies) folded into one proposal, the confirmation's items and amounts first. Untick what should not go into the
model, then **Take** (writes the order and the ticked items in one act) or **Discard**. The mail then moves to the
«Importiert» or «Verworfen» folder. Mails no parser knows go to «Nicht erkannt» right away.

Target nodes and fields are ids in the option `taxmail_targets` (defaults: fambach.net, measured 2026-10-20).

## Extending

A mail connection is a class implementing `TaxMail\Core\Parser\OrderMailParser`, announced through the
`taxmail_parsers` filter. A module that takes over a shop (e.g. tax_ebay for eBay) adds the parser key to
`taxmail_blocked_parsers`; that parser is then off.

## AI disclosure

This repository is created and maintained with the help of AI (Claude by Anthropic). All changes are reviewed by the owner.
