"""Bestellmails aus samples/*.eml (nur lokal) zu samples/orders.json — je Shop ein Modul, ein Vorgang je Plattform + Bestellnummer.

Werbung, Suchagenten und alles ohne Modul wird gezählt und liegen gelassen. Apotheke und PayPal werden bewusst nicht
gelesen (privat). Vom Käufer eines Verkaufs bleibt nur der eBay-Name; Anschriften werden nicht übernommen.
"""

from __future__ import annotations

import argparse
import collections
import json
from pathlib import Path

from .mail import load
from .orders import Store
from .shops.aliexpress import AliExpress
from .shops.conrad import Conrad
from .shops.ebay import Ebay
from .shops.jlcpcb import Jlcpcb
from .shops.mouser import Mouser
from .shops.reichelt import Reichelt

SAMPLES = Path(__file__).resolve().parent.parent / "samples"
PRIVATE = ('apodiscounter', 'paypal')


def run(source: Path, target: Path) -> collections.Counter:
    store = Store()
    shops = {'ebay': Ebay(store), 'mouser': Mouser(store), 'reichelt': Reichelt(store),
             'conrad': Conrad(store), 'aliexpress': AliExpress(store), 'jlcpcb': Jlcpcb(store)}
    counts: collections.Counter = collections.Counter()
    for path in sorted(source.glob('*.eml')):
        mail = load(path)
        if any(p in mail.domain for p in PRIVATE):
            counts['privat, nicht gelesen'] += 1
            continue
        shop = next((s for key, s in shops.items() if key in mail.domain), None)
        if shop is not None and shop.handle(mail):
            counts['verarbeitet'] += 1
        else:
            counts['Werbung / ohne Modul'] += 1
    for shop in shops.values():
        shop.finish()
    result = store.result()
    target.write_text(json.dumps(result, ensure_ascii=False, indent=1), encoding='utf-8')
    counts['Vorgänge'] = len(result)
    return counts


def main() -> None:
    args = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    args.add_argument('--source', type=Path, default=SAMPLES)
    args.add_argument('--target', type=Path, default=SAMPLES / 'orders.json')
    options = args.parse_args()
    for key, n in run(options.source, options.target).items():
        print(f"{n:5d}  {key}")
    print(f"→ {options.target}")


if __name__ == '__main__':
    main()
