# Taxmod Mail

Mail import for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: 0.1.3.** Settings are the tab «Mail» of the modeller's Configuration page (D-945), with a «How to connect» box beside it (D-963) and the version under the tab bar (D-968); both show from the modeller release after 0.1.40. Needs the Taxonomy Modeller 0.1.36 or later; without it the module stays off and says so. Since 0.1.0 the mailbox is a *source* of the modeller's import inbox (D-952, D-953): proposals, the review page, merging, the duty table (which module brings a shop's orders, which only its status) and writing through `taxmod/write-data` are the modeller's; this module reads mails, hands over what a parser read, and sorts the mailbox. Proposals still open in the 0.0.x table are handed to the inbox once. Parsers for eBay, Reichelt, Mouser, Conrad, AliExpress and JLCPCB are ported from the Python importer in `legacy/` and checked against it on synthetic mails. Sorting rules (sender | subject | folder) and a sender count in the settings sort mails that are no order; a shop's mail without an order goes to «Verworfen». Connected to the Strato mailbox since 2026-10-20. German translation in `languages/`. The draft concept (German) lives with the modeller:
[`docs/pakete/mail/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/mail/konzept.md).

## Idea in short

- The modeller's schedule fetches new order mails from the purchasing mailbox over IMAP (pure PHP, no `ext-imap`).
- One parser per shop (eBay, Mouser, Reichelt, Conrad, AliExpress, JLCPCB) turns a mail into an order proposal.
- Proposals go to the modeller's import inbox, not into the model. The owner reviews them and picks the line items.
- Only the picked items are written, by the modeller, through `taxmod/write-data`; then the mail moves.
- Independent of the stock module; it only fires WordPress actions that other modules may listen to.

## Tests

The core tests load the modeller's core from a checkout next to this one (`../wp-taxonomy-modeler`, see `autoload-dev` in `composer.json`); the release workflow clones it.

## Setup

Enter the mailbox under *Taxonomy Modeller → Configuration → Mail → Mailbox* (server, port, user, password). The
password is stored encrypted with the modeller's vault (libsodium secretbox, key derived from the WordPress auth salt in `wp-config.php`), so a
database backup alone does not reveal it; the form never shows it again. If the salts change, the page asks for it anew.

Alternatively the account can live in `wp-config.php`; these constants win over the fields:

```php
define( 'TAXMAIL_IMAP_HOST', 'imap.example.com' );
define( 'TAXMAIL_IMAP_PORT', 993 );
define( 'TAXMAIL_IMAP_USER', 'einkauf@example.com' );
define( 'TAXMAIL_IMAP_PASSWORD', '...' );
```

Then *Taxonomy Modeller → Configuration → Mail → Test connection*. It logs in, reports whether the server can move mails,
and creates the target folders that are missing.

## Use

Everything runs from *Taxonomy Modeller → Inbox*, the source «Mail»: **Fetch now**, **Sort the backlog**, **Fetch order** and
**Start over from now**. How often the schedule fetches is set here, under *Configuration → Mail → Automatic fetch*.

- The first fetch only sets a starting point: older mails are not read.
- **Sort the backlog** reads the inbox from the oldest mail, a slice per click: mails whose order is already in the model move
  to «Importiert», mails that could bring a new order become proposals, the sorting rules sort the rest, the remainder
  stays in the inbox.
- **Fetch order** reads the inbox mails that mention one order number, also from before the starting point.
- **Start over from now** forgets the open proposals, moves what was sorted away («Nicht erkannt», «Verworfen») back to the
  inbox and sets the starting point anew. «Importiert» stays.
- Taking or discarding a proposal in the inbox moves its mail to «Importiert» or «Verworfen». A move that fails is
  shown on the tab «Mail» as «Last problem».

## Extending

A mail connection is a class implementing `TaxMail\Core\Parser\OrderMailParser`, announced through the
`taxmail_parsers` filter. Which module is responsible for a shop is set in the modeller's duty table
(*Taxonomy Modeller → Configuration → Import*), not here.

## AI disclosure

This repository is created and maintained with the help of AI (Claude by Anthropic). All changes are reviewed by the owner.
