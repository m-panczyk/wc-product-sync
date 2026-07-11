# WC Product Sync (SKU)

Codzienna synchronizacja produktów ze zdalnego sklepu WooCommerce (**źródło**) do TEGO sklepu (**cel**). Dopasowanie po SKU. Obsługa: `simple`, `variable`, `grouped`. Zapisy lokalnie przez WooCommerce CRUD.

---

## Instalacja

1. Wgraj plik `wc-product-sync.php` na **sklep docelowy** (cel) do `wp-content/plugins/wc-product-sync/`.
2. Aktywuj wtyczkę w WordPress → Rozszerzenia.
3. Uzupełnij konfigurację (URL źródła, Consumer Key/Secret) w menu **WooCommerce → Synchronizacja produktów**.
4. Uruchom najpierw **Symulację (dry run)**, aby zobaczyć co zostanie zmienione bez zapisu do bazy.

---

## Klucze API — dwie metody

### Metoda 1: Formularz w adminie (domyślna)
Wypełnij pola `Consumer Key` i `Consumer Secret` bezpośrednio w ustawieniach wtyczki. Klucze są zapisywane w bazie danych (`wp_options`).

### Metoda 2: Stałe w `wp-config.php` (zalecane)
Możesz nadpisać formularz stałymi — mają pierwszeństwo i są bezpieczniejsze (nie lądują w DB ani logach):

```php
define( 'WC_PRODUCT_SYNC_SOURCE_URL', 'https://zrodlo.pl' );
define( 'WC_PRODUCT_SYNC_CK', 'ck_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
define( 'WC_PRODUCT_SYNC_CS', 'cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
```

Gdy stałe są zdefiniowane, pola w formularzu stają się nieaktywne i **nie nadpisują** istniejących wartości w bazie przy zapisie ustawień.

---

## Synchronizacja produktów

### Tryb synchronizacji (domyślnie)
Domyślnie synchronizowane są tylko produkty o statusie `publish`. Produkty ze źródła o statusach innych niż `publish` (np. draft, pending, private) są pomijane.

### Nadpisywanie filtru statusów
Możesz dodać inne statusy przez mu-plugins lub w `wp-config.php`:

```php
add_filter( 'wps_sync_statuses', function( $statuses ) {
    $statuses[] = 'draft';  // dodaj draft do synchronizacji
    $statuses[] = 'pending'; // dodaj pending do synchronizacji
    return $statuses;
} );
```

---

## Usuwanie produktów zniknętych ze źródła

Zachowanie dla produktów, które istnieją lokalnie, ale zniknęły ze źródła, ustawia opcja **`deletion_mode`**:

- **`none`** (domyślnie) — nie ruszaj lokalnych produktów.
- **`soft`** — miękkie usunięcie:
  1. Produkt ustawiany jako `draft`.
  2. Oznaczany tagiem `Usunięte (sync)` (slug: `wps-usuniete`).
  3. Zapisywany znacznik `_wps_soft_deleted_at` (timestamp).
- **`hard`** — trwałe usunięcie produktu.

### Limit szkiców
Przy trybie `soft` opcja `soft_delete_limit` (domyślnie 50) określa, ile szkiców zachować — najstarsze ponad limit są **trwale usuwane**. Ustaw `0`, aby nie usuwać niczego.

**Bezpieczeństwo:** usuwanie jest wykonywane **dopiero po** pełnym i bezbłędnym pobraniu katalogu ze źródła. Błąd pobierania REST albo przekroczenie capa kluczy źródłowych (20 000) wstrzymuje usuwanie — niepełny widok źródła nigdy nie usunie lokalnych produktów (v0.9.19).

---

## Harmonogram

### WP-Cron (domyślne)
Włącz "Uruchamiaj codziennie" w ustawieniach. Synchronizacja odpala się automatycznie przy pierwszym odwiedzinie sklebu po upływie doby.

**Uwaga:** WP-Cron wymaga ruchu na stronie. Jeśli używasz `DISABLE_WP_CRON=true`, skonfiguruj systemowy cron (patrz poniżej).

### Systemowy cron (zaawansowane)
Jeśli masz wyłączony WP-Cron, dodaj do crontaba użytkownika www:

```bash
# Uruchamiaj co godzinę (zalecany interwał — wystarczy by "daily" się aktywował)
0 * * * * curl -s https://twoj-sklep.pl/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

---

## Szybka synchronizacja (fast sync)

Oprócz codziennego pełnego synca dostępny jest **lekki, cykliczny „szybki sync"** do odświeżania
wolatylnych pól (np. cena/stan) częściej niż raz na dobę.

- **Tylko aktualizacja istniejących** — nigdy nie tworzy ani nie usuwa produktów/wariacji (to zostaje
  przy pełnym synchronizacji).
- **Tylko wybrane pola** — `fast_sync_fields` (domyślnie `price` + `stock`). Pomija obrazy, opisy,
  kategorie, atrybuty i grouped, dzięki czemu jest tani.
- **Własny harmonogram** — `fast_sync_interval_min` (domyślnie 60 min, minimum 15). Osobne zdarzenie
  cron, przeliczane przy zapisie ustawień.

Ustawienia w adminie: **`fast_sync_enabled`**, **`fast_sync_interval_min`**, **`fast_sync_fields`**.

Pełny i szybki sync wzajemnie się wykluczają (wspólna blokada / progres), więc nie nakładają się na
siebie na celu.

---

## Wydajność i wsadowanie

Synchronizacja jest **wsadowa z wznawianiem**, więc duże katalogi nie giną na limicie czasu PHP:

| Ustawienie | Domyślnie | Znaczenie |
|---|---|---|
| `per_page` | 100 | Rozmiar strony REST ze źródła (maks. 100). |
| `sync_batch_limit` | 200 | Ile produktów na batch; `0` = bez limitu (legacy). |
| `max_batch_seconds` | 20 | Budżet czasu na batch — stop+wznów przed timeoutem PHP; `0` = wyłącz. |
| `admin_auto_refresh` | **wył.** | Auto-przeładowanie strony postępu (destabilizuje UI); dostępny ręczny „Odśwież postęp". |

Obrazy są mapowane inkrementalnie (`_wps_image_map`) — przy re-syncu pobierane są **tylko** nowe/
zmienione obrazy. Szczegółowe czasy i strojenie: **[`docs/PERFORMANCE.md`](docs/PERFORMANCE.md)**.

---

## Synchronizacja ręczna

W menu **WooCommerce → Synchronizacja produktów**:

- **Symulacja (dry run)** — pokaże co zostanie zmienione, bez zapisu do bazy. Logi w WooCommerce → Status → Logi (źródło: `wc-product-sync`).
- **Synchronizuj teraz** — uruchamia synchronizację natychmiast.

---

## Blokada współbieżności

Plugin blokuje równoczesne uruchomienia synchronizacji:

- Jeśli sync już trwa, kolejne próby zostaną przerwane z logiem ostrzegawczym.
- Blokada jest automatycznie zwalniana po zakończeniu lub upływie 15 minut (TTL transienta).
- Każdy batch podnosi `set_time_limit(900)` i `ignore_user_abort(true)` — ręczna synchronizacja
  kontynuuje działanie nawet jeśli użytkownik zamknie przeglądarkę.

---

## Logi

Wszystkie operacje są logowane w WooCommerce:

1. Wejdź do **WooCommerce → Status → Logi**.
2. Wybierz źródło `wc-product-sync`.
3. Szukaj tagów: `info` (normalne), `warning` (ostrzeżenia), `error` (błędy).

Przykładowe komunikaty:
- `[DRY] CREATE simple: ...` — dry run, produkt zostanie utworzony
- `[DRY] UPDATE variable: ...` — dry run, produkt zostanie zaktualizowany
- `Soft-delete → draft: ...` — produkt zdraftowany bo brak w źródle
- `Błąd pobierania strony 5: ...` — problem z API źródła

---

## Ograniczenia

### Co jest wspierane
- Produkty: `simple`, `variable`, `grouped`
- Dopasowanie po SKU, następnie po `_wps_source_id`, potem po slug
- Atrybuty globalne i lokalne
- Kategorie (tworzy brakujące)
- Obrazy — tworzone i **aktualizowane** (inkrementalna mapa `_wps_image_map`: pobiera tylko nowe/zmienione, sprząta stare załączniki)
- Dostępności magazynowe (`manage_stock`, `stock_quantity`, `stock_status`)
- Wagi i wymiary

### Czego NIE jest wspierane
- Produkty powiązane (upsells/cross-sells)
- Meta dane custom fields poza `_wps_*`
- Tagi produktowe (poza `wps-usuniete`)
- Warianty cen w ramach wariacji — ceny są synchronizowane, ale nie wszystkie pola WC
- Zmiana typu produktu z powrotem na istniejący (może tworzyć duplikaty)

---

## Rozwiązywanie problemów

### Produkt się nie synchronizuje
1. Sprawdź logi (`wc-product-sync`) czy nie ma błędów API.
2. Upewnij się że źródło ma ustawione SKU — produkty bez SKU są pomijane.
3. Sprawdzaj statusy w adminie produktu — może być `draft` jeśli został soft-deleted.

### Duplicate products
Upewnij się że każdy produkt na źródle ma unikalne SKU. Jeśli grouped product nie ma SKU, plugin próbuje dopasować po `_wps_source_id` (meta), którą zapisuje przy pierwszym sync.

### Błąd "Synchronizacja już trwa"
Sync jest wciąż aktywny lub został przerwany i blokada nie zwolniła się (TTL 15 minut). Poczekaj lub usuń transient `wps_sync_running` z bazy danych:

```sql
DELETE FROM wp_options WHERE option_name = 'transient_wps_sync_running' OR option_name LIKE 'transient_timeout_wps_sync_running%';
```

### Klucz API nie działa
Sprawdź w WooCommerce → Ustawienia → Zaawansowane → REST API czy Consumer Key i Consumer Secret są prawidłowe oraz mają uprawnienia "Read/Write" do produktów.

---

## Zmiany (Changelog)

### 0.9.19 (current) — bezpieczeństwo danych (przegląd Codex, runda 4)
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

## Support

Wtyczka jest rozwijana wewnętrznie. W przypadku problemów zaloguj się na **sklep docelowy** i sprawdź logi WooCommerce (`wc-product-sync`). Jeśli problem dotyczy API źródła (błędy 401/403/500), sprawdzaj konfigurację REST API na stronie źródłowej.
