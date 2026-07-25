=== WC Product Sync (SKU) ===
Contributors: mpanczyk
Tags: woocommerce, product-sync, sku, batch-import
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.9.27-rc7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Codzienna synchronizacja produktów ze zdalnego sklepu WooCommerce do tego sklepu, dopasowanie po SKU (lub nazwie), obsługa simple/variable/grouped.

== Description ==

Codzienna synchronizacja produktów ze zdalnego sklepu WooCommerce (**źródło**) do TEGO sklepu (**cel**). Dopasowanie po **SKU**, a gdy go brak — po `_wps_source_id`, następnie po nazwie. Obsługa: `simple`, `variable`, `grouped`. Zapisy lokalnie przez WooCommerce CRUD; na źródle wtyczka **niczego nie zmienia** (same odczyty REST).

= Funkcje =

* Dopasowanie po SKU, `_wps_source_id` lub nazwie produktu
* Obsługa produktów: simple, variable, grouped
* Synchronizacja cen (regular + sale), stock, opisów, kategorii, atrybutów, obrazów
* Wbudowany updater — nowe wersje pojawiają się w **Wtyczki → Aktualizuj** jednym kliknięciem
* Własny serwer aktualizacji (opcjonalnie) — `WC_PRODUCT_SYNC_UPDATE_URL`
* Token dla prywatnych repozytoriów (Forgejo/Gitea) — `WC_PRODUCT_SYNC_UPDATE_TOKEN`
* Szybka synchronizacja pól (cena/stan) — cykliczna, lekki harmonogram
* Usuwanie znikniętych produktów: soft lub hard delete
* Tryb dry-run (symulacja) przed faktyczną synchronizacją
* Wsadowanie z wznawianiem — duże katalogi nie giną na limicie czasu PHP
* Blokada współbieżności — brak równoległych synców
* Logowanie do WooCommerce → Status → Logi (`wc-product-sync`)

= Endpointy źródła =

Wszystkie żądania idą do **źródła** i są tylko do odczytu (`GET`). Wtyczka **nic nie zapisuje** na źródle.

* `GET /wp-json/wc/v3/products` — główny katalog (`read_private_products`)
* `GET /wp-json/wc/v3/products?type=grouped` — domknięcie grouped
* `GET /wp-json/wc/v3/products/{id}/variations` — wariacje (variable)
* `GET <adres obrazka>` — pobranie obrazów (bez autoryzacji)

Wystarczy klucz z uprawnieniem **„Odczyt"** na koncie Administratora lub Kierownika sklepu.

= Ograniczenia =

* Atrybuty tylko na produktach `variable` — nie są przenoszone na simple/grouped
* Produkty powiązane (upsells/cross-sells) nie są przenoszone
* Custom meta fields poza `_wps_*` nie są przenoszone
* Tagi produktowe (poza `wps-usuniete`) nie są przenoszone
* Zmiana typu produktu na źródle może utworzyć duplikat

== Installation ==

1. Pobierz ZIP z [Wydania](https://git.panczyk.cc/mpanczyk/wc-product-sync/releases) lub zbuduj sam: `./build.sh` → `dist/wc-product-sync-<wersja>.zip`
2. Zainstaluj przez panel (Wtyczki → Dodaj nową → Wyślij wtyczkę), wp-cli (`wp plugin install ./wc-product-sync-<wersja>.zip --activate`) lub ręcznie na SSH — rozpakuj do `wp-content/plugins/wc-product-sync/`
3. Aktywuj wtyczkę i skonfiguruj URL źródła oraz Consumer Key/Secret w menu **WooCommerce → Synchronizacja produktów** (lub przez stałe w `wp-config.php` — zalecane)
4. Uruchom Symulację (dry run), aby zobaczyć co zostanie zmienione, bez zapisu do bazy

= Wymagania =

* WordPress ≥ 6.0
* PHP ≥ 7.4
* Aktywne WooCommerce

= Klucze API — dwie metody =

**Metoda 1: Formularz w adminie** (domyślna) — wypełnij Consumer Key i Secret w ustawieniach wtyczki.

**Metoda 2: Stałe w `wp-config.php`** (zalecane):

~~~php
define( 'WC_PRODUCT_SYNC_SOURCE_URL', 'https://zrodlo.pl' );
define( 'WC_PRODUCT_SYNC_CK', 'ck_xxx' );
define( 'WC_PRODUCT_SYNC_CS', 'cs_xxx' );
~~~

Gdy stałe są zdefiniowane, pola w formularzu stają się nieaktywne.

= Aktualizacje =

Nowe wersje pojawiają się w **Wtyczki → Aktualizuj**, aktualizacja jednym kliknięciem — bez ponownego wgrywania ZIP-a. Działa domyślnie, bez konfiguracji.

**Instalacje ≤ 0.9.24 nie znają wbudowanego adresu**, więc same się nie zaktualizują. Trzeba **raz** wgrać nowszą wersję ręcznie; potem updater działa sam.

Własny serwer aktualizacji (opcjonalnie):

~~~php
define( 'WC_PRODUCT_SYNC_UPDATE_URL', 'https://twoj-serwer.pl/wc-product-sync/update.json' );
define( 'WC_PRODUCT_SYNC_UPDATE_TOKEN', 'xxxxxxxx' ); // prywatne repozytorium
// define( 'WC_PRODUCT_SYNC_UPDATE_URL', '' );        // wyłącza updater całkowicie
~~~

Metadane są cache'owane **12 h** (sukces) / **2 h** (błąd). Wymuszenie: `wp transient delete wps_update_info`.

== Changelog ==

= 0.9.27-rc7 =

* [krytyczne] Ochrona przed kolizją SKU wariacji — gdy SKU wariacji ze źródła zajmuje na celu inny produkt, SKU jest jednoznacznie sufiksowane; przy ponownej synchronizacji zachowywane bez cofania do kolidującej wartości (idempotentne) (#36)
* [krytyczne] Total sync pomija dopasowanie po nazwie — obce produkty o zbieżnej nazwie nie „przeżywają" już kasowania przy lustrzeniu źródła (#32)
* Dopasowanie po nazwie obejmuje statusy draft/private, nie tylko publish — brak duplikatów przy synchronizacji nieopublikowanych produktów (#25)
* Bardziej szczegółowe logi WooCommerce dla pojedynczych akcji na produktach (utworzenie/aktualizacja/pominięcie) (#34)
* Internacjonalizacja — wszystkie napisy UI opakowane w funkcje tłumaczeń, dołączony katalog `.pot` (#23)
* readme.txt w stylu WordPress.org (metadane wtyczki) (#26)
* CI: krok PHPCS-WP w pipeline (#22)
* Testy e2e pokrywają tryb promocji cen (keep / promo_to_base / base_after_promo) (#18/#21)

= 0.9.27 =

* Modyfikator ceny przy synchronizacji — procent i/lub kwota stała względem źródła, z wyborem zaokrąglenia
* Nieudany zapis wariacji nie kasuje już istniejących wariacji (naprawa #15)
* Produkt wariantowy nie jest tworzony „na pusto" przy braku wariacji (#15)
* Harmonogram: godzina działa i respektuje strefę czasu (#12)
* Total sync (lustro źródła) — synchronizacja całego katalogu + twardy usuwanie brakujących
* Symulacja i scalanie nie przekraczają już limitu czasu serwera (batchowanie w tle)
* Wybór kanału aktualizacji w panelu (Stabilny / Testowy RC)
* Naprawa dopasowania po nazwie (poprawne `title` zamiast `post_title`) (#15)
* Scalanie istniejących produktów (`adopt_existing`) z podglądem przed zapisem
* Cofanie ostatniej synchronizacji (`undo_run`) — przenosi do kosza produkty utworzone w ostatnim przebiegu
* Endpoint atrybutów przestał być wymagany — mapa atrybutów odtwarzana z payloadów produktów
* Nieodzwzorowana wariacja to błąd, nie ciche pominięcie

= 0.9.27-rc6 =

* Tryb promocji (`price_promotion_mode`) — możliwość kontroli, jak cena promocyjna ze źródła jest odzwierciedlana na celu (kopiuj bez zmian / cena promocyjna → podstawowa / cena przed promocją → podstawowa)

= 0.9.26 =

* Czytelny błąd przy braku dostępu do atrybutów globalnych — komunikat z kodem HTTP i podpowiedzią uprawnień
* Błąd loguje się jako `error` (było: `warning`)

= 0.9.25 =

* Wbudowany publiczny kanał aktualizacji (`DEFAULT_UPDATE_URL`) — updater działa domyślnie bez konfiguracji
* Nadpisywalne w obie strony przez stałe `WC_PRODUCT_SYNC_UPDATE_URL` i `WC_PRODUCT_SYNC_UPDATE_TOKEN`

= 0.9.24 =

* [krytyczne] Błąd pobierania źródła (401, brak uprawnień) był logowany ale nie zwiększał licznika błędów — przebieg wyglądał jak sukces
* Puste źródło mogło wyczyścić cały katalog przy `force_full_sync` + `deletion_mode=hard`
* Naprawa: zerowy widok źródła to błąd, blokuje wszystkie ścieżki usuwania

= 0.9.23 =

* [krytyczne] Nieudane pobranie obrazu kasowało obrazy lokalne — przy awarii sieci produkty traciły zdjęcia bez zgłoszenia błędu
* Naprawa: obrazy zostają nietknięte przy nieudanym pobraniu; błąd jest reportowany

= 0.9.22 =

* Token dla prywatnego serwera aktualizacji (`WC_PRODUCT_SYNC_UPDATE_TOKEN`)

= 0.9.21 =

* Updater z własnego serwera (opcjonalny) — `WC_PRODUCT_SYNC_UPDATE_URL`
* `build.sh` generuje `dist/update.json` dla updatera

= 0.9.20 =

* [krytyczne] Force-full działał tylko pozornie — nie uruchamiał się na batchach wznowienia, a przy jednym batchu usuwał właśnie zsynchronizowane produkty
* Naprawa: kasuje wyłącznie produkty, których nie odświeżono w bieżącym przebiegu

= 0.9.19 =

* Usunięcie przy force-full przeniesione z przed pobierania na po przetworzeniu — awaria REST nie wymazuje katalogu bez możliwości odzyskania
* Przekroczenie capa 20 000 kluczy źródłowych blokuje usuwanie na niepełnym widoku

= 0.9.18 =

* Naprawiony wyciek `$fast_mode` między hookami WP-Cron — codzienny sync nie degradował się do trybu update-only

= 0.9.16–0.9.17 =

* Szybka synchronizacja pól (cena/stan) — cykliczna, lekki harmonogram
* Auto-odświeżanie postępu wyłączone domyślnie + ręczny przycisk „Odśwież postęp"

= 0.9.13–0.9.15 =

* Naprawa rollupu wariacji (min/max ceny) wołany tylko przy realnej zmianie
* Cap 20 000 kluczy źródłowych w soft-delete

= 0.9.x =

* Wsadowanie z wznawianiem (`sync_batch_limit`, `max_batch_seconds`)
* Inkrementalna mapa obrazów (`_wps_image_map`)
* Ochrona przed timeoutem: `set_time_limit(900)` + `ignore_user_abort(true)`
* Tryby usuwania `deletion_mode` = none / soft / hard

= 0.5.0 =

* Lock współbieżności, dry-run guard, filtr statusów (`wps_sync_statuses`), optymalizacja pamięci page-by-page

= 0.4.0 =

* Naprawa fatal error przy shutdown hook, soft-delete poprawione meta_query, blokada usunięcia wariacji przy błędzie fetch API

= 0.3.0 =

* Pierwsza publiczna wersja — podstawowa synchronizacja simple/variable/grouped po SKU.

== Upgrade Notice ==

= 0.9.27 =
Krytyczne naprawie: dopasowanie po nazwie, bezpieczny zapis wariacji, batchowanie dry-run/scalania, total sync i cofanie syncu. Zalecana aktualizacja.

= 0.9.27-rc6 =
Nowy tryb promocji cen (price_promotion_mode) — kompatybilne wstecz, domyślne zachowanie niezmienione.

= 0.9.24 =
[krytyczne] Błędy pobierania źródła były raportowane jako sukces — zalecana pilna aktualizacja.

= 0.9.23 =
[krytyczne] Awaria pobrania obrazu mogła kasować zdjęcia lokalne — zalecana pilna aktualizacja.
