"""Mails aus dem Einkaufs-Postfach als .eml-Dateien holen — nur lesend.

Das Postfach wird schreibgeschützt geöffnet (EXAMINE) und jede Mail mit BODY.PEEK[]
geholt: nichts wird verschoben, gelöscht oder als gelesen markiert.

Zugangsdaten stehen in ~/.config/kaeufe-import/imap.ini (siehe imap.ini.example),
nicht im Projekt und nicht in Git.
"""

from __future__ import annotations

import argparse
import configparser
import hashlib
import imaplib
import sys
from email import message_from_bytes, policy
from pathlib import Path

CONFIG_PATH = Path.home() / ".config" / "kaeufe-import" / "imap.ini"


def read_config(path: Path) -> configparser.SectionProxy:
    if not path.exists():
        sys.exit(f"Keine Zugangsdaten: {path} fehlt (Vorlage: imap.ini.example).")
    if path.stat().st_mode & 0o077:
        sys.exit(f"{path} ist für andere lesbar — bitte: chmod 600 {path}")
    parser = configparser.ConfigParser()
    parser.read(path, encoding="utf-8")
    return parser["imap"]


def fetch(target: Path, folder: str, limit: int) -> int:
    config = read_config(CONFIG_PATH)
    target.mkdir(parents=True, exist_ok=True)

    with imaplib.IMAP4_SSL(config["host"], int(config.get("port", "993"))) as imap:
        imap.login(config["user"], config["password"])
        status, _ = imap.select(f'"{folder}"', readonly=True)
        if status != "OK":
            sys.exit(f"Ordner {folder!r} nicht gefunden.")

        _, data = imap.uid("SEARCH", None, "ALL")
        uids = data[0].split()[-limit:]

        saved = 0
        for uid in uids:
            _, parts = imap.uid("FETCH", uid, "(BODY.PEEK[])")
            raw = next(p[1] for p in parts if isinstance(p, tuple))
            message = message_from_bytes(raw, policy=policy.default)
            digest = hashlib.sha256(raw).hexdigest()[:12]
            name = f"{uid.decode()}-{digest}.eml"
            (target / name).write_bytes(raw)
            print(f"{name}  {message.get('From', '')}  |  {message.get('Subject', '')}")
            saved += 1
        return saved


def main() -> None:
    args = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    args.add_argument("--target", type=Path, default=Path(__file__).resolve().parent.parent / "samples")
    args.add_argument("--folder", default="INBOX")
    args.add_argument("--limit", type=int, default=50, help="höchstens so viele der neuesten Mails")
    options = args.parse_args()
    count = fetch(options.target, options.folder, options.limit)
    print(f"{count} Mails nach {options.target} gespeichert.")


if __name__ == "__main__":
    main()
