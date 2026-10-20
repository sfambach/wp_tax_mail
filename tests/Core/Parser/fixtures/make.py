# Builds the synthetic fixture mails (no real mail is in this repo). The same files run through the Python
# importer in legacy/ gave the expectations in ShopParsersTest.
import os
def eml(name, frm, subject, cells, date='Tue, 15 Oct 2024 10:00:00 +0200', mid=None):
    html = '<html><body><table>' + ''.join(f'<tr><td>{c}</td></tr>' for c in cells) + '</table></body></html>'
    open(f'{name}.eml','w',encoding='utf-8').write(
        f'From: {frm}\nSubject: {subject}\nDate: {date}\nMessage-ID: <{name}@x>\nMIME-Version: 1.0\nContent-Type: text/html; charset=utf-8\n\n{html}\n')
eml('r1','reichelt elektronik <shop@reichelt.de>','Ihre Bestellung I-123456 vom 14.10.2024',
    ['Hallo','Bestellübersicht','Artikelnr.','Bezeichnung','Menge','Preis','LM 317','LM317 Spannungsregler 1,5A','10','0,52 €','H25PR075','Lochrasterplatine 75x100','2','3,95 €','Zwischensumme','13,10 €','Versandkosten (DHL)','5,95 €','Gesamtpreis','19,05 €'])
eml('r2','reichelt versandinfo <versandinfo@reichelt.de>','Versandinfo Bestellung I-123456',
    ['Ihre Sendung','Ihre Bearbeitungsnummer I-123456','Die Sendungsnummer lautet 00340434610895','Logistik- Partner DHL'])
eml('m1','Mouser <sales@eu.mouser.com>','Mouser Order Confirmation 12345678',
    ['Web Order Number','12345678','595-LM317TG','LM317TG','Linear Voltage Regulator','OCT 16, 2024','5','0,61','3,05','566-0206F','Merchandise Total','3,05','Shipping','20,00','VAT','4,38'])
eml('c1','Conrad <service@conrad.de>','Bestellt: Ihre Bestellung 1234567890',
    ['Ihre Bestellung','Lötzinn 100g','Menge: 2','Einzelpreis 7,99 €','Artikelnummer 123456','Versandkosten','4,95 €','Summe','20,93 €'])
eml('a1','AliExpress <transaction@notice.aliexpress.com>','Bestellung 8123456789012345678: auf Bestätigung wird gewartet',
    ['Bestellung 8123456789012345678','ESP32 DevKit','x3','USB-C Kabel','$1.20','x2','Bestellsumme','12,34€'])
eml('j1','Stefan <stefan@example.de>','Fwd: Order',
    ['-------- Forwarded Message --------','Subject: Your Order W202410151234567 Review Completed','Date: Tue, 15 Oct 2024 08:00:00 +0000','From: JLCPCB <support@jlcpcb.com>','To: einkauf@example.de','【W202410151234567】','isa8_multi_io_gerber_Y87','Approved','backplane-rev2','Approved','View order'])
eml('j2','Stefan <stefan@example.de>','Fwd: Order',
    ['-------- Forwarded Message --------','Subject: Your order W202410151234567 is On Its Way','Date: Mon, 21 Oct 2024 08:00:00 +0000','From: JLCPCB <support@jlcpcb.com>','To: einkauf@example.de','Ready for pick up by DHL Express. Tracking Number: 1234567890','Items in this shipment','isa8_multi_io_gerber_Y87','5 pcs','backplane-rev2(Reorder)','10 pcs','View order'])
E='eBay <ebay@ebay.com>'
eml('e1',E,'Bestellung bestätigt: ISA Floppy Controller',
    ['Danke für Ihren Kauf','ISA 8-bit Floppy Multi-I/O Controller Zilog','Preis:','EUR 45,00','Artikelnr.:','123456789012','Lieferung ca.:','Fr, 18. Okt','Bestellnummer:','27-13610-49402','Verkäufer:','r-o-r-c','Gesamtbetrag:','Zwischensumme (1 Artikel)','EUR 45,00','Versand','EUR 6,99','Gesamtbetrag wird abgebucht','EUR 51,99','eBay-Käuferschutz'])
eml('e2',E,'Ihre Bestellung wurde verschickt',
    ['Gute Nachrichten','ISA 8-bit Floppy Multi-I/O Controller Zilog','Bestellnummer:','27-13610-49402','Verkäufer:','r-o-r-c','Sendungsnummer:','00340434610899111','Versanddienstleister:','DHL'])
eml('e3',E,'Artikel verkauft - Commodore 64 Netzteil',
    ['Glückwunsch','Bestellung:','11-22222-33333','Käufer:','retrofan42','Verkaufte Stückzahl:','2','Verkauft:','EUR 30,00','Versand:','EUR 5,49'])
eml('e4',E,'Bestellung bestätigt: 2 Artikel',
    ['Danke','Z80 CPU','Preis:','EUR 12,00 (3 x EUR 4,00)','Artikelnr.:','223344556677','Bestellnummer:','12-34567-89012','Verkäufer:','chipshop','62256 SRAM','Preis:','EUR 3,50','Artikelnr.:','998877665544','Bestellnummer:','12-34567-89012','Verkäufer:','chipshop','Gesamtbetrag:','Zwischensumme (2 Artikel)','EUR 15,50','Versand','Kostenlos','Mehrwertsteuer','EUR 2,95','Gesamtbetrag wird abgebucht','EUR 18,45','eBay-Käuferschutz'])
eml('e5',E,'Sparen Sie 20 % Rabatt auf Elektronik',['Angebote','Bestellnummer:','00-00000-00000'])
eml('e6',E,'Ihre Rechnung für den eBay-Kauf',['Rechnung','334455667788','Atari 2600 Joystick','1','EUR 19,90','EUR 19,90','Gesamt:','EUR 24,80'])
eml('e8',E,'Ihre Rechnung für den eBay-Kauf: Terratec TT1816-N v1.0 16 BIT ISA PnP Soundkarte Retro (315973194490#)',['Rechnung','315973194490','Terratec TT1816-N v1.0 16 BIT ISA PnP Soundkarte Retro','2','EUR 16,00','EUR 16,00','Gesamt:','EUR 20,95'])
eml('e7',E,'Ihr Käufer hat eine Anfrage wegen eines nicht erhaltenen Artikels geöffnet: Anfrage 5388597203',
    ['Anfrage','Arduino FDC - Ein Floppy disk Controller aufsatz für den Arduino NUR Platine','Bestellnummer:','24-15238-03470','Käufer:','someone'])
