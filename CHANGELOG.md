## Zmiany (Changelog)

### 0.9.27-rc11 — synchronizacja klasy podatku (#51); naprawa fałszywych ostrzeżeń (#52); sprzątanie i dokumentacja (#53)

- **Nowość: synchronizacja klasy podatku (`tax_class`, #51).** Nowe pole do synchronizacji „Klasa
  podatku (tax class)" — kopiuje `tax_class` ze źródła na cel dla produktów prostych, wariantowych
  i pojedynczych wariacji. Wartość jest kopiowana zawsze, nawet gdy cel nie ma takiej klasy
  skonfigurowanej (produkt i tak dostaje wartość ze źródła), z ostrzeżeniem w logu do ręcznej
  weryfikacji.
- **[krytyczne] Naprawiono fałszywe ostrzeżenia o brakującej klasie podatku (#52).** Wykrywanie
  klas podatku na celu odwoływało się do nieistniejącej funkcji WooCommerce (`wc_get_tax_classes()`)
  — po cichu zwracała pustą listę (osłonięta `function_exists()`), więc wtyczka zawsze zakładała, że
  cel nie ma **żadnej** klasy podatku skonfigurowanej, i logowała ostrzeżenie dla każdego produktu z
  niestandardową klasą, nawet gdy klasa faktycznie istniała (np. wbudowane `reduced-rate`,
  `zero-rate`). Naprawione użyciem prawdziwego API (`WC_Tax::get_tax_class_slugs()`). Sama wartość
  `tax_class` zawsze synchronizowała się poprawnie — błąd dotyczył wyłącznie fałszywego logowania.
- **Sprzątanie i dokumentacja (#53).** Usunięto martwy fragment kodu z wykrywania klas podatku
  (porównanie z nigdy nie ustawianym kluczem), bez zmiany zachowania — zweryfikowane e2e. README
  opisuje teraz opcję „Klasa podatku" w tabeli pól do synchronizacji.

### 0.9.27-rc10 — synchronizacja pola backorders dla wariacji (#48); dokumentacja opcji panelu

- Gdy wariacja na źródle ma `backorders` włączone (`yes`/`notify`) i ujemny/zerowy stan magazynowy,
  słusznie liczy się jako `onbackorder`. `apply_stock()` kopiowało `stock_quantity` i `stock_status`,
  ale nigdy `backorders` — a WooCommerce **przelicza `stock_status` z `manage_stock` +
  `stock_quantity` + `backorders` przy każdym zapisie produktu** (`WC_Product::save()` →
  `validate_props()`), więc jawnie ustawiony `stock_status` był po cichu nadpisywany. Bez
  `backorders` cel zawsze dostawał `outofstock` zamiast poprawnego `onbackorder`. Naprawione:
  `backorders` jest teraz kopiowane razem z resztą pól magazynowych.
- Dokumentacja: README opisuje teraz opcje **„Typy produktów"**, **„Statusy w źródle"** i **„Pola do
  synchronizacji"** z panelu wtyczki — w tym, że „Stan magazynowy" to jedna jednostka
  (`manage_stock`/`stock_quantity`/`backorders`/`stock_status` razem) i dlaczego.

### 0.9.27-rc9 — total sync nie duplikuje już produktów przy niejednoznacznym dopasowaniu po nazwie (#42)

- **[krytyczne]** Gdy 2+ nieprzypisane produkty na celu mają tę samą nazwę, total sync tworzył
  kolejny duplikat przy **każdym** przebiegu. Teraz taki przypadek jest pomijany i logowany do
  ręcznej weryfikacji; zwykłe, nowe produkty nadal tworzą się normalnie.
- Ten sam problem naprawiony w zwykłej synchronizacji (dopasowanie po nazwie przy 2+ kandydatach).
- Ochrona przed #32 (total sync nie adoptuje obcych produktów po nazwie) pozostaje bez zmian.

### 0.9.27-rc8 — kolizja SKU wariacji (#36) i total sync po nazwie (#32); i18n, logi, readme.txt

- **[krytyczne]** Ochrona przed kolizją SKU wariacji — gdy SKU wariacji ze źródła zajmuje na celu
  inny produkt, jest jednoznacznie sufiksowane; idempotentne przy ponownej synchronizacji (#36).
- **[krytyczne]** Total sync dopasowuje teraz wyłącznie po SKU/`_wps_source_id` — obce produkty o
  zbieżnej nazwie nie przeżywają już kasowania przy lustrzeniu źródła (#32).
- Dopasowanie po nazwie obejmuje też statusy draft/private, nie tylko publish (#25).
- Bardziej szczegółowe logi dla pojedynczych akcji na produktach (#34).
- Internacjonalizacja — wszystkie napisy UI opakowane w funkcje tłumaczeń (#23).
- readme.txt w stylu WordPress.org (#26); CI: krok PHPCS-WP (#22); testy e2e trybu promocji (#18/#21).

### 0.9.27-rc6 — kontrola propagacji cen promocyjnych ze źródła (#18)

- Tryb promocji (`price_promotion_mode`) — jak cena promocyjna ze źródła jest odzwierciedlana na
  celu: kopiuj bez zmian (domyślne) / cena promocyjna → podstawowa / cena przed promocją → podstawowa.

### 0.9.27 — total sync, scalanie/cofanie synchronizacji, [krytyczne] naprawa dopasowania po nazwie

- Modyfikator ceny przy synchronizacji — procent i/lub kwota stała względem źródła, z wyborem
  zaokrąglenia (#14).
- **[krytyczne]** Nieudany zapis wariacji nie kasuje już istniejących wariacji (#15).
- Produkt wariantowy nie jest tworzony „na pusto" przy braku wariacji (#15).
- Harmonogram: godzina działa i respektuje strefę czasu witryny, nie UTC (#12).
- **Total sync** (lustro źródła) — scala po SKU/nazwie, synchronizuje cały katalog, twardo usuwa
  brakujące; wymaga potwierdzenia własnego backupu.
- **[krytyczne]** Symulacja i scalanie nie przekraczają już limitu czasu serwera — chodzą w tle i
  batchowo, jak zwykła synchronizacja.
- Wybór kanału aktualizacji w panelu (Stabilny / Testowy RC).
- **[krytyczne]** Dopasowanie po nazwie nigdy nie działało — `find_existing_product()` używało
  `post_title`, parametru którego WP_Query nie zna; naprawione (`title`).
- Scalanie istniejących produktów (`adopt_existing`) z podglądem przed zapisem.
- Cofanie ostatniej synchronizacji (`undo_run`) — przenosi do kosza produkty utworzone w ostatnim
  przebiegu.
- Endpoint atrybutów przestał być wymagany — mapa odtwarzana z payloadów produktów.
- Nieodwzorowana wariacja to teraz błąd, nie ciche pominięcie.

### 0.9.26 — czytelny błąd przy braku dostępu do atrybutów globalnych

- Gdy `/products/attributes` zwracał 401, raport pokazywał ogólnik bez kodu HTTP. Komunikat niesie
  teraz kod HTTP, adres endpointu i wskazówkę o uprawnieniu `manage_product_terms`.
- Błąd loguje się teraz jako `error` (było: `warning`).

### 0.9.25 — updater działa domyślnie, bez konfiguracji

- Wbudowany publiczny kanał aktualizacji — działa od razu w **Wtyczki → Aktualizuj**, bez stałej w
  `wp-config.php`. Instalacje ≤ 0.9.24 nie znają wbudowanego adresu — trzeba raz wgrać ręcznie.
- Nadal nadpisywalne w obie strony: `WC_PRODUCT_SYNC_UPDATE_URL` / `WC_PRODUCT_SYNC_UPDATE_TOKEN`.

### 0.9.24 — [krytyczne] błąd pobierania raportowany jako sukces

- 401 ze źródła (złe klucze, brak dostępu, HTTP zamiast HTTPS) był logowany, ale nie liczył się jako
  błąd — przebieg kończył się zielonym „Zakończono" mimo awarii.
- Puste źródło mogło wyczyścić cały katalog przy `force_full_sync` + `deletion_mode=hard`. Naprawa:
  zerowy widok źródła w świeżym przebiegu blokuje teraz wszystkie ścieżki usuwania.

### 0.9.23 — [krytyczne] nieudane pobranie obrazu kasowało obrazy lokalne

- Gdy pobranie nowego obrazu się nie udawało (blip sieci, TLS, 502), stary obraz i tak znikał z
  produktu — utrata danych zgłaszana jako czysty przebieg. Naprawa: obrazy i mapa zostają nietknięte
  przy błędzie pobrania, a błąd jest liczony i raportowany.

### 0.9.22 — token dla prywatnego serwera aktualizacji

- `WC_PRODUCT_SYNC_UPDATE_TOKEN` pozwala pobierać `update.json` i ZIP z prywatnego repozytorium
  (Forgejo/Gitea); token dołączany tylko do żądań na host z `UPDATE_URL`.

### 0.9.21 — aktualizacje z własnego serwera

- Updater z własnego serwera (opcjonalny) — `WC_PRODUCT_SYNC_UPDATE_URL`, aktualizacja jednym
  kliknięciem w **Wtyczki → Aktualizuj**. `build.sh` generuje `dist/update.json`.

### 0.9.20 — naprawa force-full (utrata danych / brak działania)

- Force-full było bramkowane warunkiem „pierwszy batch" ale wykonywane po całym przebiegu: na
  katalogach wielobatchowych nigdy się nie uruchamiało, na jednobatchowych kasowało właśnie
  zsynchronizowane produkty. Naprawa: usuwa tylko produkty nieodświeżone w bieżącym przebiegu,
  porównując znacznik `_wps_synced` ze startem przebiegu.

### 0.9.19 — bezpieczeństwo danych (przegląd Codex, runda 4)

- Usuwanie przy force-full przeniesione z przed na po pobraniu — awaria REST po skasowaniu nie
  wymazuje już całego katalogu.
- Przekroczenie capa 20 000 kluczy źródłowych blokuje usuwanie, zamiast fałszywie kasować produkty.

### 0.9.18 — poprawność cron

- Naprawiony wyciek `$fast_mode` między hookami WP-Cron w jednym żądaniu — codzienny sync nie
  degraduje się już do trybu update-only.

### 0.9.16–0.9.17 — szybki sync + UI/wydajność

- Szybka synchronizacja — cykliczne odświeżanie wybranych pól (cena/stan), tylko aktualizacja
  istniejących, własny interwał.
- Auto-odświeżanie postępu w adminie wyłączone domyślnie + ręczny przycisk „Odśwież postęp".
- Dokumentacja wydajności: `docs/PERFORMANCE.md`.

### 0.9.13–0.9.15 — poprawność rollupu wariacji + skalowanie

- Rollup ceny/stanu rodzica wołany tylko przy realnej zmianie, nie na każdym no-op update.
- Cap 20 000 kluczy źródłowych w akumulacji soft-delete.

### 0.9.x — wsadowanie, wznawianie, obrazy inkrementalne

- Batching z wznawianiem (`sync_batch_limit`, `max_batch_seconds`) — żaden batch nie ginie na
  limicie czasu PHP.
- Inkrementalna mapa obrazów (`_wps_image_map`) — re-sync pobiera tylko zmienione obrazy.
- Ochrona przed timeoutem: `set_time_limit(900)` + `ignore_user_abort(true)`.
- Tryby usuwania `deletion_mode` = `none` / `soft` / `hard`.

### 0.5.0
- **M2:** Lock współbieżności (transient TTL 900s), `set_time_limit(600)`, `ignore_user_abort(true)`
- **M3:** Guard dry-run — zweryfikowano brak side-effects w ścieżce symulacji
- **M4:** Filtr statusów przez `apply_filters('wps_sync_statuses')` — domyślnie tylko `publish`
- **M5:** Optymalizacja pamięci — page-by-page processing z `gc_collect_cycles()` zamiast ładowania całego katalogu do RAM

### 0.4.0
- **C3:** Naprawa fatal error przy shutdown hook (private → public method)
- **C1:** Soft-delete używa `get_posts()` zamiast `wc_get_products()` — poprawne meta_query i orderby
- **C2:** Blokada usunięcia wariacji przy błędzie fetch API
- **C4:** Identyfikacja grouped bez SKU przez `_wps_source_id` meta
- **M1:** Naprawa wipe ustawień gdy stałe w wp-config.php

### 0.3.0
Pierwsza publiczna wersja — podstawowa synchronizacja simple/variable/grouped po SKU.
