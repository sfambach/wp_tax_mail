"""Eine Bestellmail als Text: weitergeleitete Mails werden ausgepackt (Betreff, Datum, Absender des Originals)."""

from __future__ import annotations

import html
import re
from dataclasses import dataclass, field
from datetime import datetime
from email import message_from_bytes, policy
from email.utils import parseaddr, parsedate_to_datetime
from pathlib import Path

MONEY = re.compile(r'(EUR|US \$|€|\$|GBP|£)\s*(-?[\d.]+,\d{1,5}|-?\d[\d,]*\.\d{1,5}|-?\d+)|(-?[\d.]+,\d{2})\s*(€|EUR)')
CURRENCY = {'EUR': 'EUR', '€': 'EUR', 'US $': 'USD', '$': 'USD', 'GBP': 'GBP', '£': 'GBP'}
FORWARDED = re.compile(r'Forwarded Message -+\s*Subject:\s*(.*?)\s*Date:\s*(.*?)\s*From:\s*(.*?)\s*To:', re.S)


@dataclass
class Mail:
    file: str
    subject: str
    date: datetime | None
    sender: str
    text: str
    lines: list[str] = field(default_factory=list)

    @property
    def domain(self) -> str:
        return parseaddr(self.sender.split('\n')[0])[1].split('@')[-1].lower()


def load(path: Path) -> Mail:
    message = message_from_bytes(path.read_bytes(), policy=policy.default)
    text = to_text(body_html(message))
    subject, date, sender = str(message['Subject'] or ''), str(message['Date'] or ''), str(message['From'] or '')
    fwd = FORWARDED.search(text[:3000])
    if fwd:
        subject, date, sender = (g.strip() for g in fwd.groups())
        text = text[fwd.end():]
    else:
        subject = re.sub(r'^(Fwd|WG|AW|Fw):\s*', '', subject, flags=re.I)
    try:
        when = parsedate_to_datetime(date)
    except (TypeError, ValueError):
        when = None
    lines = [fix(line.strip()) for line in text.replace('\r', '').split('\n') if line.strip()]
    return Mail(path.name, subject, when, sender, text.replace('\r', ''), lines)


def body_html(message) -> str:
    for part in message.walk():
        if part.get_content_type() == 'text/html':
            try:
                return part.get_content()
            except (LookupError, ValueError):
                return part.get_payload(decode=True).decode('utf-8', 'replace')
    for part in message.walk():
        if part.get_content_type() == 'text/plain':
            return part.get_content()
    return ''


def to_text(markup: str) -> str:
    markup = re.sub(r'(?is)<(script|style|head).*?</\1>', ' ', markup)
    markup = re.sub(r'(?i)<br\s*/?>|</(p|div|tr|td|li|h\d|table)>', '\n', markup)
    text = html.unescape(re.sub(r'<[^>]+>', ' ', markup))
    text = re.sub('[͏‌​­ ]', '', text)
    text = re.sub(r'[ \t\xa0]+', ' ', text)
    return re.sub(r'\n\s*\n+', '\n', text).strip()


def fix(s: str) -> str:
    """Doppelt kodiertes UTF-8 («StÃ¼ck») zurückdrehen."""
    if 'Ã' in s or 'â\x80' in s:
        for codec in ('latin-1', 'cp1252'):
            try:
                return s.encode(codec).decode('utf-8')
            except (UnicodeEncodeError, UnicodeDecodeError):
                continue
    return s


def num(s: str) -> float:
    s = s.strip()
    if ',' in s and ('.' not in s or s.rfind(',') > s.rfind('.')):
        s = s.replace('.', '').replace(',', '.')
    else:
        s = s.replace(',', '')
    return round(float(s), 5)


def money(s: str | None) -> tuple[float | None, str | None]:
    m = MONEY.search(s or '')
    if not m:
        return None, None
    if m.group(1):
        return num(m.group(2)), CURRENCY[m.group(1)]
    return num(m.group(3)), 'EUR'


def after(lines: list[str], label: str, n: int = 1) -> str | None:
    """Die Zeile `n` hinter `label`; endet `label` auf `*`, reicht der Anfang."""
    for i, line in enumerate(lines):
        if line == label or (label.endswith('*') and line.startswith(label[:-1])):
            return lines[i + n] if i + n < len(lines) else None
    return None


def iso(d: datetime | None) -> str | None:
    return d.isoformat(timespec='minutes') if d else None
