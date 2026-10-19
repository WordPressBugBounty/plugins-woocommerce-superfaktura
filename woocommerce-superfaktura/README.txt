=== SuperFaktura WooCommerce ===
Contributors: superfaktura, webikon, johnnypea, savione, kravco, martinkrcho
Tags: superfaktura, invoice, faktura, proforma, woocommerce
Requires at least: 4.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.59.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce eShop with online invoicing system SuperFaktura.

== Description ==

SuperFaktura extension for WooCommerce enables you to create invoices using third-party online app SuperFaktura.

SuperFaktura is an online invoicing system for small business owners available in Slovakia ([superfaktura.sk](http://www.superfaktura.sk/)) and Czech Republic ([superfaktura.cz](http://www.superfaktura.cz/)).

Features, answers to frequently asked questions and news about the plugin (in Slovak) are on its website [superfaktura.2day.sk](https://superfaktura.2day.sk/).

Main features of SuperFaktura WooCommerce include:

* Automatically create invoices in SuperFaktura.
* Add fields for invoice details to WooCommerce Checkout form.
* Link to the invoice is added to
	* Customer notification email sent by WooCommerce
	* Order detail
	* WooCommerce My Account page
* Set your own rules, when proforma or real invoice should be generated. Want to send proforma invoice on order creation and real invoice after payment? We got that covered.
* Custom invoice numbering.

This plugin is not directly associated with superfaktura.sk, s.r.o. or with superfaktura cz, s.r.o. or oficially supported by their developers.

Created by [Ján Bočínec](http://bocinec.sk/) with the support of [Slovak WordPress community](http://wp.sk/) and [WordPress agency Webikon](http://www.webikon.sk/). Since 2017 maintained by [2day.sk](https://www.2day.sk/).

== External services ==

This plugin connects to the following external services.

**SuperFaktúra API** (moja.superfaktura.sk, moje.superfaktura.cz, meine.superfaktura.at, or the sandbox at sandbox.superfaktura.sk and sandbox.superfaktura.cz)

The plugin creates, updates and reads invoices, proforma invoices and credit notes in your SuperFaktúra account. It sends your API credentials and the order data needed for the document: the customer's billing and shipping details (including company IDs and VAT number), ordered items, prices, payment and shipping method. With every request it also sends the WordPress, WooCommerce, plugin and PHP versions and your shop's domain name. It connects when a document is created or regenerated (automatically on an order status change or manually), when SuperFaktúra reports a paid invoice to your shop, when the PDF is attached to an email, and when you test the connection or open the plugin settings. Terms of use: [superfaktura.sk](https://www.superfaktura.sk/podmienky-pouzivania/), [superfaktura.cz](https://www.superfaktura.cz/podminky-uzivani/). Privacy policy: [superfaktura.sk](https://www.superfaktura.sk/ochrana-sukromia/), [superfaktura.cz](https://www.superfaktura.cz/ochrana-soukromi/).

**VIES VAT number validation by the European Commission** (ec.europa.eu)

Only when "Validate VAT #" is enabled, the plugin sends the VAT number entered at checkout to VIES to check that it is valid. Service: [VIES](https://ec.europa.eu/taxation_customs/vies/). Privacy policy: [European Commission](https://commission.europa.eu/privacy-policy-websites-managed-european-commission_en).

**Plugin announcements by 2day.sk** (sf.2day.sk)

When "Announcements" is enabled in WooCommerce > Settings > SuperFaktura > Integration (enabled by default), the plugin downloads once a day a small file with announcements about SuperFaktura and this plugin (for example about eFaktúra), its signature and up to 4 images from https://sf.2day.sk/woocommerce/. The announcements are shown in the admin only to users who can manage WooCommerce, and each can be closed. The request contains no data about your shop, users or customers: the server receives only your server's IP address and the user agent "SuperFaktura-WooCommerce/" with the plugin version, as in any web request. The service is operated by 2day.sk, the maintainer of this plugin. Developers can also turn the download off with the filter `sf_remote_messages_enabled`.

== Installation ==

1. Upload the entire SuperFaktura folder *woocommerce-superfaktura* to the /wp-content/plugins/ directory (or use WordPress native installer in Plugins -> Add New Plugin). And activate the plugin through the 'Plugins' menu in WordPress.
2. Visit your SuperFaktura account and get an API key
3. Set your SuperFaktura Account Email and API key in *WooCommerce -> Settings -> SuperFaktura*

== Screenshots ==
Coming soon.

== Frequently Asked Questions ==

= Invoice is not created automatically =

Check the settings in *WooCommerce -> Settings -> SuperFaktura*
You should fill your Account Email, API key and set the Order status in which you would like to create the invoice.

= Invoice is marked as paid =

Status of the payment is related to Order status. When an invoice is created with the status “On-Hold”, it will not be marked as paid. When an invoice is created with the status “Completed”, it will be marked as paid.

= The plugin stopped working and I don’t know why! =

This usually happens when you change your login email address. The email address in *WooCommerce -> Settings -> SuperFaktura* must be the same as the one you use to log in to SuperFaktura.

= Where can I find more information about SuperFaktura API? =

You can read more about SuperFaktura API integration at [superfaktura.sk/api](http://www.superfaktura.sk/api/)

== Changelog ==

= 1.59.2 (2026-10-19) =
* Zmena: Časť Pomoc v nastaveniach pluginu odkazuje na web pluginu superfaktura.2day.sk s funkciami, častými otázkami a novinkami a obsahuje údaje pre podporu na skopírovanie do e-mailu.

= 1.59.1 (2026-10-19) =
* Oprava: Príznak prenesenia daňovej povinnosti sa na faktúru dostane aj pri vypnutom nastavení Povoliť vlastné poznámky, napríklad na e-shope nastavenom len sprievodcom.

= 1.59.0 (2026-10-19) =
* Nové: Sprievodca nastavením po inštalácii prepojí obchod so SuperFaktúrou a odporučí nastavenia pre spôsoby platby a dopravy.
* Oprava: Vynechanie položky filtrom sf_item_data (vrátením null alebo false) už nevypíše varovanie PHP.

= 1.58.0 (2026-10-13) =
* Nové: Pri zapnutom OSS objednávka pod fakturačnou adresou ukazuje, či sa doklad vystaví v režime OSS a prečo.
* Nové: Dôležité oznamy o SuperFaktúre a plugine, napríklad o eFaktúre, priamo v administrácii.

= 1.57.0 (2026-10-08) =
* Nové: Nastavenie Stavy objednávky pre automatické párovanie: úhrada spárovaná v SuperFaktúre označí objednávku ako zaplatenú aj v ďalších zvolených stavoch, nielen v stave Čaká na platbu. Predvolené správanie sa nemení.
* Nové: Vysvetlenie častých chýb v API logu a v upozornení na neúspešné doklady. Nový filter sf_log_explanation.
* Bezpečnosť: Test API pripojenia je dostupný len pre správcov obchodu. Odkazy na vytvorenie a pregenerovanie dokladov v objednávke sú chránené pred zneužitím z iných stránok.

= 1.56.1 (2026-10-06) =
* Zmena: Informácia o povinnej eFaktúre od 1. 1. 2027 a o tom, ako sa na ňu pripraviť so SuperFaktúrou. Upravený text a obrázok.

= 1.56.0 (2026-10-06) =
* Nové: Informácia o eFaktúre, ktorá bude pre platiteľov DPH povinná od 1. 1. 2027, a o tom, ako sa na ňu pripraviť so SuperFaktúrou. Nový filter sf_show_efaktura_banner.
* Nové: Upozornenie v administrácii, keď sa doklad nepodarilo vytvoriť alebo aktualizovať v SuperFaktúre, s odkazmi na objednávky a API log. Nový filter sf_admin_document_errors.
* Nové: Jazyk faktúry podľa krajiny zákazníka, s nastaviteľným jazykom pre krajiny, ktorých jazyk SuperFaktúra nepodporuje. Nový filter sf_invoice_language_country_map.

= 1.55.3 (2026-09-29) =
* Zmena: Príznak OSS sa určuje podľa krajiny, podľa ktorej WooCommerce vypočítal DPH (dodacia alebo fakturačná adresa podľa nastavení dane, pri osobnom odbere krajina obchodu), namiesto vždy podľa fakturačnej adresy. Nový filter sf_invoice_oss.

= 1.55.2 (2026-09-25) =
* Oprava: Strata názvu firmy a firemných údajov v predplatnom (WooCommerce Subscriptions) po zaplatení zlyhanej obnovy cez pokladňu. Pri úhrade obnovy sa firemné údaje predvyplnia z predplatného.
* Oprava: Zmena fakturačnej adresy v Môj účet s voľbou aktualizovať predplatné prenesie do predplatných aj IČO, DIČ a IČ DPH.
* Oprava: Firemné údaje z blokovej pokladne sa ukladajú aj do profilu zákazníka a zobrazujú sa v Môj účet.
* Oprava: Obnovovacia objednávka s firemnými údajmi, ale bez názvu firmy, doplní názov z predplatného alebo pôvodnej objednávky.

= 1.55.1 (2026-09-23) =
* Zmena: PDF faktúra v prílohe e-mailu má rovnaký názov ako pri stiahnutí zo SuperFaktúry, namiesto interného ID dokladu.
* Nové: Filter sf_invoice_attachment_filename pre nastavenie vlastného názvu.

= 1.55.0 (2026-09-14) =
* Nové: Export, import a reset nastavení pluginu.

= 1.54.1 (2026-09-11) =
* Oprava: Chyba "Invalid API credentials" pri chýbajúcom Company ID v nastaveniach.

= 1.54.0 (2026-09-08) =
* Nové: Hromadné akcie v zozname objednávok: vystavenie faktúr, vystavenie zálohových faktúr a pregenerovanie existujúcich dokladov pre vybrané objednávky, so zobrazením priebehu spracovania.

= 1.53.8 (2026-08-19) =
* Opravené duplicitné zobrazenie firemných údajov v e-mailoch objednávky pri blokovej pokladni.

= 1.53.7 (2026-07-23) =
* Opravené ukladanie firemných údajov pri úprave WooCommerce Subscriptions predplatného v administrácii.
* Opravené upozornenie _load_textdomain_just_in_time (WordPress 6.7+).

= 1.53.6 (2026-07-22) =
* Opravená chyba v kompatibilite s pluginom WooCommerce Subscriptions.

= 1.53.5 (2026-07-21) =
* Pridaná podpora pre plugin WooCommerce PDF Product Vouchers.

= 1.53.4 (2026-07-21) =
* Automatické doplnenie firemných údajov (IČO, DIČ, IČ DPH a názov firmy) z polí uložených WooCommerce, ak ich staršia verzia pluginu pri blokovej pokladni do objednávky neuložila. Platí aj pre ručné pregenerovanie starších faktúr.
* Objednávky obnovenia predplatného (WooCommerce Subscriptions) si firemné údaje doplnia z predplatného alebo z pôvodnej objednávky.

= 1.53.3 (2026-07-21) =
* Opravené ukladanie firemných údajov (IČO, DIČ, IČ DPH a názov firmy) do objednávky a faktúry v blokovej pokladni pre prihlásených zákazníkov s predvyplnenými firemnými údajmi.

= 1.53.2 (2026-07-07) =
* Opravená chyba s odpočítaním DPH (prenesenie daňovej povinnosti) v klasickom WooCommerce Checkoute.

= 1.53.1 (2026-07-06) =
* Opravené automatické odpočítanie DPH (prenesenie daňovej povinnosti) na produkty a na dopravu vo WooCommerce Checkout blokoch, aj v reálnom čase počas vypĺňania objednávky. Za identifikovanie problému a doplnenie informácií, ktoré pomohli pri oprave, ďakujeme [@mattbosak](https://profiles.wordpress.org/mattbosak/).

= 1.53.0 (2026-06-29) =
* Pridané nastavenie pre automatické odpočítanie DPH (prenesenie daňovej povinnosti) pri zadaní platného IČ DPH overeného cez VIES pre firemného zákazníka z inej krajiny EÚ.
* Opravené spojenie s VIES na serveroch, kde overenie IČ DPH zlyhávalo s chybou "cURL error 56".

= 1.52.10 (2026-06-22) =
* Opravená chyba s prázdnym ID dokladu v prípade, ak API vráti odpoveď bez chyby, ale aj bez faktúry.

= 1.52.9 (2026-06-18) =
* Overovanie IČ DPH cez VIES sa pri zlyhaní spojenia s ec.europa.eu automaticky zopakuje cez HTTP/1.1.
* Nové nastavenie pre povolenie alebo zablokovanie objednávky pri nedostupnom VIES.

= 1.52.8 (2026-06-15) =
* Overovanie IČ DPH cez VIES neblokuje dokončenie objednávky, ak je služba VIES dočasne nedostupná. Blokuje sa iba potvrdené neplatné IČ DPH.

= 1.52.7 (2026-06-05) =
* Opravené zobrazenie voľby nákupu na firmu pri objednávkach z WooCommerce Checkout blokov v potvrdení objednávky, v e-mailoch a v zákazníckom účte

= 1.52.6 (2026-05-28) =
* Dodacia adresa sa odosiela aj v prípade, že sa zhoduje s fakturačnou adresou, ak je zapnutá možnosť Delivery Name "CompanyName - FirstName LastName"

= 1.52.5 (2026-05-27) =
* Citlivé hodnoty API Key a Secret Key sa už nevracajú cez WooCommerce settings REST API.
* API Key a Secret Key sa v administrácii zobrazujú ako password polia s možnosťou zobrazenia a kopírovania.

= 1.52.4 (2026-05-27) =
* Pridaný parameter $item do filtra sf_item_data pre prístup k aktuálnej položke objednávky

= 1.52.3 (2026-05-07) =
* Opravené ukladania firemných údajov v classic checkoute
* Doplnená kompatibilita s podtriedami WC_Order_Item_Tax pri spracovaní sadzieb DPH

= 1.52.2 (2026-05-05) =
* Opravené spracovanie firemných údajov pri classic aj block checkoute
* Upravené poradie firemných polí na IČO, DIČ a IČ DPH

= 1.52.1 (2026-04-15) =
* Kompatibilita s WordPress 7.0

= 1.52.0 (2026-03-16) =
* Opravené generovanie položiek faktúry pre zmazané produkty (bez SKU a popisu produktu, ktoré nie je možné zrekonštruovať z údajov objednávky)

= 1.51.0 (2026-03-16) =
* Pridaný filter sf_invoice_extras
* Pridaný action hook sf_before_invoice_create (spustí sa pred odoslaním faktúry cez API)
* Pridaný action hook sf_after_invoice_create (spustí sa po úspešnom vytvorení faktúry cez API)

= 1.50.4 (2026-03-12) =
* Opravená kontrola odlišnej dodacej adresy zákazníka

= 1.50.3 (2026-02-12) =
* Doplnené spracovanie štátu (state, nie country) vo fakturačnej a dodacej adrese zákazníka

= 1.50.2 (2026-02-10) =
* Opravené preklady do čestiny

= 1.50.1 (2026-01-20) =
* Doplnený filter sf_fee_data

= 1.50.0 (2026-01-13) =
* Pridaná podpora fakturácie na firmu pre WooCommerce Checkout blok

Kompletný zoznam zmien nájdete v súbore [changelog.txt](https://plugins.svn.wordpress.org/woocommerce-superfaktura/trunk/changelog.txt).
