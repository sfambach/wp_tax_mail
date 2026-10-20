"""Die gesammelten Vorgänge: je Plattform + Bestellnummer einer, ergänzt von jeder Mail, die dazu kommt."""

from __future__ import annotations

from .mail import Mail, iso


class Store:
    def __init__(self) -> None:
        self.orders: dict[tuple[str, str], dict] = {}

    def order(self, platform: str, no: str, kind: str = 'purchase') -> dict:
        key = (platform, no)
        if key not in self.orders:
            self.orders[key] = dict(platform=platform, order_no=no, kind=kind, merchant=None, buyer=None,
                                    ordered=None, shipped=None, received=None, items=[], shipping=None, fees=None,
                                    total=None, currency=None, tracking=[], carrier=None, refund=None,
                                    mail_types=[], source_mails=[], notes=[])
        return self.orders[key]

    def of(self, platform: str, kind: str = 'purchase'):
        return [o for o in self.orders.values() if o['platform'] == platform and o['kind'] == kind]

    @staticmethod
    def add_mail(o: dict, mail: Mail, mtype: str) -> None:
        o['source_mails'].append(dict(subject=mail.subject[:120], date=iso(mail.date), type=mtype))
        if mtype not in o['mail_types']:
            o['mail_types'].append(mtype)
        if mtype == 'confirmation' and mail.date and (not o['ordered'] or mail.date.isoformat() < o['ordered']):
            o['ordered'] = iso(mail.date)
        if mtype == 'shipped' and mail.date and not o['shipped']:
            o['shipped'] = iso(mail.date)
        if mtype == 'delivered' and mail.date:
            o['received'] = iso(mail.date)

    @staticmethod
    def add_item(o: dict, it: dict, priced_source: bool) -> None:
        """Eine Position; dieselbe (Artikelnummer bzw. Titel + Variante) wird ergänzt, nicht verdoppelt."""
        key = (it.get('article_no'), it['title'][:40], it.get('variant'))
        for ex in o['items']:
            if (ex.get('article_no'), ex['title'][:40], ex.get('variant')) == key or \
               (it.get('article_no') and ex.get('article_no') == it.get('article_no') and ex.get('variant') == it.get('variant')):
                for f in ('unit_price', 'qty'):
                    if priced_source and it.get(f) is not None:
                        ex[f] = it[f]
                if len(it['title']) > len(ex['title']) and not it['title'].endswith(('...', '…')):
                    ex['title'] = it['title']
                return
        o['items'].append(it)

    def result(self) -> list[dict]:
        out = []
        for o in self.orders.values():
            o['source_mails'].sort(key=lambda m: m['date'] or '')
            priced = bool(o['items']) and all(i.get('unit_price') is not None for i in o['items'])
            o['prices_complete'] = priced and (o['total'] is not None or bool(o.get('checkout')))
            out.append({k: v for k, v in o.items() if v not in (None, [], {}) or k in ('items', 'tracking')})
        return sorted(out, key=lambda o: (o.get('ordered') or (o['source_mails'][0]['date'] if o['source_mails'] else '') or ''))
