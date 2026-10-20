"""eBay: Käufe (Bestätigung, Versand, Zustellung, Rückerstattung, gewonnene Auktion, Rechnung) und Verkäufe."""

from __future__ import annotations

import re
from datetime import datetime

from ..mail import Mail, after, iso, money
from ..orders import Store

PLATFORM = 'ebay'
DOMAINS = ('ebay.com', 'ebay.de')

ITEM_LABELS = {'Preis:', 'Artikelnr.:', 'Lieferung ca.:'}
ORDER_NO = re.compile(r'\d{2}-\d{5}-\d{5}$')
ADVERT = re.compile(r'Treffer für|Ergebnis|Only \d+ match|gespeicherten Verkäufer|Rabatt|Gutschein|Coupon|CODE|Wunschartikel|'
                    r'günstiger|Kürzlich angesehen|Schlagen Sie|gehört fast|Konkurrenzgebot|Interesse|eingestellt|Erfahrung|'
                    r'Preisvorschlag gesendet|Ausverkauf|Festival|Passend zu|Boost|Newsletter', re.I)


class Ebay:
    def __init__(self, store: Store):
        self.store = store
        self.pending: list[dict] = []   # gewonnene Auktionen / Rechnungen ohne Bestellnummer

    def handle(self, mail: Mail) -> bool:
        s = mail.subject
        if re.search(r'Artikel verkauft|Geld erhalten für', s):
            return self.sale(mail)
        if 'Bestellnummer:' in mail.lines and not ADVERT.search(s):
            return self.purchase(mail)
        return self.other(mail)

    # ------------------------------------------------------------ Käufe
    @staticmethod
    def mail_type(subject: str) -> str:
        s = subject.lower()
        if 'rückerstattung veranlasst' in s:
            return 'refund'
        if 'zugestellt' in s or 'geliefert' in s:
            return 'delivered'
        if any(w in s for w in ('zustellung', 'update zu', 'versanddienstleister', 'sendung ist jetzt', 'verschickt')):
            return 'shipped'
        if 'bestätigt' in s:
            return 'confirmation'
        return 'other'

    def purchase(self, mail: Mail) -> bool:
        L = mail.lines
        mtype = self.mail_type(mail.subject)
        prev_end = 0
        found = []
        for i in [i for i, line in enumerate(L) if line == 'Bestellnummer:']:
            no = L[i + 1]
            if not ORDER_NO.match(no):
                continue
            # Der Block einer Position: Titel, dann Preis / Artikelnr. / Lieferung, je mit Wert(en) darunter.
            x = i
            while True:
                back = [y for y in (x - 2, x - 3) if y > prev_end and L[y] in ITEM_LABELS and L[x - 1] not in ITEM_LABELS]
                if not back:
                    break
                x = back[0]
            tx = x - 1
            while tx > prev_end and L[tx] in ('eBay-Käuferschutz', 'Deals ansehen', 'Bestellung nachverfolgen'):
                tx -= 1
            title = L[tx] if tx >= prev_end else None
            block = L[x:i]
            art = after(block, 'Artikelnr.:')
            price = after(block, 'Preis:')
            qty, unit, cur = 1, None, None
            if price:
                m = re.search(r'\((\d+)\s*x\s*(.+?)\)', price)
                if m:
                    qty = int(m.group(1))
                    unit, cur = money(m.group(2))
                else:
                    unit, cur = money(price)
            variant, seller, j = [], None, i + 2
            while j < min(len(L), i + 40):
                if L[j] == no:
                    j += 1
                    continue
                if L[j] == 'Verkäufer:':
                    seller = L[j + 1].strip(' …')
                    j += 2
                    break
                if L[j].endswith(':') and j + 1 < len(L):
                    variant.append(f"{L[j][:-1]}: {L[j + 1]}")
                    j += 2
                    while j < len(L) and L[j] == L[j - 1]:
                        j += 1
                    continue
                j += 1
            prev_end = j
            o = self.store.order(PLATFORM, no)
            if seller and (not o['merchant'] or '…' in o['merchant']):
                o['merchant'] = seller
            if title and title not in ITEM_LABELS and mtype != 'refund':
                it = dict(title=title, article_no=art, qty=qty, unit_price=unit)
                if variant:
                    it['variant'] = '; '.join(variant)
                # Positionen aus der Bestätigung gelten; die übrigen nur, wenn keine Bestätigung kommt.
                o.setdefault('_items', {}).setdefault('conf' if mtype == 'confirmation' and unit is not None else 'other', []).append(it)
            if cur:
                o['currency'] = cur
            found.append(o)

        sub, ship, tot, paid, fees, disc, tcur = self.totals(L)
        tracking = after(L, 'Sendungsnummer:')
        if not tracking:
            mt = re.search(r'Sendungsnummer:\s*([A-Z0-9]{8,})', mail.text)
            tracking = mt.group(1) if mt else None
        carrier = after(L, 'Versanddienstleister:')
        uniq = list({id(o): o for o in found}.values())
        for o in uniq:
            Store.add_mail(o, mail, mtype)
            if tracking and re.match(r'[A-Z0-9]{8,}$', tracking) and tracking not in o['tracking']:
                o['tracking'].append(tracking)
            if carrier:
                o['carrier'] = carrier
            if mtype == 'refund':
                o['refund'] = money(after(L, 'Rückerstattung gesamt'))[0]
            elif tot is not None:
                if len(uniq) == 1:
                    o['shipping'], o['total'], o['fees'] = ship, tot, fees or None
                    if tcur:
                        o['currency'] = tcur
                    if tcur and tcur != 'EUR' and paid is not None:
                        o['charged_eur'] = paid
                else:
                    # Sammelkauf: die Mail nennt nur die Summe der Kasse.
                    o['checkout'] = dict(orders=[u['order_no'] for u in uniq], subtotal=sub, shipping=ship,
                                         fees=fees or None, total=tot)
        return bool(uniq)

    @staticmethod
    def totals(L: list[str]):
        """Zwischensumme, Versand, Kaufpreis gesamt, abgebucht, Gebühren, Rabatt, Währung — aus dem Block «Gesamtbetrag:»."""
        sub = ship = tot = paid = tcur = None
        fees: dict[str, float] = {}
        disc = 0.0
        if 'Gesamtbetrag:' not in L:
            return sub, ship, tot, paid, fees, disc, tcur
        seg = L[L.index('Gesamtbetrag:') + 1:]
        for k in range(len(seg) - 1):
            lab, val = seg[k], seg[k + 1].replace('−', '-')
            if lab.startswith(('eBay-Käuferschutz', 'Mit dem', 'Ihr eBay', 'E-Mail-')):
                break
            v, c = money(val)
            if val.strip() == 'Kostenlos':
                v = 0.0
            if v is None:
                continue
            if val.strip().startswith('-') and v > 0:
                v = -v
            if lab.startswith('Zwischensumme'):
                sub, tcur = v, c
            elif lab == 'Versand':
                ship = v
            elif lab.startswith('Gutscheine'):
                disc += v
            elif lab.startswith(('Mehrwertsteuer', 'Einfuhrabgaben', 'Import', 'Zoll')):
                fees[lab] = v
            elif lab.startswith('Gesamtbetrag wird abgebucht') or lab == 'Gesamt':
                paid = v
            # «Guthaben» ist eine Zahlungsart, kein Preis.
        if sub is not None:
            tot = round(sub + (ship or 0) + sum(fees.values()) + disc, 2)
            if disc:
                fees['Gutschein/Rabatt'] = round(disc, 2)
        return sub, ship, tot, paid, fees, disc, tcur

    # ------------------------------------------------------------ Verkäufe
    def sale(self, mail: Mail) -> bool:
        L = mail.lines
        no = after(L, 'Bestellung:')
        if not no:
            return False
        o = self.store.order(PLATFORM, no, 'sale')
        o['kind'] = 'sale'   # auch wenn eine andere Mail (Anfrage, Fall) den Vorgang zuerst angelegt hat
        o['buyer'] = after(L, 'Käufer:')   # nur der eBay-Name, keine Anschrift
        title = re.sub(r'^\W*(Artikel verkauft - |Sie haben Ihr Geld erhalten für )', '', mail.subject).strip()
        qty = int(after(L, 'Verkaufte Stückzahl:') or 1)
        sold, cur = money(after(L, 'Verkauft:'))
        ship = money(after(L, 'Versand:'))[0]
        unit = round(sold / qty, 2) if sold is not None else None
        # Ein Sammelverkauf schickt je Artikel eine Mail mit derselben Bestellnummer: gleicher Titelanfang = derselbe Artikel.
        head = title.rstrip('.… ').lower()[:20]
        ex = next((i for i in o['items'] if i['title'].rstrip('.… ').lower()[:20] == head), None)
        if ex is not None:
            if len(title) > len(ex['title']) and not title.endswith(('...', '…')):
                ex['title'] = title
            if unit is not None:
                ex['unit_price'], ex['qty'] = unit, qty
        else:
            o['items'].append(dict(title=title, article_no=None, qty=qty, unit_price=unit))
        o['currency'] = cur or o['currency']
        if ship is not None:
            o['shipping'] = ship
        if sold is not None:
            items_sum = sum((i['unit_price'] or 0) * i['qty'] for i in o['items'])
            o['total'] = round(items_sum + (o['shipping'] or 0), 2)
        if not o['ordered']:
            o['ordered'] = iso(mail.date)
        Store.add_mail(o, mail, 'sold' if 'verkauft' in mail.subject else 'paid')
        return True

    # ------------------------------------------------------------ ohne Bestellnummer
    def other(self, mail: Mail) -> bool:
        s = mail.subject
        if s.startswith(('Sie haben den Artikel gekauft', 'Preisvorschlag akzeptiert', 'Aktion erforderlich: Bezahlen')):
            bid = re.search(r'(?:Höchstgebot|Preisvorschlag|Betrag)[^:\n]*:\s*(EUR [\d.,]+)', mail.text)
            price = money(bid.group(1))[0] if bid else money(s)[0]
            seller = re.search(r'Verkäufer:\s*([^,\n]+)', mail.text)
            m = re.search(r'um (.+?) zu erhalten', s) or re.search(r'Kauf (?:von|für) (.+)', s)
            self.pending.append(dict(title=(m.group(1) if m else s)[:120], price=price,
                                     seller=seller.group(1).strip() if seller else None, mail=mail))
            return True
        if s.startswith('Ihre Rechnung für den eBay-Kauf'):
            m = re.search(r'\n(\d{12})\n(.+)\n(\d+)\n(EUR [\d.,]+)\n(EUR [\d.,]+)', '\n' + '\n'.join(mail.lines))
            if m:
                self.pending.append(dict(title=m.group(2), price=money(m.group(4))[0], qty=int(m.group(3)),
                                         article_no=m.group(1), seller=None, mail=mail,
                                         total=money(after(mail.lines, 'Gesamt:'))[0]))
            return True
        return False

    def finish(self) -> None:
        for o in self.store.orders.values():
            src = o.pop('_items', None)
            if src is None:
                continue
            if o['kind'] == 'sale':
                # Eine Mail mit «Bestellnummer:» zu einem Verkauf: ihr Titel nur, wenn die Verkaufsmail keinen gab.
                for it in src.get('conf', []) + src.get('other', []):
                    if o['items'] and len(it['title']) > len(o['items'][0]['title']) and not it['title'].endswith(('...', '…')):
                        o['items'][0]['title'] = it['title']
                    elif not o['items']:
                        o['items'].append({k: v for k, v in it.items() if k != 'variant'})
                continue
            o['items'] = dedupe(src.get('conf') or src.get('other', []))
        for p in self.pending:
            hit = self.match(p)
            if hit:
                Store.add_mail(hit, p['mail'], 'won_or_invoice')
                if len(hit['items']) == 1 and hit['items'][0].get('unit_price') is None and p['price'] is not None:
                    hit['items'][0]['unit_price'] = p['price']
                if p.get('total') and hit['total'] is None:
                    # Die Rechnung nennt den Betrag dieser einen Bestellung samt Versand.
                    hit['total'] = p['total']
                    items_sum = sum((i.get('unit_price') or 0) * i['qty'] for i in hit['items'])
                    if items_sum and p['total'] >= items_sum:
                        hit['shipping'] = round(p['total'] - items_sum, 2)
                continue
            key = 'ohne-Nr:' + (p.get('article_no') or (p['seller'] or '?') + ':' + p['title'][:30])
            o = self.store.order(PLATFORM, key)
            o['merchant'], o['currency'], o['ordered'] = p['seller'], 'EUR', iso(p['mail'].date)
            o['notes'].append('keine Bestellnummer in der Mail (Auktion gewonnen / Rechnung)')
            o['items'].append(dict(title=p['title'], article_no=p.get('article_no'), qty=p.get('qty', 1), unit_price=p['price']))
            if p.get('total'):
                o['total'] = p['total']
            Store.add_mail(o, p['mail'], 'won_or_invoice')

    def match(self, p: dict) -> dict | None:
        for o in self.store.of(PLATFORM):
            if p.get('article_no') and any(i.get('article_no') == p['article_no'] for i in o['items']):
                return o
            pt = p['title'].rstrip('.… ').lower()[:15]
            dates = [m['date'] for m in o['source_mails'] if m['date']]
            start = o.get('ordered') or (min(dates) if dates else None)
            if start and p['mail'].date and any(i['title'].lower()[:15] == pt for i in o['items']) and \
                    abs((datetime.fromisoformat(start) - p['mail'].date).days) <= 14:
                return o
        return None


def dedupe(items: list[dict]) -> list[dict]:
    out: list[dict] = []
    for it in items:
        for ex in out:
            if ex.get('article_no') == it.get('article_no') and ex.get('variant') == it.get('variant') and \
                    (it.get('article_no') or ex['title'][:25] == it['title'][:25]):
                if len(it['title']) > len(ex['title']) and not it['title'].endswith(('...', '…')):
                    ex['title'] = it['title']
                break
        else:
            out.append(dict(it))
    return out
