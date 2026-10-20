"""Conrad: Bestellt-Mail (Positionen, Versand, Summe), Versand- und Zustellmails, Rechnungen."""

from __future__ import annotations

import re

from ..mail import Mail, after, money
from ..orders import Store

PLATFORM = 'conrad'


class Conrad:
    def __init__(self, store: Store):
        self.store = store
        self.invoices: dict[str, list[Mail]] = {}

    def handle(self, mail: Mail) -> bool:
        if mail.domain != 'conrad.de':
            return False
        L, s = mail.lines, mail.subject
        t = '\n'.join(L)
        if 'Rechnung' in s:
            inv = re.search(r'\((\d{10}),', s)
            self.invoices.setdefault(inv.group(1) if inv else s, []).append(mail)
            return True
        m = re.search(r'(\d{10})\s*$', s) or re.search(r'Auftrag (\d{10})', s) or re.search(r'Bestellnummer:\n(\d{10})', t) \
            or re.search(r'Bestellung\n?\s*(\d{10})', t)
        if not m:
            return False
        o = self.store.order(PLATFORM, m.group(1))
        o['merchant'], o['currency'] = 'Conrad Electronic', 'EUR'
        if s.startswith('Bestellt'):
            for k, line in enumerate(L):
                if line.startswith('Menge:') and k > 0 and k + 2 < len(L):
                    q = int(re.search(r'\d+', line).group())
                    up = money(L[k + 1])[0] if L[k + 1].startswith('Einzelpreis') else None
                    an = re.search(r'\d+', L[k + 2]).group() if L[k + 2].startswith('Artikelnummer') else None
                    Store.add_item(o, dict(title=L[k - 1], article_no=an, qty=q, unit_price=up), True)
            o['shipping'] = money(after(L, 'Versandkosten'))[0]
            o['total'] = money(after(L, 'Summe'))[0]
            Store.add_mail(o, mail, 'confirmation')
        else:
            inv = after(L, 'Rechnungsnummer:')
            if inv:
                o['invoice'] = inv
            Store.add_mail(o, mail, 'delivered' if 'geliefert' in s or 'zugestellt' in t[:300] else 'shipped')
        return True

    def finish(self) -> None:
        for inv, mails in self.invoices.items():
            hit = [o for o in self.store.of(PLATFORM) if o.get('invoice') == inv]
            for mail in mails:
                if hit:
                    Store.add_mail(hit[0], mail, 'invoice')
