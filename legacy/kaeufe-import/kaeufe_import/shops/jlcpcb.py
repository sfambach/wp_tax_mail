"""JLCPCB (über Weiterleitung): Prüfmail «Order Review … Completed» (Platinen der Bestellung), Versandmail
«On Its Way» (Platinen mit Stückzahl, Versender, Sendungsnummer), «Ready to pick up» (Sendungsnummer) und
Nachzahlung «additional charge». Preise stehen in keiner dieser Mails."""

from __future__ import annotations

import re

from ..mail import Mail
from ..orders import Store

PLATFORM = 'jlcpcb'
ORDER = re.compile(r'\bW\d{14,16}\b')


PCS = re.compile(r'^(\d+) pcs$')
SMT = re.compile(r'^SMT\d{12}$')


def norm(name: str) -> str:
    """Platinenname ohne Dateinummer (_Y87), «gerber», Zusatz in Klammern (Reorder, replacefile, (1)) und
    Auslassung; «-» und «_» gleich — zum Zusammenführen von Prüf- und Versandmail."""
    n = re.sub(r'(\.{3}|…)$', '', name.strip())
    n = n.split('(')[0]
    n = re.sub(r'_Y\d+', '', n)
    n = re.sub(r'[_ ]?gerber', '', n, flags=re.I)
    return re.sub(r'[-_ ]+', '_', n).strip('_').lower() or name.strip().lower()


def title_of(name: str) -> str:
    if SMT.match(name):
        return f'Bestückung (SMT-Auftrag {name})'
    t = re.sub(r'(\.{3}|…)$', '', name.strip())
    t = re.sub(r'\((replacefile|Reorde\w*|-返\w*)\)?$', '', t)
    t = re.sub(r'_Y\d+', '', t)
    t = re.sub(r'[_ ]?gerber', '', t, flags=re.I).strip(' _-(')
    return f'Leiterplatte {t}' if t else f'Leiterplatte (Datei {name.strip()})'


class Jlcpcb:
    def __init__(self, store: Store):
        self.store = store

    def handle(self, mail: Mail) -> bool:
        L, s = mail.lines, mail.subject
        text = ' '.join(L)
        m = ORDER.search(s) or ORDER.search(text)
        if not m:
            return False
        if 'Review' in s:
            o = self.order(m.group(0))
            start = next((k for k, x in enumerate(L) if '【' in x), None)
            if start is not None:
                for k in range(start + 1, len(L) - 1):
                    if L[k + 1] in ('Approved', 'Failed', 'Audit Failed') and not L[k].startswith('View order'):
                        self.add(o, L[k], None)
            Store.add_mail(o, mail, 'confirmation')
            return True
        if 'On Its Way' in s:
            o = self.order(m.group(0))
            t = re.search(r'pick up by (.{0,40}?)\.\s*Tracking Number:\s*(\S+)', text)
            if t:
                o['carrier'] = t.group(1).strip()
                if t.group(2) not in o['tracking']:
                    o['tracking'].append(t.group(2))
            start = next((k for k, x in enumerate(L) if 'Items in this shipment' in x), None)
            if start is not None:
                unnamed = []   # «N pcs» ohne Namen davor: die bestückten Platinen der SMT-Aufträge, in deren Reihenfolge
                for k in range(start + 1, len(L)):
                    if L[k].startswith('View order'):
                        break
                    q = PCS.match(L[k])
                    if q and PCS.match(L[k - 1] if k - 1 > start else ''):
                        unnamed.append(int(q.group(1)))
                    elif q and k - 1 > start:
                        self.add(o, L[k - 1], int(q.group(1)))
                    elif q:
                        unnamed.append(int(q.group(1)))
                if unnamed:
                    o['_smt_qty'] = unnamed
            Store.add_mail(o, mail, 'shipped')
            return True
        if 'pick up' in s:
            o = self.order(m.group(0))
            t = re.search(r'tracking number is (\w+)\.?\s*\((\w+)\)', text)
            if t:
                o['carrier'] = o['carrier'] or t.group(2)
                if t.group(1) not in o['tracking']:
                    o['tracking'].append(t.group(1))
            Store.add_mail(o, mail, 'other')
            return True
        return False

    def order(self, no: str) -> dict:
        o = self.store.order(PLATFORM, no)
        o['merchant'] = 'JLCPCB'
        return o

    @staticmethod
    def add(o: dict, name: str, qty: int | None) -> None:
        key = norm(name)
        if not key:
            return
        for it in o['items']:
            k2 = norm(it['_name'])
            if k2 == key or k2.startswith(key) or key.startswith(k2):
                if qty is not None:
                    it['qty'] = qty
                if len(name) > len(it['_name']) and not name.endswith(('...', '…')):
                    it['_name'], it['title'] = name, title_of(name)
                return
        o['items'].append(dict(title=title_of(name), article_no=None, qty=qty, unit_price=None, _name=name))

    def finish(self) -> None:
        for o in self.store.of(PLATFORM):
            smt = [i for i in o['items'] if SMT.match(i['_name'])]
            for it, n in zip(smt, o.pop('_smt_qty', [])):
                it['qty'] = n
            for it in o['items']:
                it.pop('_name', None)
            o['notes'].append('JLCPCB-Mails nennen keine Preise.')
