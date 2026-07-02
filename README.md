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

## Soft-delete (produkty usunięte ze źródła)

Gdy produkt istnieje lokalnie, ale zniknął ze źródła:

1. Produkt jest ustawiany jako `draft`.
2. Oznaczany tagiem `Usunięte (sync)` (slug: `wps-usuniete`).
3. Zapisywany znacznik `_wps_soft_deleted_at` (timestamp).

### Limit szkiców
W ustawieniach możesz określić, ile szkiców soft-delete zachować (`soft_delete_limit`). Najstarsze produkty powyżej limitu są **trwale usuwane**. Ustaw `0`, aby nie usuwać niczego.

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

## Synchronizacja ręczna

W menu **WooCommerce → Synchronizacja produktów**:

- **Symulacja (dry run)** — pokaże co zostanie zmienione, bez zapisu do bazy. Logi w WooCommerce → Status → Logi (źródło: `wc-product-sync`).
- **Synchronizuj teraz** — uruchamia synchronizację natychmiast.

---

## Blokada współbieżności

Plugin blokuje równoczesne uruchomienia synchronizacji:

- Jeśli sync już trwa, kolejne próby zostaną przerwane z logiem ostrzegawczym.
- Blokada jest automatycznie zwalniana po zakończeniu lub upływie 15 minut (TTL transienta).
- Ręczna synchronizacja kontynuuje działanie nawet jeśli użytkownik zamknie przeglądarkę (`ignore_user_abort`).

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
- Obrazy — tylko przy tworzeniu nowego produktu (nie aktualizuje istniejących)
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

### 0.5.0 (current)
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
