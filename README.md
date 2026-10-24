# Taxmod Mail

Mail import for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: 0.3.1.** Settings are the tab «Mail» of the modeller's Configuration page (D-945), in the order every module tab has (D-981): *Access* (mailbox, Save, Test connection, Delete access data), *Fetching* (the modeller's fetch interval), *Settings*; a «How to connect» box beside it (D-963) and the version under the tab bar (D-968). Needs the Taxonomy Modeller 0.1.49 or later; without it the module stays off and says so. Since 0.1.0 the mailbox is a *source* of the modeller's import inbox (D-952, D-953): proposals, the review page, merging, the duty table (which module brings a shop's orders, which only its status) and writing through `taxmod/write-data` are the modeller's; this module reads mails, hands over what a parser read, and sorts the mailbox. Proposals still open in the 0.0.x table are handed to the inbox once. Parsers for eBay, Reichelt, Mouser, Conrad, AliExpress and JLCPCB are ported from the Python importer in `legacy/` and checked against it on synthetic mails; shipping and delivery mails of every shop are status updates without an order date (0.2.2). DHL «Online Frankierung» mails (labels bought through eBay) are no order: their cart number, date and label link are kept sealed for 30 days, handed out through the filter `taxmail_dhl_labels` (newest first, `list<array{cart, date, links}>`) for the eBay module, and the mail moves to «Importiert» (0.3.0); forwarded copies count too, and every fetch takes such mails out of «Nicht erkannt» (0.3.1). Sorting rules (sender | subject | folder) and a sender count in the settings sort mails that are no order; beside each counted sender, «Save samples» writes its newest mails as text to `wp-content/taxmail-samples/` (closed to the web, «Delete samples» empties it) so a parser for a new shop can be written from real mails; a shop's mail without an order goes to «Verworfen». Connected to the Strato mailbox since 2026-10-20. German translation in `languages/`. The draft concept (German) lives with the modeller:
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

Enter the mailbox under *Taxonomy Modeller → Configuration → Mail → Access* (server, port, user, password); *Delete access data* removes them after asking. The
password is stored encrypted with the modeller's vault (libsodium secretbox, key derived from the WordPress auth salt in `wp-config.php`), so a
database backup alone does not reveal it; the form never shows it again. If the salts change, the page asks for it anew.

Alternatively the account can live in `wp-config.php`; these constants win over the fields:

```php
define( 'TAXMAIL_IMAP_HOST', 'imap.example.com' );
define( 'TAXMAIL_IMAP_PORT', 993 );
define( 'TAXMAIL_IMAP_USER', 'einkauf@example.com' );
define( 'TAXMAIL_IMAP_PASSWORD', '...' );
```

Then *Taxonomy Modeller → Configuration → Mail → Access → Test connection*. It saves what was entered, logs in, reports whether the server can move mails,
and creates the target folders that are missing.

## Use

Everything runs from *Taxonomy Modeller → Inbox*, the source «Mail»: **Fetch now**, **Sort the backlog**, **Fetch order** and
**Start over from now**. How often the schedule fetches is set under *Configuration → Mail → Fetching*; a 0.1.x choice is carried over once.

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
