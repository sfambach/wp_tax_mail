"""Mouser: Auftragsbestätigung (Positionen, Warenwert, Versand, MwSt) und Versandbenachrichtigung (Sendungsnummer)."""

from __future__ import annotations

import re

from ..mail import Mail, after, num
from ..orders import Store

PLATFORM = 'mouser'
PART = re.compile(r'^\d{2,3}-[A-Z0-9][A-Z0-9\-./+#]*$')
DATE = re.compile(r'^[A-Z]{3} \d{1,2}, \d{4}$')
SUMS = (('Merchandise Total', 'sub'), ('Warenwert', 'sub'), ('Gesamtwarenwert', 'sub'), ('Shipping', 'ship'),
        ('Versand', 'ship'), ('VAT', 'vat'), ('MwSt', 'vat'), ('Mehrwertsteuer', 'vat'))


class Mouser:
    def __init__(self, store: Store):
        self.store = store

    def handle(self, mail: Mail) -> bool:
        if 'enews' in mail.domain:
            return False
        L = mail.lines
        m = re.search(r'(?:order|Bestellung|Purchase Order)\s+(\d{8})', mail.subject) or \
            re.search(r'(?:Web Order Number|Purchase Order Number|Web-Bestellnummer|Bestellnummer)[^\n]*\n:?\s*(\d{8})', '\n'.join(L))
        if not m:
            return False
        o = self.store.order(PLATFORM, m.group(1))
        o['merchant'], o['currency'] = 'Mouser Electronics', 'EUR'
        s = mail.subject.lower()
        mtype = 'shipped' if ('shipment' in s or 'versand' in s) else 'cancellation' if 'cancel' in s else \
            'confirmation' if ('confirmation' in s or 'bestätigung' in s) else 'other'
        prev = ''
        for k, line in enumerate(L):
            # Die Herstellernummer unter der Mouser-Nummer (645-566-0206F → 566-0206F) ist keine neue Position.
            if PART.match(line) and k + 4 < len(L) and not prev.endswith('-' + line):
                prev = line
                rest = [x for x in L[k + 1:k + 12] if not DATE.match(x)]
                nums = [x for x in rest[2:] if re.match(r'^[\d.,]+$', x)]
                if len(nums) >= 3:
                    Store.add_item(o, dict(title=f"{rest[0]} {rest[1]}".strip(), article_no=line,
                                           qty=int(num(nums[0])), unit_price=num(nums[1])), mtype == 'confirmation')
        vals: dict[str, float] = {}
        for k, line in enumerate(L):
            for label, key in SUMS:
                if line.startswith(label) and key not in vals:
                    for x in L[k + 1:k + 3]:
                        if re.match(r'^-?[\d.,]+$', x):
                            vals[key] = num(x)
                            break
        if 'sub' in vals and mtype == 'confirmation':
            o['shipping'] = vals.get('ship')
            o['fees'] = {'VAT': vals['vat']} if 'vat' in vals else None
            o['total'] = round(vals['sub'] + vals.get('ship', 0) + vals.get('vat', 0), 2)
        tracking = after(L, 'Tracking Number') or after(L, 'Sendungsnummer')
        if tracking and tracking not in o['tracking']:
            o['tracking'].append(tracking)
        Store.add_mail(o, mail, mtype)
        return True

    def finish(self) -> None:
        pass
