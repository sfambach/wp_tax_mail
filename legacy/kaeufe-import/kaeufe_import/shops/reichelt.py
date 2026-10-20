"""reichelt: Bestellbestätigung (zwei Formate), Versandinfo (Sendungsnummer), Kundenservice zu einem Auftrag."""

from __future__ import annotations

import re

from ..mail import Mail, after, money
from ..orders import Store

PLATFORM = 'reichelt'
NUMBER = re.compile(r'Bearbeitungsnummer I-(\d{6})|Bestellung I-(\d{6})|I-Net-Nummer (\d{6})|I-Net Nummer[^\n]*\n[^\n]*\n(\d{6})')


class Reichelt:
    def __init__(self, store: Store):
        self.store = store

    def handle(self, mail: Mail) -> bool:
        L = mail.lines
        t = '\n'.join(L)
        m = NUMBER.search(mail.subject + '\n' + t)
        if not m:
            a = re.search(r'Auftrag:?\s*(\d{7})', mail.subject)
            if not a:
                return False
            o = self.store.order(PLATFORM, 'Auftrag ' + a.group(1))
            o['merchant'] = 'reichelt elektronik'
            Store.add_mail(o, mail, 'other')
            return True
        o = self.store.order(PLATFORM, 'I-' + next(g for g in m.groups() if g))
        o['merchant'], o['currency'] = 'reichelt elektronik', 'EUR'
        if 'versandinfo' in mail.sender:
            tr = re.search(r'Sendungsnummer lautet (\w+)', t)
            if tr and tr.group(1) not in o['tracking']:
                o['tracking'].append(tr.group(1))
            carrier = re.search(r'Logistik-\s*Partner (\w+)', t)
            o['carrier'] = carrier.group(1) if carrier else None
            Store.add_mail(o, mail, 'shipped')
            return True
        if 'Bestellübersicht' in L:
            # Artikelnummer / Text / Anzahl / Einzelpreis
            k = L.index('Bestellübersicht') + 1
            while k + 3 < len(L) and L[k] != 'Zwischensumme':
                if re.match(r'^\d+$', L[k + 2]) and money(L[k + 3])[0] is not None:
                    Store.add_item(o, dict(title=L[k + 1], article_no=L[k], qty=int(L[k + 2]), unit_price=money(L[k + 3])[0]), True)
                    k += 4
                else:
                    k += 1
            o['shipping'] = money(after(L, 'Versandkosten*'))[0]
            o['total'] = money(after(L, 'Gesamtpreis'))[0]
        elif 'Ihre bestellten Artikel:' in L:
            # älteres Format: Artikelnummer / Text / Anzahl / Gesamtpreis der Zeile (netto darunter)
            k = L.index('Ihre bestellten Artikel:') + 5
            while k + 3 < len(L):
                if re.match(r'^\d+$', L[k + 2]) and money(L[k + 3])[0] is not None:
                    q, line_total = int(L[k + 2]), money(L[k + 3])[0]
                    Store.add_item(o, dict(title=L[k + 1], article_no=L[k], qty=q, unit_price=round(line_total / q, 4)), True)
                    k += 4
                    if k < len(L) and 'netto' in L[k]:
                        k += 1
                else:
                    k += 1
                    if L[k - 1].startswith(('Versand', 'Gesamt', 'Summe', 'Zwischensumme')):
                        break
            for k2, line in enumerate(L):
                nxt = money(L[k2 + 1])[0] if k2 + 1 < len(L) else None
                if line.startswith('Versand') and o['shipping'] is None and nxt is not None:
                    o['shipping'] = nxt
                if re.match(r'^(Gesamtbetrag|Gesamtpreis|Gesamtsumme|Summe)', line) and nxt is not None:
                    o['total'] = nxt
        d = re.search(r'Bestellung vom (\d{1,2})\.(\d{1,2})\.(\d{4})', mail.subject)
        if d:
            o['ordered'] = f"{d.group(3)}-{int(d.group(2)):02d}-{int(d.group(1)):02d}"
        Store.add_mail(o, mail, 'confirmation')
        return True

    def finish(self) -> None:
        pass
