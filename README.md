# Taxmod Mail

Mail import for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: concept, nothing built.** The draft concept (German) lives with the modeller:
[`docs/pakete/mail/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/mail/konzept.md).

## Idea in short

- A WP-Cron job fetches new order mails from the purchasing mailbox over IMAP (pure PHP library, no `ext-imap`).
- One parser per shop (eBay, Mouser, Reichelt, Conrad, AliExpress, JLCPCB) turns a mail into an order proposal.
- Proposals are stored by this module, not in the model. The owner reviews them and picks the line items to import.
- Only the picked items are written, through the modeller's `taxmod/write-data` path.
- Independent of the stock module; it only fires WordPress actions that other modules may listen to.

## AI disclosure

This repository is created and maintained with the help of AI (Claude by Anthropic). All changes are reviewed by the owner.
