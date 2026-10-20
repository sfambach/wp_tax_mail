"""AliExpress: Mails zu einer Bestellnummer — bisher nur «auf Bestätigung wird gewartet» (Titel, Anzahl, Bestellsumme)."""

from __future__ import annotations

import re

from ..mail import Mail, after, money
from ..orders import Store

PLATFORM = 'aliexpress'


class AliExpress:
    def __init__(self, store: Store):
        self.store = store

    def handle(self, mail: Mail) -> bool:
        L = mail.lines
        m = re.search(r'Bestellung (\d{15,20})', mail.subject + ' ' + ' '.join(L[:20]))
        if not m:
            return False
        o = self.store.order(PLATFORM, m.group(1))
        o['currency'] = 'EUR'
        for k, line in enumerate(L):
            if re.match(r'^x(\d+)$', line) and k > 1:
                title = L[k - 1] if not L[k - 1].startswith('$') else L[k - 2]
                Store.add_item(o, dict(title=title, article_no=None, qty=int(line[1:]), unit_price=None), False)
        total = after(L, 'Bestellsumme')
        if total:
            o['total'] = money(total)[0]
        Store.add_mail(o, mail, 'awaiting_receipt_confirmation' if 'Bestätigung' in mail.subject else 'other')
        return True

    def finish(self) -> None:
        pass
