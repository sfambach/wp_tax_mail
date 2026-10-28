# Taxmod Mail

Mail import for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: 0.6.0 — the mailbox only (D-1006).** Since 0.6.0 every shop's mail reader lives in its own module (Taxmod eBay, JLCPCB, Amazon, AliExpress, Conrad, Mouser, Reichelt) and registers through `taxmail_parsers`; this module fetches, sorts and files the mails and keeps the mailbox settings. Without an active shop module it fetches nothing, so no mail lands in «Nicht erkannt» by mistake. The Amazon order history upload and the DHL label mails stay here for now. Needs the Taxonomy Modeller 0.1.81 or later. *Before 0.6.0:* Settings are the tab «Mail» of the modeller's Configuration page (D-945), in the order every module tab has (D-981): *Access* (mailbox, Save, Test connection, Delete access data), *Fetching* (the modeller's fetch interval), *Settings*; a «How to connect» box beside it (D-963) and the version under the tab bar (D-968). Needs the Taxonomy Modeller 0.1.49 or later; without it the module stays off and says so. Since 0.1.0 the mailbox is a *source* of the modeller's import inbox (D-952, D-953): proposals, the review page, merging, the duty table (which module brings a shop's orders, which only its status) and writing through `taxmod/write-data` are the modeller's; this module reads mails, hands over what a parser read, and sorts the mailbox. Proposals still open in the 0.0.x table are handed to the inbox once. Parsers for eBay, Reichelt, Mouser, Conrad, AliExpress and JLCPCB are ported from the Python importer in `legacy/` and checked against it on synthetic mails; Amazon (0.4.0) was written from real mails: one confirmation can hold several orders, each becomes its own proposal, shown in one box (`bundle`), and the mail stays in the inbox until all of them are in the model; shipping and delivery mails of every shop are status updates without an order date (0.2.2); their tracking number is also read from schema.org's «trackingNumber» when the text only links it (Conrad via parcelLab, 0.5.3). DHL «Online Frankierung» mails (labels bought through eBay) are no order: their cart number, date and label link are kept sealed for 30 days, handed out through the filter `taxmail_dhl_labels` (newest first, `list<array{cart, date, links}>`) for the eBay module, and the mail moves to «Importiert» (0.3.0); forwarded copies count too, and every fetch takes such mails out of «Nicht erkannt» (0.3.1). When the parsers change (a new version, one switched on), «Nicht erkannt» is read again, a slice per fetch: what a parser now reads goes back to the inbox, and a parcel service's own notice (DHL, DPD, Hermes, GLS, UPS: «Ihre Sendung kommt heute», no order number) goes to «Verworfen» (0.4.3). Amazon's «Order History.csv» (from «Meine Daten anfordern») can be uploaded in the tab for the orders from before the mails (0.5.0): one proposal per order with gross item prices, shipping, total, ship date and tracking numbers, cancelled orders left out, closed ones filed as shipped so no receipt date is invented (0.5.2), optionally only technical material (Amazon's department, or words in the product name such as «Kondensator», «Kabel», «SSD», outside books, food and clothes) and from a date on (0.5.1); the proposals are kept as JSON in `wp-content/taxmail-import/` (closed to the web, no addresses or payment data) and each fetch hands the next 100 to the inbox. Sorting rules (sender | subject | folder) and a sender count in the settings sort mails that are no order; beside each counted sender, «Save samples» writes its newest mails as text to `wp-content/taxmail-samples/` (closed to the web, «Delete samples» empties it) so a parser for a new shop can be written from real mails; a shop's mail without an order goes to «Verworfen». Connected to the Strato mailbox since 2026-10-20. German translation in `languages/`. The draft concept (German) lives with the modeller:
[`docs/pakete/mail/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/mail/konzept.md).

## Idea in short

- The modeller's schedule fetches new order mails from the purchasing mailbox over IMAP (pure PHP, no `ext-imap`).
- Each shop module brings the reader for its mails (`Taxmod\Core\Import\Mail\OrderMailParser`); this module hands every mail to the reader that recognises it.
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

A mail connection is a shop module whose reader implements the modeller's `Taxmod\Core\Import\Mail\OrderMailParser`
and is announced through the `taxmail_parsers` filter; the reader's `version()` makes «Nicht erkannt» be read again
when it changes. Which module is responsible for a shop is set in the modeller's duty table
(*Taxonomy Modeller → Configuration → Import*), not here.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.
