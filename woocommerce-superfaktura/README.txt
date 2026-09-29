=== SuperFaktura WooCommerce ===
Contributors: superfaktura, webikon, johnnypea, savione, kravco, martinkrcho
Tags: superfaktura, invoice, faktura, proforma, woocommerce
Requires at least: 4.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.55.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce eShop with online invoicing system SuperFaktura.

== Description ==

SuperFaktura extension for WooCommerce enables you to create invoices using third-party online app SuperFaktura.

SuperFaktura is an online invoicing system for small business owners available in Slovakia ([superfaktura.sk](http://www.superfaktura.sk/)) and Czech Republic ([superfaktura.cz](http://www.superfaktura.cz/)).

For more information about the plugin and its settings check the articles on SuperFaktura blog:
[SuperFaktúra a WooCommerce: Diel 1. – Inštalácia a autorizácia](https://www.superfaktura.sk/blog/superfaktura-a-woocommerce-diel-1-instalacia-a-autorizacia/)
[SuperFaktura a WooCommerce: Díl 1. – Instalace a autorizace](https://www.superfaktura.cz/blog/superfaktura-a-woocommerce-dil-1-instalace-a-autorizace/)

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

= 1.55.3 =
* Príznak OSS sa určuje podľa krajiny, podľa ktorej WooCommerce vypočítal DPH (dodacia alebo fakturačná adresa podľa nastavení dane, pri osobnom odbere krajina obchodu), namiesto vždy podľa fakturačnej adresy. Nový filter sf_invoice_oss.

= 1.55.2 =
* Opravená strata názvu firmy a firemných údajov v predplatnom (WooCommerce Subscriptions) po zaplatení zlyhanej obnovy cez pokladňu. Pri úhrade obnovy sa firemné údaje predvyplnia z predplatného.
* Zmena fakturačnej adresy v Môj účet s voľbou aktualizovať predplatné prenesie do predplatných aj IČO, DIČ a IČ DPH.
* Firemné údaje z blokovej pokladne sa ukladajú aj do profilu zákazníka a zobrazujú sa v Môj účet.
* Obnovovacia objednávka s firemnými údajmi, ale bez názvu firmy, doplní názov z predplatného alebo pôvodnej objednávky.

= 1.55.1 =
* PDF faktúra v prílohe emailu má rovnaký názov ako pri stiahnutí zo SuperFaktúry, namiesto interného ID dokladu.
* Pridaný filter sf_invoice_attachment_filename pre nastavenie vlastného názvu.

= 1.55.0 =
* Pridaná možnosť export, import a reset nastavení pluginu

= 1.54.1 =
* Opravená chyba "Invalid API credentials" pri chýbajúcom Company ID v nastaveniach.

= 1.54.0 =
* Pridané hromadné akcie v zozname objednávok: vystavenie faktúr, vystavenie zálohových faktúr a pregenerovanie existujúcich dokladov pre vybrané objednávky, so zobrazením priebehu spracovania.

= 1.53.8 =
* Opravené duplicitné zobrazenie firemných údajov v e-mailoch objednávky pri blokovej pokladni.

= 1.53.7 =
* Opravené ukladanie firemných údajov pri úprave WooCommerce Subscriptions predplatného v administrácii.
* Opravené upozornenie _load_textdomain_just_in_time (WordPress 6.7+).

= 1.53.6 =
* Opravená chyba v kompatibilite s pluginom WooCommerce Subscriptions.

= 1.53.5 =
* Pridaná podpora pre plugin WooCommerce PDF Product Vouchers.

= 1.53.4 =
* Automatické doplnenie firemných údajov (IČO, DIČ, IČ DPH a názov firmy) z polí uložených WooCommerce, ak ich staršia verzia pluginu pri blokovej pokladni do objednávky neuložila. Platí aj pre ručné pregenerovanie starších faktúr.
* Objednávky obnovenia predplatného (WooCommerce Subscriptions) si firemné údaje doplnia z predplatného alebo z pôvodnej objednávky.

= 1.53.3 =
* Opravené ukladanie firemných údajov (IČO, DIČ, IČ DPH a názov firmy) do objednávky a faktúry v blokovej pokladni pre prihlásených zákazníkov s predvyplnenými firemnými údajmi.

= 1.53.2 =
* Opravená chyba s odpočítaním DPH (prenos daňovej povinnosti) v klasickom WooCommerce Checkoute.

= 1.53.1 =
* Opravené automatické odpočítanie DPH (prenos daňovej povinnosti) na produkty a na dopravu vo WooCommerce Checkout blokoch, aj v reálnom čase počas vypĺňania objednávky. Za identifikovanie problému a doplnenie informácií, ktoré pomohli pri oprave, ďakujeme [@mattbosak](https://profiles.wordpress.org/mattbosak/).

= 1.53.0 =
* Pridané nastavenie pre automatické odpočítanie DPH (prenos daňovej povinnosti) pri zadaní platného IČ DPH overeného cez VIES pre firemného zákazníka z inej krajiny EÚ.
* Opravené spojenie s VIES na serveroch, kde overenie IČ DPH zlyhávalo s chybou "cURL error 56".

= 1.52.10 =
* Opravená chyba s prázdnym ID dokladu v prípade, ak API vráti odpoveď bez chyby, ale aj bez faktúry.

= 1.52.9 =
* Overovanie IČ DPH cez VIES sa pri zlyhaní spojenia s ec.europa.eu automaticky zopakuje cez HTTP/1.1.
* Nové nastavenie pre povolenie alebo zablokovanie objednávky pri nedostupnom VIES.

= 1.52.8 =
* Overovanie IČ DPH cez VIES neblokuje dokončenie objednávky, ak je služba VIES dočasne nedostupná. Blokuje sa iba potvrdené neplatné IČ DPH.

= 1.52.7 =
* Opravené zobrazenie voľby nákupu na firmu pri objednávkach z WooCommerce Checkout blokov v potvrdení objednávky, v e-mailoch a v zákazníckom účte

= 1.52.6 =
* Dodacia adresa sa odosiela aj v prípade, že sa zhoduje s fakturačnou adresou, ak je zapnutá možnosť Delivery Name "CompanyName - FirstName LastName"

= 1.52.5 =
* Citlivé hodnoty API Key a Secret Key sa už nevracajú cez WooCommerce settings REST API.
* API Key a Secret Key sa v administrácii zobrazujú ako password polia s možnosťou zobrazenia a kopírovania.

= 1.52.4 =
* Pridaný parameter $item do filtra sf_item_data pre prístup k aktuálnej položke objednávky

= 1.52.3 =
* Opravené ukladania firemných údajov v classic checkoute
* Doplnená kompatibilita s podtriedami WC_Order_Item_Tax pri spracovaní sadzieb DPH

= 1.52.2 =
* Opravené spracovanie firemných údajov pri classic aj block checkoute
* Upravené poradie firemných polí na IČO, DIČ a IČ DPH

= 1.52.1 =
* Kompatibilita s WordPress 7.0

= 1.52.0 =
* Opravené generovanie položiek faktúry pre zmazané produkty (bez SKU a popisu produktu, ktoré nie je možné zrekonštruovať z údajov objednávky)

= 1.51.0 =
* Pridaný filter sf_invoice_extras
* Pridaný action hook sf_before_invoice_create (spustí sa pred odoslaním faktúry cez API)
* Pridaný action hook sf_after_invoice_create (spustí sa po úspešnom vytvorení faktúry cez API)

= 1.50.4 =
* Opravená kontrola odlišnej dodacej adresy zákazníka

= 1.50.3 =
* Doplnené spracovanie štátu (state, nie country) vo fakturačnej a dodacej adrese zákazníka

= 1.50.2 =
* Opravené preklady do čestiny

= 1.50.1 =
* Doplnený filter sf_fee_data

= 1.50.0 =
* Pridaná podpora fakturácie na firmu pre WooCommerce Checkout blok

Kompletný zoznam zmien nájdete v súbore [changelog.txt](https://plugins.svn.wordpress.org/woocommerce-superfaktura/trunk/changelog.txt).
