# WC Product Sync (SKU)

Codzienna synchronizacja produktów ze zdalnego sklepu WooCommerce (**źródło**) do TEGO sklepu (**cel**). Dopasowanie po SKU. Obsługa: `simple`, `variable`, `grouped`. Zapisy lokalnie przez WooCommerce CRUD.

---

## Instalacja

Wtyczka jest dystrybuowana jako **ZIP** — `wc-product-sync-<wersja>.zip`. W środku jest jeden folder
najwyższego poziomu `wc-product-sync/`, więc paczka instaluje się wprost z panelu WordPressa, bez
ręcznego rozpakowywania.

**Wymagania:** WordPress ≥ 6.0, PHP ≥ 7.4, aktywne WooCommerce. Instalujesz na **sklepie docelowym**
(cel) — ze źródła czytamy tylko przez REST, niczego tam nie instalujemy.

**1. Zdobądź ZIP-a** — pobierz z wydania:

```
https://git.panczyk.cc/mpanczyk/wc-product-sync/releases
```

Repozytorium jest **prywatne**, więc pobranie wymaga zalogowania lub tokenu. Alternatywnie zbuduj
paczkę samodzielnie ze źródeł — patrz „Budowanie paczki" niżej:

```bash
./build.sh          # → dist/wc-product-sync-<wersja>.zip
```

**2. Zainstaluj** — panel: **Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer** → wybierz ZIP →
**Zainstaluj teraz** → **Aktywuj**.

Przez wp-cli (to samo, bez klikania):

```bash
wp plugin install ./wc-product-sync-0.9.22.zip --activate
```

Ręcznie przez SSH — rozpakuj tak, aby plik `wc-product-sync.php` wylądował
w `wp-content/plugins/wc-product-sync/`:

```bash
unzip wc-product-sync-0.9.22.zip -d wp-content/plugins/
```

**3. Skonfiguruj** — URL źródła i Consumer Key/Secret w menu **WooCommerce → Synchronizacja
produktów** (albo stałymi w `wp-config.php` — patrz niżej, metoda zalecana).

**4. Uruchom Symulację (dry run)** — pokaże co zostanie zmienione, bez zapisu do bazy. Zawsze zaczynaj
od tego.

**Aktualizacja do nowszej wersji:** nie musisz powtarzać tej procedury — po jednorazowym ustawieniu
stałej `WC_PRODUCT_SYNC_UPDATE_URL` nowe wersje przychodzą przez **Wtyczki → Aktualizuj** jednym
kliknięciem. Patrz „Aktualizacje z własnego serwera".

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

### Pełna synchronizacja (`force_full_sync`)
Niezależna od `deletion_mode` opcja **„Trwale usuń lokalne produkty nieobecne w źródle"**. Po **zakończeniu całego przebiegu** trwale usuwa lokalne produkty oznaczone jako zsynchronizowane, których **nie** odświeżono w tym przebiegu (zniknęły ze źródła). Produkty utworzone/zaktualizowane w bieżącym przebiegu są **zachowane** — mechanizm porównuje znacznik `_wps_synced` z chwilą startu przebiegu, więc działa tak samo dla katalogów jedno- i wielobatchowych. Pomijana przy błędzie pobierania oraz gdy nie da się ustalić znacznika startu (bezpiecznik). **Uwaga:** produkty pominięte przez filtr statusów (np. źródłowe wersje robocze przy `sync_statuses = publish`) nie są odświeżane, więc force-full je usunie (v0.9.20).

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
2. Produkty **bez SKU** są dopasowywane po nazwie (fallback), więc brak SKU sam w sobie nie blokuje synchronizacji — ale utrudnia dopasowanie przy zmianie nazwy. Ustaw SKU, jeśli możesz.
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

## Aktualizacje z własnego serwera

Nowe wersje pojawiają się w **Wtyczki → Aktualizuj** (jak wtyczki z wordpress.org), aktualizacja
jednym kliknięciem — bez ponownego wgrywania ZIP-a.

**Działa domyślnie, bez żadnej konfiguracji.** Od 0.9.25 wtyczka ma wbudowany adres publicznego
kanału wydań (`DEFAULT_UPDATE_URL`), a repozytorium jest publiczne — więc **nie trzeba ani stałej, ani
tokenu**. Świeża instalacja po prostu dostaje aktualizacje.

**Uwaga dla instalacji ≤ 0.9.24:** starsze wersje nie znają domyślnego adresu, więc same się nie
zaktualizują. Trzeba **raz** wgrać 0.9.25 ręcznie (albo dodać stałą poniżej) — od tego momentu updater
działa sam.

**Własny serwer aktualizacji** — nadpisz stałą:

```php
define( 'WC_PRODUCT_SYNC_UPDATE_URL', 'https://twoj-serwer.pl/wc-product-sync/update.json' );
```

**Wyłączenie updatera** (żadnych zapytań HTTP) — pusta wartość:

```php
define( 'WC_PRODUCT_SYNC_UPDATE_URL', '' );
```

**Prywatne repozytorium (Forgejo/Gitea)** — jeśli `update.json` i ZIP są za autoryzacją, dodaj token dostępu (uprawnienie do odczytu repozytorium):

```php
define( 'WC_PRODUCT_SYNC_UPDATE_TOKEN', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
```

Token jest dołączany jako nagłówek `Authorization: token …` **wyłącznie** do żądań na host z `UPDATE_URL` — zarówno do pobrania `update.json`, jak i samego ZIP-a (który ściąga rdzeń WordPressa). Dzięki ograniczeniu do jednego hosta token nigdy nie trafia do innego serwera.

**Hosting** — pod `UPDATE_URL` serwujemy `update.json`, którego `download_url` wskazuje wersjonowanego ZIP-a:

```json
{
  "version": "0.9.22",
  "requires": "6.0",
  "requires_php": "7.4",
  "tested": "6.7",
  "download_url": "https://git.panczyk.cc/mpanczyk/wc-product-sync/releases/download/v0.9.22/wc-product-sync-0.9.22.zip",
  "sections": { "changelog": "…" }
}
```

### Dlaczego wydanie `latest`

`UPDATE_URL` to **stała w `wp-config.php`** — ustawiana raz i nigdy nie zmieniana. Nie może więc
wskazywać na adres przypięty do wersji: sklep skonfigurowany na
`…/releases/download/v0.9.22/update.json` **na zawsze zostałby na 0.9.22** i nigdy nie zobaczyłby
0.9.23. Metadane muszą leżeć pod adresem, który się nie zmienia, a treść pod nim ma się zmieniać.

Dlatego obok wydań wersjonowanych (`v0.9.22`, `v0.9.23`, …) utrzymujemy **jedno ruchome wydanie
z tagiem dosłownie `latest`**, do którego przy każdej publikacji podmieniamy `update.json`. Adres jest
stały, bo tag się nie zmienia:

```
https://git.panczyk.cc/mpanczyk/wc-product-sync/releases/download/latest/update.json
```

Same ZIP-y zostają **niezmienne** pod swoimi tagami wersyjnymi — `latest` niesie tylko metadane,
a `download_url` w środku pokazuje na wersjonowanego ZIP-a. Dzięki temu instalacja 0.9.22 pobiera
dokładnie tę paczkę, którą wtedy zbudowano, nawet długo po wydaniu 0.9.23.

Konfiguracja na sklepie docelowym:

```php
define( 'WC_PRODUCT_SYNC_UPDATE_URL',   'https://git.panczyk.cc/mpanczyk/wc-product-sync/releases/download/latest/update.json' );
define( 'WC_PRODUCT_SYNC_UPDATE_TOKEN', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
```

### Kanały wydań: `latest` (stable) i `latest-beta`

Kanał **wynika z wersji** — nie podaje się go ręcznie, więc beta nie trafi na produkcję przez pomyłkę:

| Wersja w nagłówku | Tag | Publikowane do |
|---|---|---|
| `0.9.24-beta1` | `v0.9.24-beta1` | **`latest-beta`** (sklepy produkcyjne tego nie widzą) |
| `0.9.24` | `v0.9.24` | **`latest` + `latest-beta`** |

Wydanie finalne trafia do **obu** kanałów celowo: kanał beta jest **nadzbiorem** stabilnego. Gdyby
finał szedł tylko na `latest`, sklep testowy zostałby na `0.9.24-beta1` na zawsze i nigdy nie dostałby
`0.9.24`, która ją zastępuje.

Działa to, bo PHP-owe `version_compare` porządkuje `0.9.24-beta1 < 0.9.24` (a `0.9.22 < 0.9.24-beta1`)
— beta nigdy nie wygląda na nowszą od finału, który po niej następuje.

**Sklep testowy** wskazujesz na kanał beta jedną stałą:

```php
define( 'WC_PRODUCT_SYNC_UPDATE_URL', 'https://git.panczyk.cc/mpanczyk/wc-product-sync/releases/download/latest-beta/update.json' );
```

Wydania beta robisz z gałęzi roboczej (np. `next`) — `release.yaml` reaguje na **tag `v*`**, niezależnie
od gałęzi:

```bash
# na gałęzi next, z Version: 0.9.24-beta1 w nagłówku wtyczki
git tag v0.9.24-beta1 && git push forgejo v0.9.24-beta1
```

---

## Budowanie paczki

`build.sh` czyta wersję z nagłówka wtyczki (**jedyne źródło prawdy**) i składa dwa artefakty w `dist/`:

| Artefakt | Zawartość |
|---|---|
| `wc-product-sync-<wersja>.zip` | `wc-product-sync.php`, `README.md`, `LICENSE`, `docs/` — pod jednym folderem `wc-product-sync/` |
| `update.json` | metadane dla updatera; `download_url` = `$WPS_UPDATE_BASE_URL/wc-product-sync-<wersja>.zip` |

Wewnętrzne artefakty (`tests/`, `metrics/`, notatki robocze, `build.sh`) **nie trafiają** do paczki.

`WPS_UPDATE_BASE_URL` musi wskazywać katalog, z którego realnie serwowany będzie ZIP — czyli ścieżkę
pobierania **wydania wersjonowanego**:

```bash
WPS_UPDATE_BASE_URL=https://git.panczyk.cc/mpanczyk/wc-product-sync/releases/download/v0.9.23 ./build.sh
```

Bez tej zmiennej `download_url` wskaże `https://EXAMPLE.invalid/…` — paczka zbuduje się, ale updater
nie będzie działał.

### Publikacja nowej wersji

Publikacja jest zautomatyzowana — **wydanie robi tag**:

1. **Podnieś `Version`** w nagłówku `wc-product-sync.php` i dopisz wpis do „Zmiany (Changelog)".
2. **Otaguj i wypchnij:**

```bash
git tag v0.9.23 && git push forgejo v0.9.23
```

Tag `v*` uruchamia workflow **Release** (`.forgejo/workflows/release.yaml`), który woła `publish.sh`:
buduje ZIP-a, tworzy wydanie `v0.9.23` z załącznikiem, a na koniec **podmienia `update.json`
w wydaniu `latest`** — to ten ostatni krok faktycznie publikuje aktualizację dla sklepów.

`publish.sh` **odmawia publikacji, gdy tag nie zgadza się z wersją w nagłówku wtyczki** (`v0.9.23` ↔
`Version: 0.9.23`) — literówka w bumpie wywala się na CI, zamiast wypchnąć metadane wskazujące na złą
paczkę.

To samo ręcznie (gdy runner nie działa):

```bash
FORGEJO_TOKEN=xxx ./publish.sh v0.9.23
```

Metadane są cache'owane po stronie sklepu **12 h** (sukces) / **2 h** (błąd serwera), więc nowa wersja
pojawi się w panelu w ciągu doby, a niedostępny serwer nigdy nie spowalnia panelu. Cache jest
czyszczony automatycznie po każdej aktualizacji wtyczki (`upgrader_process_complete`). Żeby wymusić
sprawdzenie od razu, usuń transient:

```bash
wp transient delete wps_update_info
```

---

## Deinstalacja

**Najważniejsze: usunięcie wtyczki NIE usuwa produktów.** Zsynchronizowany katalog zostaje w sklepie
— wtyczka kasuje po sobie tylko własne ustawienia i harmonogram.

### 1. Dezaktywacja (odwracalna, nic nie ginie)

**Wtyczki → Zainstalowane → Wyłącz**, albo:

```bash
wp plugin deactivate wc-product-sync
```

Zatrzymuje harmonogram (`wc_product_sync_daily_event`, `wc_product_sync_fast_event`). Ustawienia,
klucze API, produkty i wszystkie metadane **zostają**. Ponowne włączenie wraca do stanu sprzed.

### 2. Usunięcie (uninstall)

**Wtyczki → Usuń**, albo:

```bash
wp plugin uninstall wc-product-sync --deactivate
```

| Znika | Zostaje |
|---|---|
| Ustawienia (`wc_product_sync_options`) — w tym URL źródła i klucze API | **Wszystkie produkty i wariacje** |
| Wynik i raport ostatniego przebiegu (`wps_last_sync_result`, `wps_last_sync_report`) | **Obrazy produktów** (załączniki) |
| Zdarzenia cron: `wc_product_sync_daily_event`, `wps_sync_resume`, `wc_product_sync_fast_event` | Meta: `_wps_synced`, `_wps_source_id`, `_wps_image_map`, `_wps_soft_deleted_at` |
| Transienty: blokada, postęp, klucze źródła, cache updatera | Tag `Usunięte (sync)` (`wps-usuniete`) i szkice po soft-delete |

**Dlaczego metadane zostają — to jest celowe.** Gdyby uninstall je skasował, zniknęłoby wszystko, co
wiąże lokalne produkty ze źródłem. Po ponownej instalacji wtyczka nie dopasowałaby ich po
`_wps_source_id` (dotyczy zwłaszcza produktów bez SKU i grouped) i **zduplikowałaby katalog** zamiast
go zaktualizować. Usunięcie wtyczki nie może niszczyć danych, na których sklep dalej stoi.

Zachowanie jest pokryte testem (`tests/stack/uninstall.sh`): sprawdza, że wszystkie opcje, crony
i transienty **znikają**, a produkty, załączniki, meta, tag i szkice **przeżywają**.

### 3. Pełne wyczyszczenie śladów (opcjonalne, nieodwracalne)

Tylko jeśli **na pewno** nie wrócisz do tej wtyczki. Uruchom **po** uninstallu:

```bash
wp eval '
global $wpdb;
$n = $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (\"_wps_synced\",\"_wps_source_id\",\"_wps_image_map\",\"_wps_soft_deleted_at\")" );
echo "usunięto $n wierszy meta\n";
$t = term_exists( "wps-usuniete", "product_tag" );
if ( $t ) { wp_delete_term( (int) $t["term_id"], "product_tag" ); echo "usunięto tag wps-usuniete\n"; }
'
rm -f wp-content/uploads/wc-logs/wc-product-sync-*.log
```

**Czego to nie robi:** nie usuwa produktów ani obrazów — te zostają w sklepie jak każdy inny towar.
Jeśli chcesz się ich pozbyć, kasuj je normalnie w WooCommerce.

**Konsekwencja, jeśli jednak wrócisz do wtyczki:** produkty z SKU i tak zostaną dopasowane (SKU jest
pierwszym kryterium), ale produkty **bez SKU** i grouped bez SKU zduplikują się, bo przepadło
`_wps_source_id`. Do tego pierwszy sync po wyczyszczeniu **pobierze wszystkie obrazy od nowa** —
mapa `_wps_image_map` już nie istnieje.

---

## Zmiany (Changelog)

### 0.9.25 (current) — updater działa domyślnie, bez konfiguracji

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

## Support

Wtyczka jest rozwijana wewnętrznie. W przypadku problemów zaloguj się na **sklep docelowy** i sprawdź logi WooCommerce (`wc-product-sync`). Jeśli problem dotyczy API źródła (błędy 401/403/500), sprawdzaj konfigurację REST API na stronie źródłowej.

---

## Testing

### CI/CD (Forgejo Actions)

Workflows żyją w **`.forgejo/workflows/`** (jedyny katalog — Forgejo czyta właśnie ten):

| Workflow | Wyzwalacz | Co robi |
|---|---|---|
| `ci.yaml` | push do `main`, PR | `php -l`, `bash -n`, **próbne zbudowanie paczki** (`./build.sh`). |
| `e2e.yaml` | push do `main`, PR | Efemeryczne sklepy: parytet + force-full + **wydajność A/B**. |
| `release.yaml` | tag `v*` | `publish.sh` — buduje ZIP, tworzy wydanie, podmienia `update.json` w kanale. |

**CI nie dotyka rigu LAN i nie ma żadnych sekretów.** Testy stawiają własne sklepy WooCommerce
w kontenerach obok joba (ten sam demon DinD) i kasują je po sobie. Żadnego klucza SSH, żadnego
dostępu do produkcji, żadnych mutacji na cudzych danych.

Skrypty rigowe (`tests/perf-run.sh`, `tests/sync-parity-test.sh`, `tests/systemd/`) zostają jako
**narzędzia ręczne** do pomiarów na realnym sprzęcie — nie są już częścią CI.

**Wymagania runnera:** joby lecą w obrazie `node:22-bookworm` (etykieta `self-hosted`), a brakujące
narzędzia doinstalowuje krok `Toolchain`. Runner musi mieć dostęp do demona DinD — job wykrywa go
sam na swoim domyślnym gateway'u.

### Efemeryczny rig e2e (`tests/stack/`)

Dwa pełne sklepy WooCommerce (źródło + cel) w Dockerze, na jednej sieci. **Nie wymaga rigu LAN,
SSH ani sekretów** — działa tak samo na laptopie i w CI, i niczego nie mutuje poza sobą.

```bash
tests/stack/up.sh      # build ZIP-a → 2 sklepy → WP+WC → klucze REST → instalacja wtyczki z ZIP-a
tests/stack/seed.sh    # deterministyczny katalog (stały seed → powtarzalne błędy)
tests/stack/e2e.sh     # pełny sync + parytet + force-full
tests/stack/perf.sh    # wydajność: A/B względem ostatniego tagu
tests/stack/down.sh    # kasuje wszystko razem z wolumenami
```

Cel instaluje wtyczkę **z paczki zbudowanej przez `build.sh`**, więc testowany jest realny artefakt
dystrybucyjny, a nie drzewo robocze.

**`e2e.sh` wymusza wielobatchowość** (`per_page=10`, `sync_batch_limit=15`) i **traktuje pojedynczy
batch jako błąd**. To nie jest kaprys: błąd z 0.9.20 ujawniał się **wyłącznie** przy batchach
wznowienia — dla katalogu mieszczącego się w jednym batchu force-full kasował właśnie
zsynchronizowane produkty, a dla dzielonego nie uruchamiał się wcale. Test jednobatchowy nie
sprawdziłby żadnego z tych przypadków.

Faza 2 usuwa kilka produktów ze źródła, włącza `force_full_sync` i sprawdza, że z celu zniknęły
**dokładnie te** produkty — reszta katalogu przeżyła.

### Wydajność: pomiar A/B (`tests/stack/perf.sh`)

Bezwzględny czas synchronizacji zmierzony w kontenerze na współdzielonym NAS-ie (DinD, sterownik
`vfs`) jest **bezwartościowy** — ten sam kod potrafi się wahać dwukrotnie między przebiegami. Dlatego
`perf.sh` nigdy nie mówi „sync trwa N sekund". Synchronizuje **ten sam** zaseedowany katalog dwa razy,
na tym samym sprzęcie w tej samej minucie: raz wtyczką z **ostatniego tagu** (`v[0-9]*`), raz z drzewa
roboczego. Szum środowiskowy uderza w obie strony jednakowo, więc **stosunek** jest stabilny, choć
liczby bezwzględne nie są.

- **Rozgrzewka** (odrzucana) — pierwszy sync płaci za opcache, bufory MySQL i pobranie obrazków;
  bez niej strona idąca druga wygrywałaby bez powodu.
- **Przeplot i best-of-2** — przy **identycznym kodzie** po obu stronach ten stelaż potrafił pokazać
  **1.14×**; to jest podłoga szumu. Przeplot kasuje dryf, a `min` odrzuca chwilowe zacięcia (sąsiedni
  kontener, flush ZFS-a) zamiast brać je za wynik. Po tej zmianie identyczny kod daje ~0.98×.
- **Progi:** `WPS_PERF_WARN` (domyślnie 1.2×) → ostrzeżenie, `WPS_PERF_MAX` (1.5×) → błąd.

Łapie to regresje **kodu** (dodatkowy round-trip REST na produkt, obrazki pobierane mimo mapy,
rollup odpalany przy no-op update). **Nie** odpowie na pytanie „ile trwa pełny sync na realnym
QNAP-ie" — to własność sprzętu, nie kodu; od tego są ręczne skrypty rigowe.

**Ograniczenia rigu (tylko na źródle, produkcji nie dotyczą):** WooCommerce przyjmuje Basic auth
kluczem CK/CS **tylko gdy `is_ssl()`**; stack jest po czystym HTTP, więc `wp-config` ustawia
`$_SERVER['HTTPS'] = 'on'`. To z kolei sprawia, że WP zaczyna podawać adresy obrazków po `https://`,
których cel nie pobierze — dlatego mu-plugin (`tests/stack/mu/`) wymusza z powrotem `http`. Produkcja
działa po prawdziwym TLS i nie potrzebuje żadnego z tych obejść.

### Smoke test (plugin loading)

Weryfikuje, że wtyczka ładuje się bez fatalnych błędów, singleton działa, cron jest zarejestrowany i stałe klasy są dostępne.

```bash
# Na rigu (QNAP target):
WP_SMOKE_RUN=1 wp eval 'include "/share/.../wp-content/plugins/wc-product-sync/tests/smoke-test.php";'
```

Zwraca exit 0 przy sukcesie, 1 przy dowolnej awarii. Przydatny jako first-line check przed deployem nowej wersji na rig.

### Field-parity integrity test (`tests/sync-parity-test.sh`)

Testuje cykl: mutuj produkt na źródle → szybki sync na celu → weryfikacja parytetu wszystkich prostych produktów źródło↔cel.

**Tryby:**
- **`tick`** (domyślny): mutuje $K losowych prostych produktów na źródle, uruchamia fast-sync na celu, sprawdza czy pole (`price` lub `stock`) się zgadza
- **`full`**: uruchamia pełny sync, następnie weryfikuje parytet całego katalogu + rollup cen wariacji + tagi soft-delete

**Uruchomienie:**
```bash
# Cena (default), tryb tick (default):
tests/sync-parity-test.sh

# Cena, tryb full:
tests/sync-parity-test.sh full

# Stock, tryb tick:
TEST_FIELD=stock tests/sync-parity-test.sh tick

# Stock, pełny, 10 produktów do mutacji:
TEST_FIELD=stock PARITY_TEST_K=10 tests/sync-parity-test.sh full
```

**Wymagania:** `tests/perf.env` z endpointami rigu (gitignored — skopiuj z `perf.env.example`). Skrypt potrzebuje SSH do źródła i celu, wp-cli na obu, InfluxDB i Grafana (best-effort emit).

**Dodatkowe pełne sprawdzenia (tryb `full`):**
- **Variable rollup:** porównuje `min_price`/`max_price` produktów zmiennych z rzeczywistymi cenami wariacji (tolerancja ±0.01)
- **Soft-delete tagging:** próbuje 100 produktów draft, sprawdza czy te bez `_wps_synced` mają tag `wps-usuniete`

### Performance benchmark (`tests/perf-run.sh`)

Times full sync run i loguje metryki do CSV + Grafana annotation.

```bash
# Baseline (wipe+recreate):
tests/perf-run.sh baseline-v0.9.23

# Incremental (update in place):
tests/perf-run.sh incremental 0   # label=incremental, force_full=0

# Default: wipe+recreate, label="manual"
tests/perf-run.sh
```

**Auto-regression check:** porównuje duration z historycznym mediana baseline-ów — warning na stderr jeśli >1.5× median.

### Grafana dashboard push

```bash
tests/apply-dashboard.sh
```

Wypycha `tests/grafana-dashboard.json` do live Grafany (uid `wps-perf`). Automatycznie dodaje price-parity panels + wps-price annotation overlay.

### Systemd timers (automated testing)

```bash
# Zainstaluj timery:
sudo cp tests/systemd/wps-*.timer tests/systemd/wps-*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now wps-price-tick.timer   # hourly price parity @:30
sudo systemctl enable --now wps-stock-tick.timer    # hourly stock parity @:00
# Full sync parity test (daily noon):
sudo systemctl enable --now wps-price-full.timer    # daily 12:00

# Status:
systemctl list-timers | grep wps
journalctl -u wps-price-tick --since "1 hour ago" --no-pager
```

### PHPCS-WP linting (standalone)

Po zainstalowaniu composer deps (`composer install`):

```bash
# Dry-run check:
composer phpcs wc-product-sync.php

# Auto-fix (review before committing!):
composer phpcbf wc-product-sync.php

# Only warn about unprepared SQL queries:
composer phpcs --standard=WordPress.DB.PreparedSQL.NotPrepared wc-product-sync.php
```

### Testing on remote rig without scripts

Jeśli nie masz skryptów na rigu, możesz uruchomić inline PHP:

```bash
# Smoke test inline:
wp eval '
if ( class_exists( "WC_Product_Sync" ) && \WC_Product_Sync::instance() !== null ) {
    echo "OK: plugin loaded, singleton OK\n"; exit(0);
} else {
    echo "FAIL: plugin load or singleton failed\n"; exit(1);
}'
```

