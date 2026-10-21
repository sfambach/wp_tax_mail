# src/Core

IMAP client, mail type, parser contract and registry, merging of proposals, sorting rules. The proposal types themselves are the modeller's (`Taxmod\Core\Import`, D-953) — plain PHP as well. Plain PHP 8.2, **no WordPress call** — the core
test suite loads no WordPress and fails if one drifts in. Concept:
[`docs/pakete/mail/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/mail/konzept.md).
