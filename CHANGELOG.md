## Zmiany (Changelog)

### 0.9.27 (current) — obejście `/products/attributes`, scalanie i cofanie synchronizacji, total sync, [krytyczne] naprawa dopasowania po nazwie

- **Harmonogram: godzina działa i respektuje strefę czasu (#12).** Dwie usterki. (1) Zmiana godziny
  uruchomienia **nie przeplanowywała** już zaplanowanego zadania — ustawienie „nie działało", dopóki nie
  wyłączyłeś i włączyłeś harmonogramu. Reconciler przenosi teraz zadanie, gdy zapisana godzina/minuta się
  różni od zaplanowanej. (2) Godzina była liczona przez `mktime()` na częściach `gmdate()`, czyli
  **jako UTC**, choć pole jest opisane jako czas WordPressa — na strefie +2 wpisanie **01:00** dawało
  uruchomienie o **03:00**. Czas liczony jest teraz w strefie witryny (`wp_timezone()`), więc wpisana
  godzina znaczy to, co obiecuje etykieta. Zweryfikowane e2e (strefa +2: 01:30 planuje 01:30 lokalnie,
  edycja godziny przenosi przebieg).
- **Total sync (lustro źródła).** Nowy, oddzielny przycisk w sekcji „Uruchomienie ręczne", który robi
  sklep **lustrem źródła**: (1) scala istniejące produkty po **SKU i nazwie**, (2) synchronizuje cały
  katalog źródła, (3) **twardo usuwa** (bez kosza) każdy produkt na celu, którego nie ma na źródle.
  Uruchamia się **tylko gdy źródło udostępnia > 0 produktów** (mirror pustego/niedostępnego źródła
  wykasowałby cały sklep — zablokowane; ta sama ochrona działa też, jeśli źródło padnie w trakcie).
  Wtyczka **nie robi kopii** — wymaga potwierdzenia „mam własną kopię bazy" i podwójnego potwierdzenia
  (zalecany wcześniej podgląd scalania). Całość chodzi **w tle i batchowo**. Zweryfikowane e2e.
- **[krytyczne] Symulacja i scalanie nie przekraczają już limitu czasu serwera.** Dry run i „Scal
  istniejące" robiły całą robotę w jednym żądaniu — na dużym katalogu mod_fcgid ubijał je po swoim
  limicie (na niektórych hostingach 31 s) i zwracał **500 Internal Server Error**. Obie operacje
  chodzą teraz **w tle i batchowo**, jak zwykła synchronizacja: każdy fragment mieści się pod limitem
  czasu, a postęp i wynik pojawiają się w panelu. Zweryfikowane e2e (wymuszony podział na batche).
- **Wybór kanału aktualizacji w panelu.** Lista „Kanał aktualizacji": **Stabilny** (kanał produkcyjny)
  lub **Testowy (RC)** (kandydaci do wydania). WordPress nie ma natywnego wyboru kanału dla wtyczek — to
  ustawienie kieruje updater na właściwy `update.json` bez edycji `wp-config.php`. Stała
  `WC_PRODUCT_SYNC_UPDATE_URL` (jeśli ustawiona) ma pierwszeństwo i blokuje pole. Zmiana kanału od razu
  odświeża sprawdzanie aktualizacji.
- **[krytyczne] Dopasowanie po nazwie nigdy nie działało.** `find_existing_product()` używało
  `get_posts( "post_title" => ... )`, a WP_Query **nie zna** parametru `post_title` (poprawny to
  `title`) — filtr był po cichu ignorowany. Skutki: przy **jednym** kandydacie na celu każdy
  niedopasowany produkt źródła fałszywie „dopasowywał się" do niego (**nadpisanie złego produktu**);
  przy **wielu** — zapytanie zawsze widziało 2 i nigdy nie dopasowywało, więc produkty bez zgodnego
  SKU lądowały jako **duplikaty**. Naprawione (`title`), zweryfikowane e2e.
- **Scalanie istniejących produktów** (`adopt_existing`) — nadaje produktom założonym poza wtyczką
  znacznik `_wps_source_id` po SKU lub **jednoznacznej nazwie**, dzięki czemu kolejna synchronizacja je
  **aktualizuje zamiast tworzyć duplikaty**. Przycisk **„Scal istniejące — podgląd"** pokazuje plan
  (co z czym, wieloznaczne osobno) przed zapisem; nic nie rusza produktów już przypisanych.
- **Cofanie ostatniej synchronizacji** (`undo_run`) — przenosi do **kosza** (odwracalnie) produkty
  **utworzone** w ostatnim przebiegu; nie rusza zaktualizowanych ani starych sklepowych. Produkty są
  znakowane `_wps_created_run` przy tworzeniu; dla przebiegów sprzed tej wersji działa fallback po
  `_wps_source_id` + dacie utworzenia. Przycisk **„Cofnij ostatnią synchronizację (N)"**.
- **Endpoint atrybutów przestał być wymagany.** WooCommerce pilnuje `/products/attributes`
  uprawnieniem **`manage_product_terms`**, a `/products` tylko `read_private_products` — więc klucz
  API potrafi czytać **cały katalog** i mimo to dostawać `401` na atrybutach. Do 0.9.26 **przerywało
  to każdy przebieg**, a sklepy, które nie mogą dostać szerszego klucza, nie miały jak synchronizować.
- **Co się okazało:** mapa z tego endpointu niosła tylko **dwa pola** (`name` + `slug`), a WooCommerce
  wysyła oba **inline w każdym produkcie i wariacji**:
  `"attributes":[{"id":1,"name":"Kolor","slug":"pa_kolor","options":[…]}]`.
  Wtyczka odtwarza więc mapę z payloadów. Pozostałe pola endpointu (`type`, `order_by`,
  `has_archives`) i tak nigdy nie były używane — przy tworzeniu atrybutu są zakładane na sztywno.
- **Efekt: endpoint nie jest wołany w ogóle.** Skoro payload wystarcza, żądanie po prostu nie
  powstaje — a niewysłane żądanie nie może zwrócić 401, nie wymaga uprawnienia, którego klucz nie ma,
  i nie zaśmieca loga ostrzeżeniem przy każdym przebiegu. Endpoint zostaje wyłącznie jako **ostateczny
  fallback**, sięgany leniwie (maks. raz na przebieg), gdyby jakiś payload nie miał ani nazwy, ani
  sluga (bardzo stare WooCommerce).
- Zweryfikowane na rigu: pełny sync katalogu z atrybutami globalnymi → **0 trafień** w
  `/products/attributes`, 50 produktów, `błędy=0`, taksonomia `pa_kolor` i wariacje na celu.
- **Bezpiecznik zamiast cichego czyszczenia:** atrybut, którego naprawdę nie da się odwzorować, nie
  jest już po cichu pomijany (co dla `variable` znaczyło przebudowę **bez atrybutów**). Produkt jest
  **pomijany i raportowany jako błąd**, a jego dane zostają nietknięte.

- **Nieodwzorowana wariacja to teraz błąd, nie ciche pominięcie.** Wariacja, której atrybutu
  globalnego nie dało się odwzorować, była pomijana z logiem `warning`, a przebieg meldował
  `błędy=0` — produkt zmienny wychodził bez wariacji, wyglądając na czysty sync. Teraz rodzic jest
  liczony jako błąd, a powód trafia do raportu.

### 0.9.26 — czytelny błąd przy braku dostępu do atrybutów globalnych

- **Powód przerwania przebiegu widać wreszcie w adminie.** Gdy nie udało się pobrać
  `/products/attributes`, raport pokazywał tylko ogólnik „Nie pobrano definicji atrybutów globalnych",
  bez kodu HTTP — a prawdziwa przyczyna (`HTTP 401`) leżała wyłącznie w logu, jako `warning`. Teraz
  komunikat niesie **kod HTTP, adres endpointu i wskazówkę**, że ten endpoint wymaga uprawnienia
  **`manage_product_terms`** (Administrator / Kierownik sklepu) — **innego** niż czytanie produktów.
- To jest realna pułapka: klucz API potrafi działać na `/products` i **jednocześnie** dostawać 401 na
  `/products/attributes`, bo WooCommerce pilnuje tych endpointów różnymi uprawnieniami. Objawiało się
  to jako przerwany przebieg bez zrozumiałego powodu.
- Błąd loguje się teraz jako `error` (było: `warning`).

### 0.9.25 — updater działa domyślnie, bez konfiguracji

- **Wbudowany publiczny kanał aktualizacji** (`DEFAULT_UPDATE_URL`). Repozytorium jest publiczne, więc
  świeża instalacja dostaje aktualizacje w **Wtyczki → Aktualizuj** bez stałej w `wp-config.php` i bez
  tokenu. Wcześniej updater był wyłączony, dopóki nie zdefiniowało się `WC_PRODUCT_SYNC_UPDATE_URL` —
  co w praktyce znaczyło, że nikt go nie miał włączonego i „nie było opcji aktualizacji".
- **Nadpisywalne w obie strony:** `define( 'WC_PRODUCT_SYNC_UPDATE_URL', 'https://…' )` kieruje na
  własny serwer, a pusta wartość (`''`) **wyłącza updater całkowicie** (zero zapytań HTTP). Token
  (`WC_PRODUCT_SYNC_UPDATE_TOKEN`) nadal działa dla prywatnych repozytoriów — publiczne go nie
  potrzebują.
- **Uwaga dla instalacji ≤ 0.9.24:** nie znają wbudowanego adresu, więc **nie zaktualizują się same**.
  Trzeba raz wgrać 0.9.25 ręcznie (albo dodać stałą) — potem updater działa automatycznie.
- Zweryfikowane end-to-end na efemerycznym sklepie: instalacja 0.9.23 → panel oferuje 0.9.24 → `wp
  plugin update` (ta sama ścieżka co przycisk w adminie) pobiera z publicznego adresu, instaluje,
  wtyczka zostaje aktywna i się ładuje.

### 0.9.24 — [krytyczne] błąd pobierania raportowany jako sukces

- **[krytyczne] 401 ze źródła wyglądał jak udana synchronizacja.** Błąd pobierania (złe klucze API,
  klucz użytkownika bez dostępu do produktów, źródło pod `http://` — WooCommerce przyjmuje Basic auth
  **tylko po HTTPS**) był logowany, ale **nie zwiększał licznika błędów**. Przebieg kończył się
  `0/0/0`, `błędy: 0` i **zielonym** komunikatem „Zakończono". Operator nie miał jak odróżnić awarii
  uwierzytelniania od pustego katalogu.
- **[krytyczne] Puste źródło (HTTP 200 + pusta lista) mogło wyczyścić cały katalog.** Klucz API
  przypisany do użytkownika bez dostępu do produktów zwraca „brak produktów", a nie „brak uprawnień".
  Przy `force_full_sync` / `deletion_mode=hard` oznaczało to „wszystko zniknęło ze źródła" → skasowanie
  **całego** lokalnego katalogu na podstawie złego klucza.
- **Naprawa:** zerowy widok źródła w świeżym przebiegu jest teraz **błędem** i oznacza pobranie jako
  niepewne (`fetch_had_error`), co blokuje **wszystkie** ścieżki usuwania (ten sam bezpiecznik co przy
  błędzie REST i przekroczeniu capa kluczy, v0.9.19). Źródło realnie puste zsynchronizuje zero i
  **nie usunie niczego** — odmowa działania jest odwracalna, skasowany katalog nie.
- **Widoczność:** błędy pobierania (strony produktów i atrybutów globalnych) zwiększają licznik
  `błędy`, trafiają do raportu przebiegu, a komunikat w adminie jest **czerwony** (`notice-error`),
  gdy błędy > 0 **lub** gdy nie dotknięto ani jednego produktu — z podpowiedzią najczęstszych przyczyn
  (klucze, uprawnienie „Odczyt", źródło po HTTP).
- Regresja przykryta testem e2e (faza 4): puste źródło + `force_full_sync=1` → katalog na celu
  **nienaruszony**, przebieg raportuje `błędy=1`.

### 0.9.23 — [krytyczne] nieudane pobranie obrazu kasowało obrazy lokalne

- **[krytyczne] Utrata danych przy chwilowym błędzie sieci.** Gdy źródło podmieniło obraz produktu,
  a cel nie zdołał pobrać nowego (blip sieci, niezgodność TLS, 502), `sync_product_images()` szło
  dalej: nowy klucz nie trafiał do mapy `_wps_image_map`, więc pass sprzątający **usuwał dotychczasowe
  załączniki**, a pusta lista **czyściła obrazy z produktu**. Trwała utrata lokalnych danych z powodu
  **przejściowego** błędu — zgłoszona jako czysty przebieg (`błędy=0`).
- **Naprawa:** przy jakimkolwiek nieudanym pobraniu obrazy produktu i mapa **zostają nietknięte**
  (kasowane są tylko załączniki utworzone w tym przebiegu, żeby nie osierocić). Następny przebieg
  ponawia próbę z niezmienionej mapy.
- **Księgowanie błędów:** nieudane pobranie obrazu loguje się teraz jako `error` (było: `warning`)
  **i zwiększa licznik `błędy`** w podsumowaniu przebiegu oraz w raporcie. Produkt nadal dostaje
  `_wps_synced` — inaczej force-full skasowałby go przy następnym przebiegu za to, że „nie został
  odświeżony".
- Regresja przykryta testem e2e (faza 3): podmiana obrazu na źródle + zerwane pobieranie → obrazy na
  celu **przeżywają**, a przebieg **raportuje błąd**. Test zweryfikowany na starym kodzie: 3 → 2 obrazy
  i `błędy=0`.

### 0.9.22 — token dla prywatnego serwera aktualizacji
- **Obsługa tokenu w updaterze:** stała `WC_PRODUCT_SYNC_UPDATE_TOKEN` pozwala pobierać `update.json` i ZIP z prywatnego repozytorium (Forgejo/Gitea). Nagłówek `Authorization: token …` dołączany jest tylko do żądań na host z `UPDATE_URL` (również do pobrania ZIP-a realizowanego przez rdzeń WP przez filtr `http_request_args`), więc token nie wycieka do innych serwerów.

### 0.9.21 — aktualizacje z własnego serwera
- **Updater z własnego serwera (opcjonalny):** po ustawieniu stałej `WC_PRODUCT_SYNC_UPDATE_URL` nowe wersje pojawiają się w panelu **Wtyczki → Aktualizuj** (aktualizacja jednym kliknięciem, bez ponownego wgrywania ZIP-a). Bez tej stałej mechanizm jest w pełni wyłączony (żadnych zapytań HTTP). Szczegóły: sekcja „Aktualizacje z własnego serwera".
- `build.sh` generuje teraz obok ZIP-a plik `dist/update.json` (metadane dla updatera) na podstawie nagłówka wtyczki.

### 0.9.20 — naprawa force-full (utrata danych / brak działania)
- **[krytyczne] Force-full działał tylko pozornie:** kasowanie było bramkowane warunkiem „pierwszy batch", a wykonywane dopiero po zakończeniu przebiegu. Dla katalogów dzielonych na batche (koniec wypada na batchu wznowienia) **nigdy się nie uruchamiało**; dla katalogów mieszczących się w jednym batchu **kasowało właśnie zsynchronizowane produkty** (zapytanie usuwało *wszystkie* rekordy z `_wps_synced`, w tym te odświeżone w tym przebiegu).
- **Naprawa:** force-full usuwa teraz **wyłącznie** produkty, których **nie** odświeżono w bieżącym przebiegu — porównując znacznik `_wps_synced` ze znacznikiem startu przebiegu (`SELECT ... WHERE meta_key='_wps_synced' AND CAST(meta_value AS UNSIGNED) < <start>`). Znacznik startu jest utrwalany w `wps_last_sync_result` i przeżywa batche wznowienia, więc kasacja jest spójna dla katalogów jedno- i wielobatchowych.
- **Bezpiecznik:** brak wiarygodnego znacznika startu → kasacja **pomijana** (nigdy „na wszelki wypadek"). Nadal wymaga bezbłędnego pobrania całego katalogu. Usunięcia trafiają do raportu (`hard_deleted`).
- Zweryfikowane na środowisku testowym (katalog 492 produktów, 3 batche): force-full uruchomił się na batchu wznowienia i usunął **0** produktów przy pełnej parności ze źródłem (poprzednio: brak uruchomienia).

### 0.9.19 — bezpieczeństwo danych (przegląd Codex, runda 4)
- **P1 [krytyczne]:** usuwanie przy force-full przeniesione **z przed** pobierania **na po** przetworzeniu — awaria REST po skasowaniu nie wymazuje już całego katalogu bez możliwości odzyskania.
- **P2 [krytyczne]:** przekroczenie capa 20 000 kluczy źródłowych ustawia `had_error = true`, blokując usuwanie na niepełnym widoku źródła (wcześniej ważne produkty mogły zostać fałszywie usunięte).

### 0.9.18 — poprawność cron
- Naprawiony wyciek `$fast_mode` między hookami WP-Cron w jednym żądaniu — codzienny pełny sync nie degraduje się już do trybu update-only, gdy zdarzenie szybkiego synca odpali się w tym samym przebiegu wp-cron.

### 0.9.16–0.9.17 — szybki sync + UI/wydajność
- **Szybka synchronizacja** — cykliczne odświeżanie wybranych pól (cena/stan), tylko aktualizacja istniejących, własny interwał (patrz sekcja „Szybka synchronizacja").
- Auto-odświeżanie postępu w adminie **wyłączone domyślnie** (`admin_auto_refresh`) + ręczny przycisk „Odśwież postęp".
- Dokumentacja wydajności: `docs/PERFORMANCE.md`.

### 0.9.13–0.9.15 — poprawność rollupu wariacji + skalowanie
- `WC_Product_Variable::sync()` (rollup min/max ceny + statusu stanu rodzica) wołany **tylko** gdy realnie zaszła zmiana ceny/stanu/statusu/liczby wariacji — no-op update nie płaci za rollup.
- Cap 20 000 kluczy źródłowych w akumulacji soft-delete (ochrona pamięci na dużych katalogach).

### 0.9.x — wsadowanie, wznawianie, obrazy inkrementalne
- Batching z wznawianiem (`sync_batch_limit`, `max_batch_seconds`, item-level `page_offset`) — żaden batch nie ginie na limicie czasu PHP.
- Inkrementalna mapa obrazów (`_wps_image_map`) — re-sync pobiera tylko zmienione obrazy.
- Ochrona przed timeoutem: `set_time_limit(900)` + `ignore_user_abort(true)` w każdym batchu.
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

---
