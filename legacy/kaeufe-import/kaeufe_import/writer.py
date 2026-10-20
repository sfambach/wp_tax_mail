"""Bestellungen und Verkäufe aus samples/orders.json in den Taxonomy Modeller schreiben.

Vorgabe ist der Probelauf (--dry-run): die Änderungslisten landen in samples/plan/*.json, dazu eine Zählung.
Erst --write schickt sie an fambach.net — je Bestellung bzw. Verkauf ein Aufruf von `taxmod/write-data`
(alles oder nichts), mindestens 5 s Pause dazwischen, Abbruch beim ersten Fehler, Ids nach samples/written.log.

Regeln (D-933, D-934, Übergabe 2026-10-18):
- jeder Satz mit Bestellnummer; doppelt ist, was Plattform + Bestellnummer schon trägt, oder eine Bestellung, deren
  Artikelnummern alle schon unter «Käufe» stehen (dort fehlt bei alten Sätzen die Bestellnummer) — beides wird
  übersprungen und gemeldet;
- Einstandspreis je Stück = Stückpreis + Anteil an Versand, Zoll und Gebühren, verteilt nach dem Preis der Position;
- Herkunft «belegt», Herkunftshinweis nennt die Quell-Mails; vom Käufer nur der eBay-Name;
- Sammelkäufe (eine Kassensumme für mehrere Bestellungen): keine erfundenen Einzelsummen, nur ein Hinweis.

Zugangsdaten: Umgebung TAXMOD_URL / TAXMOD_USER / TAXMOD_APP_PASSWORD oder ~/.config/kaeufe-import/wp.ini
(Vorlage wp.ini.example, chmod 600) — nie im Projekt.
"""

from __future__ import annotations

import argparse
import base64
import configparser
import datetime as dt
import html
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SAMPLES = ROOT / "samples"
CONFIG_PATH = Path.home() / ".config" / "kaeufe-import" / "wp.ini"

NODE_ORDERS = 149000110051      # Bestellungen
NODE_PURCHASES = 149000109585   # Käufe (Positionen einer Bestellung)
NODE_SALES = 149000110053       # Verkäufe
NODE_SOLD = 149000110052        # Verkaufte Artikel (Positionen eines Verkaufs)
NODE_UNIT_VALUE = 4232          # Einheitenwert (Betrag + Einheit)
NODE_CURRENCY = 8569            # Currency
HERKUNFT_BELEGT = "149000108192"

CURRENCY_NAMES = {"EUR": "Euro", "USD": "US Dollar", "GBP": "Pound", "AUD": "Australian Dollar"}
PLATFORM_NAMES = {"ebay": "Ebay", "reichelt": "Reichelt", "mouser": "Mouser", "conrad": "Conrad", "aliexpress": "AliExpress", "jlcpcb": "JLCPCB"}
PLATFORM_SHORT = {"ebay": "eBay", "reichelt": "Reichelt", "mouser": "Mouser", "conrad": "Conrad", "aliexpress": "AliExpress", "jlcpcb": "JLCPCB"}

FIELDS = {
    NODE_ORDERS: ["Bezeichnung", "Herkunft", "Herkunftshinweis", "Bestellnummer", "Plattform", "Händler", "Bestellt am",
                  "Eingegangen am", "Versandkosten", "Zoll / Gebühren", "Gesamtbetrag", "Sendungsnummer", "Positionen"],
    NODE_PURCHASES: ["Bezeichnung", "Herkunft", "Herkunftshinweis", "Anzahl", "Preis je Stück", "Preis gesamt",
                     "Artikelnummer", "Einstandspreis je Stück"],
    NODE_SALES: ["Bezeichnung", "Herkunft", "Herkunftshinweis", "Bestellnummer", "Plattform", "Käufer", "Verkauft am",
                 "Versand erhalten", "Gesamtbetrag", "Sendungsnummer", "Positionen"],
    NODE_SOLD: ["Bezeichnung", "Herkunft", "Herkunftshinweis", "Artikelnummer", "Anzahl", "Verkaufspreis je Stück"],
    NODE_UNIT_VALUE: ["wert", "einheit"],
}


# ---------------------------------------------------------------- Zugang
class Site:
    """Die Abilities-REST-Schnittstelle von WordPress; ohne Zugangsdaten liest sie aus einer Momentaufnahme."""

    def __init__(self, snapshot: Path | None, pause: float):
        self.snapshot = snapshot
        self.pause = pause
        self._last = 0.0
        self.url = self.user = self.password = None
        self.route = None
        if snapshot is None:
            self.url, self.user, self.password = read_credentials()

    def _wait(self) -> None:
        # Strato: Aufrufe strikt nacheinander, mit Pause dazwischen.
        delta = time.monotonic() - self._last
        if self._last and delta < self.pause:
            time.sleep(self.pause - delta)
        self._last = time.monotonic()

    def _request(self, method: str, ability: str, payload: dict) -> dict:
        auth = base64.b64encode(f"{self.user}:{self.password}".encode()).decode()
        # Auf fambach.net (gemessen 2026-10-18) gilt die Form mit «abilities/»; die andere steht in der Doku.
        routes = [self.route] if self.route else ["/wp-json/wp-abilities/v1/abilities/{ns}/{name}/run",
                                                  "/wp-json/wp-abilities/v1/{ns}/{name}/run"]
        ns, name = ability.split("/")
        last_error = None
        for route in routes:
            url = self.url.rstrip("/") + route.format(ns=ns, name=name)
            data = None
            if method == "GET":
                # Als JSON-Text kommt «input» als String an («input ist nicht vom Typ object»); als input[schlüssel] als Objekt.
                url += "?" + urllib.parse.urlencode({f"input[{k}]": v for k, v in payload.items()})
            else:
                data = json.dumps({"input": payload}).encode()
            req = urllib.request.Request(url, data=data, method=method, headers={
                "Authorization": f"Basic {auth}", "Content-Type": "application/json", "Accept": "application/json"})
            self._wait()
            try:
                with urllib.request.urlopen(req, timeout=120) as resp:
                    self.route = route
                    return json.loads(resp.read().decode())
            except urllib.error.HTTPError as e:
                body = e.read().decode(errors="replace")[:2000]
                last_error = RuntimeError(f"{method} {url} → HTTP {e.code}: {body}")
                if e.code == 404 and not self.route and "rest_no_route" in body:
                    continue
                raise last_error
        raise last_error

    def read_node(self, node: int) -> dict:
        if self.snapshot is not None:
            path = self.snapshot / f"{node}.json"
            if not path.exists():
                sys.exit(f"Momentaufnahme fehlt: {path}")
            return json.loads(path.read_text(encoding="utf-8"))
        return self._request("GET", "taxmod/read-node", {"node": node})

    def write_data(self, changes: list[dict]) -> dict:
        if self.snapshot is not None:
            raise RuntimeError("Schreiben braucht Zugangsdaten, nicht eine Momentaufnahme.")
        return self._request("POST", "taxmod/write-data", {"changes": changes})


def read_credentials() -> tuple[str, str, str]:
    url = os.environ.get("TAXMOD_URL")
    user = os.environ.get("TAXMOD_USER")
    password = os.environ.get("TAXMOD_APP_PASSWORD")
    if not password and CONFIG_PATH.exists():
        if CONFIG_PATH.stat().st_mode & 0o077:
            sys.exit(f"{CONFIG_PATH} ist für andere lesbar — bitte: chmod 600 {CONFIG_PATH}")
        parser = configparser.ConfigParser()
        parser.read(CONFIG_PATH, encoding="utf-8")
        section = parser["wordpress"]
        url = url or section.get("url")
        user = user or section.get("user")
        password = section.get("app_password")
    if not (url and user and password):
        sys.exit("Keine WordPress-Zugangsdaten (TAXMOD_URL/TAXMOD_USER/TAXMOD_APP_PASSWORD oder "
                 f"{CONFIG_PATH}); für einen Probelauf ohne Zugang: --snapshot samples/model")
    return url, user, password


# ---------------------------------------------------------------- Modell
class Model:
    def __init__(self, site: Site):
        self.nodes = {n: site.read_node(n) for n in (NODE_ORDERS, NODE_PURCHASES, NODE_SALES, NODE_SOLD, NODE_UNIT_VALUE)}
        self.field = {}
        for node, names in FIELDS.items():
            have = {f["name"]: f for f in self.nodes[node]["fields"]}
            missing = [n for n in names if n not in have]
            if missing:
                sys.exit(f"Knoten {node} ({self.nodes[node]['node']['name']}): Feld fehlt: {', '.join(missing)}")
            self.field[node] = {n: have[n] for n in names}
        currency = site.read_node(NODE_CURRENCY)
        by_name = {c["name"]: str(c["id"]) for c in currency.get("children", [])}
        self.currency = {code: by_name[name] for code, name in CURRENCY_NAMES.items() if name in by_name}
        if "EUR" not in self.currency:
            sys.exit("Währung «Euro» unter «Currency» nicht gefunden.")

    def rid(self, node: int, name: str) -> str:
        return str(self.field[node][name]["relation_id"])

    def required(self, node: int, name: str) -> bool:
        return self.field[node][name]["multiplicity"].startswith("1")

    def existing_keys(self, node: int, number_field: str, platform_field: str) -> dict:
        nr, pf = self.rid(node, number_field), self.rid(node, platform_field)
        keys = {}
        for rec in self.nodes[node].get("records", []):
            vals = {str(v["relation_id"]): v["value"] for v in rec["values"]}
            if vals.get(nr):
                keys[(str(vals.get(pf, "")).lower(), vals[nr].strip())] = rec["id"]
        return keys

    def existing_article_numbers(self) -> dict:
        an = self.rid(NODE_PURCHASES, "Artikelnummer")
        out = {}
        for rec in self.nodes[NODE_PURCHASES].get("records", []):
            for v in rec["values"]:
                if str(v["relation_id"]) == an and v["value"]:
                    out[str(v["value"]).strip()] = rec["id"]
        return out


# ---------------------------------------------------------------- Plan
def fmt(x: float) -> str:
    return f"{round(x, 4):.4f}".rstrip("0").rstrip(".")


def shorten(text: str, limit: int = 45) -> str:
    if len(text) <= limit:
        return text
    cut = text[:limit].rsplit(" ", 1)[0]
    return cut.rstrip(" -–,/") + " …"


def item_title(item: dict) -> str:
    """Artikeltitel, bei gewählter Variante (Farbe, Wert, Menge …) mit der Variante in Klammern."""
    return f"{item['title']} ({item['variant']})" if item.get("variant") else item["title"]


def landed_costs(items: list[dict], extra: float) -> list[float | None]:
    """Einstandspreis je Stück: Stückpreis plus Anteil an `extra`, verteilt nach dem Preis der Position (D-933)."""
    if any(i.get("unit_price") is None for i in items):
        return [None] * len(items)
    total = sum(i["unit_price"] * i["qty"] for i in items)
    out = []
    for i in items:
        share = extra * (i["unit_price"] * i["qty"]) / total if total else extra / len(items)
        out.append(i["unit_price"] + share / i["qty"])
    return out


class Planner:
    def __init__(self, model: Model, orders: list[dict], today: str):
        self.m = model
        self.today = today
        self.by_no = {(o["platform"], o["order_no"]): o for o in orders}
        self.order_keys = model.existing_keys(NODE_ORDERS, "Bestellnummer", "Plattform")
        self.sale_keys = model.existing_keys(NODE_SALES, "Bestellnummer", "Plattform")
        self.articles = model.existing_article_numbers()

    def money(self, node: int, field: str, amount: float, currency: str) -> dict:
        unit = self.m.currency.get(currency or "EUR")
        if unit is None:
            raise ValueError(f"Währung {currency} fehlt unter «Currency»")
        part = {self.m.rid(NODE_UNIT_VALUE, "wert"): fmt(amount), self.m.rid(NODE_UNIT_VALUE, "einheit"): unit}
        # Ein Pflichtteil steht beim neuen Satz schon leer da: «0» füllt ihn; sonst legt ein Name einen neuen an.
        return {"0" if self.m.required(node, field) else "neu": part}

    def provenance(self, o: dict, gaps: list[str]) -> str:
        mails = o.get("source_mails", [])
        names = []
        for m in mails:
            s = shorten(html.unescape(html.unescape(m["subject"])).lstrip("📦🚚🎉✅ "), 40)
            if s not in names:
                names.append(s)
        dates = sorted({(m.get("date") or "")[:10] for m in mails if m.get("date")})
        span = dates[0] if len(dates) == 1 else (f"{dates[0]} bis {dates[-1]}" if dates else "ohne Datum")
        text = (f"Aus {PLATFORM_SHORT.get(o['platform'], o['platform'])}-Mail(s) "
                + ", ".join(f"«{n}»" for n in names[:3]) + (f" und {len(names) - 3} weiteren" if len(names) > 3 else "")
                + f" ({span}), übernommen {self.today} durch kaeufe-import.")
        if gaps:
            text += " " + " ".join(gaps)
        return text

    def plan(self, o: dict) -> tuple[str, list[dict] | None, list[str]]:
        """Liefert (Status, Änderungsliste, Lücken); Status: neu, doppelt, unvollständig."""
        if o["kind"] == "sale":
            return self.plan_sale(o)
        return self.plan_order(o)

    def plan_order(self, o: dict) -> tuple[str, list[dict] | None, list[str]]:
        platform = PLATFORM_NAMES.get(o["platform"], o["platform"])
        no = o["order_no"]
        if no.startswith("ohne-Nr"):
            return "ohne Bestellnummer", None, ["keine Bestellnummer in der Mail"]
        if (platform.lower(), no) in self.order_keys:
            return f"doppelt (Bestellung {self.order_keys[(platform.lower(), no)]})", None, []
        items = o.get("items", [])
        arts = [i.get("article_no") for i in items if i.get("article_no")]
        if items and arts and len(arts) == len(items) and all(a in self.articles for a in arts):
            return f"doppelt (Kauf {self.articles[arts[0]]}, gleiche Artikelnummer)", None, []
        if not items:
            return "ohne Positionen", None, ["keine Artikel in den Mails"]

        cur = o.get("currency") or "EUR"
        gaps = []
        checkout = o.get("checkout")
        fees = sum(v for v in (o.get("fees") or {}).values() if v > 0)
        discount = sum(v for v in (o.get("fees") or {}).values() if v < 0)
        if checkout:
            # Sammelkauf: Versand und Gebühren der Kasse nach Preis über alle Bestellungen der Kasse verteilt.
            group = [self.by_no.get((o["platform"], n)) for n in checkout["orders"]]
            all_items = [i for g in group if g for i in g.get("items", [])]
            group_value = sum((i.get("unit_price") or 0) * i["qty"] for i in all_items)
            own_value = sum((i.get("unit_price") or 0) * i["qty"] for i in items)
            extra_all = (checkout.get("shipping") or 0) + sum((checkout.get("fees") or {}).values())
            extra = extra_all * own_value / group_value if group_value else 0.0
            gaps.append(f"Sammelkauf mit {', '.join(n for n in checkout['orders'] if n != no)}: die Mail nennt nur die "
                        f"Kassensumme {fmt(checkout['total'])} {cur} (Versand {fmt(checkout.get('shipping') or 0)}); "
                        "Versand und Gesamtbetrag dieser Bestellung sind nicht belegt, der Einstandspreis verteilt "
                        "den Versand der Kasse nach Preis.")
        else:
            extra = (o.get("shipping") or 0) + fees + discount
            if discount:
                gaps.append(f"Gutschein/Rabatt {fmt(-discount)} {cur} laut Mail, im Gesamtbetrag und Einstandspreis abgezogen.")
            if o.get("shipping") is None:
                gaps.append("Versandkosten stehen nicht in den Mails.")
            if o.get("total") is None:
                gaps.append("Gesamtbetrag steht nicht in den Mails.")
        if any(i.get("unit_price") is None for i in items):
            gaps.append("Stückpreis fehlt in den Mails.")
        if any(i.get("qty") is None for i in items):
            gaps.append("Stückzahl fehlt in den Mails (nur Prüfmail, keine Versandmail).")
        if o.get("refund"):
            gaps.append(f"Rückerstattung {fmt(o['refund'])} {cur} laut Mail, nicht abgezogen.")
        if o.get("charged_eur"):
            gaps.append(f"Abgebucht wurden {fmt(o['charged_eur'])} EUR.")
        landed = landed_costs(items, extra)

        changes = []
        refs = []
        for k, (it, lc) in enumerate(zip(items, landed)):
            ref = f"p{k}"
            refs.append("@" + ref)
            vals = {
                self.m.rid(NODE_PURCHASES, "Bezeichnung"): item_title(it),
                self.m.rid(NODE_PURCHASES, "Herkunft"): {"values": [HERKUNFT_BELEGT]},
                self.m.rid(NODE_PURCHASES, "Herkunftshinweis"): self.provenance(o, gaps),
            }
            if it.get("qty") is not None:
                vals[self.m.rid(NODE_PURCHASES, "Anzahl")] = str(it["qty"])
            if it.get("article_no"):
                vals[self.m.rid(NODE_PURCHASES, "Artikelnummer")] = it["article_no"]
            if it.get("unit_price") is not None:
                vals[self.m.rid(NODE_PURCHASES, "Preis je Stück")] = self.money(NODE_PURCHASES, "Preis je Stück", it["unit_price"], cur)
                vals[self.m.rid(NODE_PURCHASES, "Preis gesamt")] = self.money(NODE_PURCHASES, "Preis gesamt", it["unit_price"] * it["qty"], cur)
            if lc is not None:
                vals[self.m.rid(NODE_PURCHASES, "Einstandspreis je Stück")] = self.money(NODE_PURCHASES, "Einstandspreis je Stück", lc, cur)
            changes.append({"op": "create_record", "node": NODE_PURCHASES, "ref": ref})
            changes.append({"op": "set_values", "record": "@" + ref, "values": vals})

        title = shorten(items[0]["title"]) + (f" (+{len(items) - 1})" if len(items) > 1 else "")
        vals = {
            self.m.rid(NODE_ORDERS, "Bezeichnung"): f"{PLATFORM_SHORT.get(o['platform'], platform)} {no} · {title}",
            self.m.rid(NODE_ORDERS, "Bestellnummer"): no,
            self.m.rid(NODE_ORDERS, "Plattform"): platform,
            self.m.rid(NODE_ORDERS, "Herkunft"): {"values": [HERKUNFT_BELEGT]},
            self.m.rid(NODE_ORDERS, "Herkunftshinweis"): self.provenance(o, gaps),
            self.m.rid(NODE_ORDERS, "Positionen"): {"values": refs},
        }
        if o.get("merchant"):
            vals[self.m.rid(NODE_ORDERS, "Händler")] = o["merchant"]
        if o.get("ordered"):
            vals[self.m.rid(NODE_ORDERS, "Bestellt am")] = o["ordered"][:10]
        if o.get("received"):
            vals[self.m.rid(NODE_ORDERS, "Eingegangen am")] = o["received"][:10]
        if o.get("tracking"):
            vals[self.m.rid(NODE_ORDERS, "Sendungsnummer")] = {"values": list(o["tracking"])}
        if not checkout:
            if o.get("shipping") is not None:
                vals[self.m.rid(NODE_ORDERS, "Versandkosten")] = self.money(NODE_ORDERS, "Versandkosten", o["shipping"], cur)
            if fees:
                vals[self.m.rid(NODE_ORDERS, "Zoll / Gebühren")] = self.money(NODE_ORDERS, "Zoll / Gebühren", fees, cur)
            if o.get("total") is not None:
                vals[self.m.rid(NODE_ORDERS, "Gesamtbetrag")] = self.money(NODE_ORDERS, "Gesamtbetrag", o["total"], cur)
        changes.append({"op": "create_record", "node": NODE_ORDERS, "ref": "o"})
        changes.append({"op": "set_values", "record": "@o", "values": vals})
        return ("unvollständig" if gaps else "neu"), changes, gaps

    def plan_sale(self, o: dict) -> tuple[str, list[dict] | None, list[str]]:
        platform = PLATFORM_NAMES.get(o["platform"], o["platform"])
        no = o["order_no"]
        if (platform.lower(), no) in self.sale_keys:
            return f"doppelt (Verkauf {self.sale_keys[(platform.lower(), no)]})", None, []
        items = o.get("items", [])
        cur = o.get("currency") or "EUR"
        gaps = ["Gebühren stehen nicht in der Mail."]
        if o.get("shipping") is None:
            gaps.append("Versand steht nicht in der Mail.")
        if any(i.get("unit_price") is None for i in items):
            gaps.append("Verkaufspreis fehlt in der Mail.")
        changes, refs = [], []
        for k, it in enumerate(items):
            ref = f"p{k}"
            refs.append("@" + ref)
            vals = {
                self.m.rid(NODE_SOLD, "Bezeichnung"): item_title(it),
                self.m.rid(NODE_SOLD, "Anzahl"): str(it["qty"]),
                self.m.rid(NODE_SOLD, "Herkunft"): {"values": [HERKUNFT_BELEGT]},
                self.m.rid(NODE_SOLD, "Herkunftshinweis"): self.provenance(o, []),
            }
            if it.get("article_no"):
                vals[self.m.rid(NODE_SOLD, "Artikelnummer")] = it["article_no"]
            if it.get("unit_price") is not None:
                vals[self.m.rid(NODE_SOLD, "Verkaufspreis je Stück")] = self.money(NODE_SOLD, "Verkaufspreis je Stück", it["unit_price"], cur)
            changes.append({"op": "create_record", "node": NODE_SOLD, "ref": ref})
            changes.append({"op": "set_values", "record": "@" + ref, "values": vals})
        title = shorten(items[0]["title"]) if items else ""
        vals = {
            self.m.rid(NODE_SALES, "Bezeichnung"): f"{PLATFORM_SHORT.get(o['platform'], platform)} {no} · {title}",
            self.m.rid(NODE_SALES, "Bestellnummer"): no,
            self.m.rid(NODE_SALES, "Plattform"): platform,
            self.m.rid(NODE_SALES, "Herkunft"): {"values": [HERKUNFT_BELEGT]},
            self.m.rid(NODE_SALES, "Herkunftshinweis"): self.provenance(o, gaps),
            self.m.rid(NODE_SALES, "Positionen"): {"values": refs},
        }
        if o.get("buyer"):
            vals[self.m.rid(NODE_SALES, "Käufer")] = o["buyer"]
        if o.get("ordered"):
            vals[self.m.rid(NODE_SALES, "Verkauft am")] = o["ordered"][:10]
        if o.get("shipping") is not None:
            vals[self.m.rid(NODE_SALES, "Versand erhalten")] = self.money(NODE_SALES, "Versand erhalten", o["shipping"], cur)
        if o.get("total") is not None:
            vals[self.m.rid(NODE_SALES, "Gesamtbetrag")] = self.money(NODE_SALES, "Gesamtbetrag", o["total"], cur)
        if o.get("tracking"):
            vals[self.m.rid(NODE_SALES, "Sendungsnummer")] = {"values": list(o["tracking"])}
        changes.append({"op": "create_record", "node": NODE_SALES, "ref": "o"})
        changes.append({"op": "set_values", "record": "@o", "values": vals})
        return ("unvollständig" if len(gaps) > 1 else "neu"), changes, gaps


# ---------------------------------------------------------------- Lauf
def main() -> None:
    args = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    mode = args.add_mutually_exclusive_group()
    mode.add_argument("--dry-run", action="store_true", help="nur planen (Vorgabe)")
    mode.add_argument("--write", action="store_true", help="wirklich schreiben, je Vorgang ein Aufruf")
    args.add_argument("--orders", type=Path, default=SAMPLES / "orders.json")
    args.add_argument("--snapshot", type=Path, help="Modell aus Momentaufnahme (Ordner mit <knoten>.json) statt live lesen")
    args.add_argument("--only", help="nur diese Bestellnummern (Komma-Liste)")
    args.add_argument("--limit", type=int, help="höchstens so viele Vorgänge schreiben")
    args.add_argument("--pause", type=float, default=5.0, help="Sekunden zwischen zwei Aufrufen (mindestens 5)")
    options = args.parse_args()
    if options.write and options.snapshot:
        sys.exit("--write liest das Modell immer live, ohne --snapshot.")
    pause = max(5.0, options.pause)

    site = Site(options.snapshot, pause)
    model = Model(site)
    orders = json.loads(options.orders.read_text(encoding="utf-8"))
    if options.only:
        wanted = {n.strip() for n in options.only.split(",")}
        selected = [o for o in orders if o["order_no"] in wanted]
    else:
        selected = orders
    planner = Planner(model, orders, dt.date.today().isoformat())

    plan_dir = SAMPLES / "plan"
    plan_dir.mkdir(parents=True, exist_ok=True)
    for old in plan_dir.glob("*.json"):
        old.unlink()
    counts: dict[str, int] = {}
    todo = []
    for o in selected:
        status, changes, gaps = planner.plan(o)
        key = status.split(" (")[0]
        counts[f"{o['kind']}: {key}"] = counts.get(f"{o['kind']}: {key}", 0) + 1
        name = f"{o['kind']}-{o['platform']}-{o['order_no']}".replace("/", "_").replace(":", "_")[:80]
        (plan_dir / f"{name}.json").write_text(json.dumps(
            {"status": status, "gaps": gaps, "changes": changes}, ensure_ascii=False, indent=1), encoding="utf-8")
        if changes:
            if len(changes) > 500:
                sys.exit(f"{name}: {len(changes)} Änderungen, höchstens 500 je Aufruf.")
            todo.append((name, o, changes))

    for k in sorted(counts):
        print(f"{counts[k]:4d}  {k}")
    print(f"{len(todo)} Vorgänge zum Schreiben, Pläne in {plan_dir}")

    if not options.write:
        return
    if options.limit:
        todo = todo[:options.limit]
    log = SAMPLES / "written.log"
    for n, (name, o, changes) in enumerate(todo, 1):
        try:
            result = site.write_data(changes)
        except Exception as e:  # noqa: BLE001 — beim ersten Fehler aufhören, nichts weiter schreiben
            print(f"FEHLER bei {name}: {e}", file=sys.stderr)
            with log.open("a", encoding="utf-8") as fh:
                fh.write(json.dumps({"at": dt.datetime.now().isoformat(timespec="seconds"), "name": name,
                                     "error": str(e)[:1000]}, ensure_ascii=False) + "\n")
            sys.exit(1)
        with log.open("a", encoding="utf-8") as fh:
            fh.write(json.dumps({"at": dt.datetime.now().isoformat(timespec="seconds"), "name": name,
                                 "refs": result.get("refs")}, ensure_ascii=False) + "\n")
        print(f"{n}/{len(todo)} {name}: {result.get('refs')}")


if __name__ == "__main__":
    main()
