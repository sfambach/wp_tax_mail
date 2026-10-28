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
eml('c3','Conrad Electronic <noreply@conrad.de>','Auftrag 2020147197 Sendungsinformation zu Ihrem Paket',
    ['Ihre Bestellung 2020147197 wird voraussichtlich heute zugestellt.','Bestellt','Versendet','Zugestellt','Guten Tag,',
     'Ihre Bestellung 2020147197 ist nun fast bei Ihnen. Die Zustellung Ihrer Bestellung erfolgt voraussichtlich heute .','Sendung verfolgen'])
eml('c2','Conrad <noreply@conrad.de>','Bestellt: Yageo MF0207 und 1 weitere Artikel - 2020147197',
    ['Vielen Dank für Ihre Bestellung!','wir haben Ihre Bestellung','2020147197','erhalten','Bestellübersicht','24.10.2026',
     'Yageo MF0207F220RH Metallschicht-Widerstand 220 Ω 1 St.','Lieferung: Mi., 28.10.2026','Menge: 100 Stück','Einzelpreis: € 0,02','Artikelnummer: 1417626','Verkäufer: Conrad Electronic','€ 1,62',
     'Kontakt Chemie 814727 Sprühröhrchen 5 St.','Lieferung: Mi., 28.10.2026','Menge: 10 Set(s)','Einzelpreis: € 0,10','Artikelnummer: 814727','Verkäufer: Conrad Electronic','€ 1,00',
     'Zwischensumme','€ 2,62','Versandkosten','€ 4,95','Conrad','€ 4,95','Summe','€ 7,57'])
eml('a1','AliExpress <transaction@notice.aliexpress.com>','Bestellung 8123456789012345678: auf Bestätigung wird gewartet',
    ['Bestellung 8123456789012345678','ESP32 DevKit','x3','USB-C Kabel','$1.20','x2','Bestellsumme','12,34€'])
eml('j1','Stefan <stefan@example.de>','Fwd: Order',
    ['-------- Forwarded Message --------','Subject: Your Order W202410151234567 Review Completed','Date: Tue, 15 Oct 2024 08:00:00 +0000','From: JLCPCB <support@jlcpcb.com>','To: einkauf@example.de','【W202410151234567】','isa8_multi_io_gerber_Y87','Approved','backplane-rev2','Approved','View order'])
eml('j2','Stefan <stefan@example.de>','Fwd: Order',
    ['-------- Forwarded Message --------','Subject: Your order W202410151234567 is On Its Way','Date: Mon, 21 Oct 2024 08:00:00 +0000','From: JLCPCB <support@jlcpcb.com>','To: einkauf@example.de','Ready for pick up by DHL Express. Tracking Number: 1234567890','Items in this shipment','isa8_multi_io_gerber_Y87','5 pcs','backplane-rev2(Reorder)','10 pcs','View order'])
eml('a2','AliExpress <transaction@notice.aliexpress.com>','Bestellung 8123456789012345678: Bestellung versandt',
    ['Bestellung 8123456789012345678','Ihr Paket ist unterwegs','Sendungsnummer: LP00123456789012','ESP32 DevKit','x3'], date='Mon, 21 Oct 2024 09:00:00 +0200')
eml('d1','noreply@dhl.com','Auftragsbestätigung Ihrer Online Frankierung 7BQCFDV5F9Z8',
    ['Warenkorb-Nr.: 7BQCFDV5F9Z8','Datum: 23.10.2026','IHRE VERSANDMARKE:','1x Maxibrief bis 1000 g / 2,90 €',
     '<a href="https://www.dhl.de/int-versenden/gw/rest/init?token=abc123&amp;download=letter&amp;pos=1">Versandmarke drucken</a>',
     '<a href="https://www.dhl.de/int-versenden/gw/rest/init?token=abc123&amp;download=invoice">Rechnung</a>'], date='Fri, 23 Oct 2026 18:20:40 +0200')
eml('z1','"Amazon.de" <bestellbestaetigung@amazon.de>','Bestellt: „ESP32 DevKit...“ und 2 weitere Artikel',
    ['Vielen Dank für deine Bestellung!','Bestellnr. \u202b304-1111111-2222222','<img alt="ESP32 DevKit V1 Entwicklungsboard mit CP2102 | 2 Stück">ESP32 DevKit V1 Entwick...','Verkauf durch Amazon.de','Zustand: Neu','Menge: 2','9','98','€','9 98 €',
     'Summe','0.00€','Bestellnr. \u202b304-3333333-4444444','USB-C Kabel 1 m','Verkauf durch Kabelshop','Zustand: Neu','Menge: 1','4','49','€','4 49 €','Summe','0.00€'], date='Wed, 21 Oct 2026 15:49:00 +0200')
eml('z2','"Amazon.de" <order-update@amazon.de>','Zugestellt: „ESP32 DevKit...“',
    ['Dein Paket wurde zugestellt!','Heute zugestellt','Bestellnr. \u202b304-1111111-2222222','Lieferung verfolgen'], date='Thu, 22 Oct 2026 09:37:32 +0200')
E='eBay <ebay@ebay.com>'
eml('e11',E,'📦PACKAGE DELIVERED: Creative Soundblaste...',
    ['Dropped off on Wed, Oct 28','Your package is here. Now for the good part!','View order details','Creative Soundblaster 16 CT2940 ISA',
     'Item ID:','237124172289','237124172289','Order number:','09-15343-88398','09-15343-88398','Seller:','suswl77','suswl77','eBay Money Back Guarantee'])
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
eml('e9',E,'Ein Käufer möchte einen Kauf abbrechen',['Abbruchanfrage','greaseweazle 4.1, USB Floppy Disk Controller','Bestellnummer:','24-13651-27152','Preis:','EUR 25,00'])
eml('e10',E,'Sie haben einen Kauf abgebrochen',['Abgebrochen','greaseweazle 4.1, USB Floppy Disk Controller','Bestellnummer:','24-13651-27152'])
eml('e7',E,'Ihr Käufer hat eine Anfrage wegen eines nicht erhaltenen Artikels geöffnet: Anfrage 5388597203',
    ['Anfrage','Arduino FDC - Ein Floppy disk Controller aufsatz für den Arduino NUR Platine','Bestellnummer:','24-15238-03470','Käufer:','someone'])
