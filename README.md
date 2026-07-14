# WC Product Sync (SKU)

Codzienna synchronizacja produktów ze zdalnego sklepu WooCommerce (**źródło**) do TEGO sklepu
(**cel**). Dopasowanie po **SKU**, a gdy go brak — po `_wps_source_id`, następnie po nazwie. Obsługa:
`simple`, `variable`, `grouped`. Zapisy lokalnie przez WooCommerce CRUD; na źródle wtyczka **niczego
nie zmienia** (same odczyty REST).

**[Instalacja](#instalacja)** · **[Diagnostyka (wiki)](https://git.panczyk.cc/mpanczyk/wc-product-sync/wiki)** · **[Zmiany](CHANGELOG.md)** · **[Dla deweloperów](DEVELOPMENT.md)**

---

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

Repozytorium jest **publiczne** — ZIP pobierzesz bez logowania i bez tokenu. Możesz też zbudować
paczkę sam ze źródeł (patrz [DEVELOPMENT.md](DEVELOPMENT.md)):

```bash
./build.sh          # → dist/wc-product-sync-<wersja>.zip
```

**2. Zainstaluj** — panel: **Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer** → wybierz ZIP →
**Zainstaluj teraz** → **Aktywuj**.

Przez wp-cli (to samo, bez klikania):

```bash
wp plugin install ./wc-product-sync-<wersja>.zip --activate
```

Ręcznie przez SSH — rozpakuj tak, aby plik `wc-product-sync.php` wylądował
w `wp-content/plugins/wc-product-sync/`:

```bash
unzip wc-product-sync-<wersja>.zip -d wp-content/plugins/
```

**3. Skonfiguruj** — URL źródła i Consumer Key/Secret w menu **WooCommerce → Synchronizacja
produktów** (albo stałymi w `wp-config.php` — patrz niżej, metoda zalecana).

**4. Uruchom Symulację (dry run)** — pokaże co zostanie zmienione, bez zapisu do bazy. Zawsze zaczynaj
od tego.

**Aktualizacja do nowszej wersji:** nie musisz powtarzać tej procedury. Od **0.9.25** wtyczka ma
wbudowany publiczny kanał wydań, więc nowe wersje przychodzą przez **Wtyczki → Aktualizuj** jednym
kliknięciem — **bez żadnej konfiguracji**. Patrz „Aktualizacje".

---

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

---

## Synchronizacja ręczna

W menu **WooCommerce → Synchronizacja produktów**:

- **Symulacja (dry run)** — pokaże co zostanie zmienione, bez zapisu do bazy. Logi w WooCommerce → Status → Logi (źródło: `wc-product-sync`).
- **Synchronizuj teraz** — uruchamia synchronizację natychmiast.

---

---

## Blokada współbieżności

Plugin blokuje równoczesne uruchomienia synchronizacji:

- Jeśli sync już trwa, kolejne próby zostaną przerwane z logiem ostrzegawczym.
- Blokada jest automatycznie zwalniana po zakończeniu lub upływie 15 minut (TTL transienta).
- Każdy batch podnosi `set_time_limit(900)` i `ignore_user_abort(true)` — ręczna synchronizacja
  kontynuuje działanie nawet jeśli użytkownik zamknie przeglądarkę.

---

---

## Jakie endpointy wtyczka wywołuje

Wszystkie żądania idą do **źródła** i są tylko do odczytu (`GET`). Wtyczka **nic nie zapisuje** na
źródle.

| Żądanie | Po co | Wymagane uprawnienie |
|---|---|---|
| `GET /wp-json/wc/v3/products` (stronicowane) | główny katalog | `read_private_products` |
| `GET /wp-json/wc/v3/products?type=grouped` | domknięcie produktów `grouped` | `read_private_products` |
| `GET /wp-json/wc/v3/products/{id}/variations` | wariacje produktów `variable` | `read_private_products` |
| `GET <adres obrazka>` (`wp-content/uploads/…`) | pobranie obrazów — **bez autoryzacji** | — |
| `GET /wp-json/wc/v3/products/attributes` | **fallback, normalnie NIE wołany** | `manage_product_terms` |

**Wystarczy klucz z uprawnieniem „Odczyt" na koncie Administratora lub Kierownika sklepu.**

**O `/products/attributes`:** od 0.9.27 wtyczka **nie wywołuje** tego endpointu. Mapa atrybutów
globalnych jest odtwarzana z payloadów produktów i wariacji, które i tak niosą `id`, `name` i `slug`.
To ma znaczenie praktyczne: WooCommerce pilnuje tego endpointu uprawnieniem **`manage_product_terms`**,
innym niż czytanie produktów — więc klucz mógł czytać cały katalog i mimo to dostawać na nim `401`,
co wcześniej **przerywało każdy przebieg**. Endpoint został wyłącznie jako ostateczny fallback
(sięgany maks. raz na przebieg), gdyby payload nie zawierał ani nazwy, ani sluga atrybutu.

**Ruch do serwera aktualizacji** (`update.json` + ZIP) **nie idzie do źródła** — patrz „Aktualizacje".

---

---

## Ograniczenia

> Sekcja zweryfikowana empirycznie na efemerycznym rigu (2026-07-14) — nie jest to lista życzeń.
> Dwa wcześniejsze twierdzenia okazały się nieprawdziwe i zostały poprawione (dopasowanie po slug,
> atrybuty na produktach prostych).

### Co jest wspierane
- Produkty: `simple`, `variable`, `grouped`
- Dopasowanie po **SKU**, następnie po `_wps_source_id`, a na końcu po **nazwie** (`post_title`) —
  i to tylko wtedy, gdy nazwa trafia w **dokładnie jeden** lokalny produkt, nieprzypisany do innego
  źródła (`wc-product-sync.php:1984`)
- **Atrybuty — tylko na produktach `variable`** (globalne `pa_*` i lokalne). Zweryfikowane: taksonomia
  jest zakładana na celu, terminy i przypisania wariacji dojeżdżają poprawnie
- Kategorie (tworzy brakujące)
- Obrazy — tworzone i **aktualizowane**, inkrementalnie (mapa `_wps_image_map`). Zweryfikowane:
  podmiana głównego obrazu na źródle pobiera **tylko** ten jeden plik, galeria zostaje nietknięta,
  a stary załącznik jest usuwany
- Dostępności magazynowe (`manage_stock`, `stock_quantity`, `stock_status`)
- Wagi i wymiary
- Ceny zwykłe i **promocyjne** (`sale_price`), opisy (pełny i krótki)

### Czego NIE jest wspierane
- **Atrybuty na produktach `simple` i `grouped`** — `set_attributes()` jest wołane wyłącznie w gałęzi
  `WC_Product_Variable` (`wc-product-sync.php:2095`, `:2162`). Produkt prosty z atrybutem na źródle
  przyjedzie na cel **bez żadnego atrybutu**, i **nie jest to zgłaszane jako błąd**
- Produkty powiązane (upsells/cross-sells) — zweryfikowane: nie są przenoszone
- Meta dane custom fields poza `_wps_*` — zweryfikowane: nie są przenoszone
- Tagi produktowe (poza `wps-usuniete`) — zweryfikowane: nie są przenoszone
- Zmiana typu produktu na źródle (np. `simple` → `variable`) — może utworzyć duplikat

---

---

## Aktualizacje

Nowe wersje pojawiają się w **Wtyczki → Aktualizuj**, aktualizacja jednym kliknięciem — bez ponownego
wgrywania ZIP-a.

**Działa domyślnie, bez żadnej konfiguracji.** Od 0.9.25 wtyczka ma wbudowany adres publicznego kanału
wydań, a repozytorium jest publiczne — nie potrzeba ani stałej, ani tokenu.

**Instalacje ≤ 0.9.24 nie znają wbudowanego adresu**, więc same się nie zaktualizują. Trzeba **raz**
wgrać nowszą wersję ręcznie; potem updater działa sam.

```php
// własny serwer aktualizacji
define( 'WC_PRODUCT_SYNC_UPDATE_URL', 'https://twoj-serwer.pl/wc-product-sync/update.json' );

// prywatne repozytorium (Forgejo/Gitea) — token dołączany TYLKO do żądań na host z UPDATE_URL
define( 'WC_PRODUCT_SYNC_UPDATE_TOKEN', 'xxxxxxxx' );

// całkowite wyłączenie updatera — zero zapytań HTTP
define( 'WC_PRODUCT_SYNC_UPDATE_URL', '' );
```

Metadane są cache'owane **12 h** (sukces) / **2 h** (błąd serwera), więc niedostępny serwer nigdy nie
spowalnia panelu. Wymuszenie sprawdzenia: `wp transient delete wps_update_info`.

Jak działają kanały wydań i dlaczego tag `latest` jest ruchomy — patrz [DEVELOPMENT.md](DEVELOPMENT.md).

---

## Coś nie działa

Pełna diagnostyka jest w **[wiki](https://git.panczyk.cc/mpanczyk/wc-product-sync/wiki)** — z drzewem
objawów („co widzisz?" → właściwa strona), po polsku i angielsku. Nie powielamy jej tutaj, żeby obie
wersje nie rozjechały się przy pierwszej poprawce.

Skrót na start:

1. **Wersja** — `wp plugin get wc-product-sync --field=version`. Sporo problemów jest już naprawionych.
2. **Log** — WooCommerce → Status → Logi, źródło `wc-product-sync`. Szukaj linii `ERROR`.
3. **Test połączenia ze źródłem** — [Wiki: Pierwsze 5 minut](https://git.panczyk.cc/mpanczyk/wc-product-sync/wiki/PL-Pierwsze-kroki).

Najczęstsze przyczyny (opisane w [Wiki: Klucze API](https://git.panczyk.cc/mpanczyk/wc-product-sync/wiki/PL-Klucze-API)):
źródło pod `http://` (WooCommerce przyjmuje klucze API **tylko po HTTPS**), klucz przypisany do
użytkownika bez uprawnień do produktów, albo klucz z **innego** sklepu.

---

## Deinstalacja

**Usunięcie wtyczki NIE usuwa produktów.** Znikają wyłącznie ustawienia wtyczki, jej zdarzenia cron
i transienty. Produkty, obrazy, meta (`_wps_synced`, `_wps_source_id`, `_wps_image_map`) oraz tag
`wps-usuniete` **zostają** — bez nich ponowna instalacja nie dopasowałaby produktów po
`_wps_source_id` i **zduplikowała katalog**.

```bash
wp plugin uninstall wc-product-sync --deactivate
```

Pełna instrukcja (pułapka `rm -rf`, „nie udało się odinstalować", pełne wyczyszczenie śladów):
**[Wiki: Deinstalacja](https://git.panczyk.cc/mpanczyk/wc-product-sync/wiki/PL-Deinstalacja)**.

---

## Zmiany

Pełna lista zmian (0.3.0–0.9.27): **[CHANGELOG.md](CHANGELOG.md)**.

---

## Dla deweloperów

Budowanie paczki, publikacja wydań, CI/CD, efemeryczny rig e2e i testy wydajności:
**[DEVELOPMENT.md](DEVELOPMENT.md)**.

---

## Support

Wtyczka jest rozwijana wewnętrznie. W przypadku problemów zaloguj się na **sklep docelowy** i sprawdź logi WooCommerce (`wc-product-sync`). Jeśli problem dotyczy API źródła (błędy 401/403/500), sprawdzaj konfigurację REST API na stronie źródłowej.

---
