# DEVELOPMENT

Jak budować, wydawać i testować wtyczkę. **To nie jest dokumentacja dla użytkownika** —
instalacja i konfiguracja są w [README](README.md), a diagnostyka problemów w
[wiki](https://git.panczyk.cc/mpanczyk/wc-product-sync/wiki).

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

Tag `v*` uruchamia workflow **Release** (`.forgejo/workflows/release.yaml`) — potok z bramkami,
w którym **każdy job blokuje następny**:

1. **`guard`** — waliduje tag, zanim cokolwiek się zbuduje:
   - **format** — `vX.Y.Z` (stabilna) albo `vX.Y.Z-rcN` (kandydat). Nic innego; tag bez `v`
     (np. `0.9.27-rc7`) jest odrzucany.
   - **zgodność z nagłówkiem** — `v0.9.23` ↔ `Version: 0.9.23`.
   - **brak duplikatu** — jeśli wersja ma już wydanie z ZIP-em, potok pada („podbij numer").
   - **kolejność** — nowy tag musi być **ściśle większy** od każdego istniejącego (PHP
     `version_compare`: `0.9.27 > 0.9.27-rc9`, `rc10 > rc2`). Blokuje cofnięcie wersji.
2. **`lint` + `e2e`** — te same testy co na PR, ale na **otagowanym drzewie**. Wydanie fizycznie
   nie ruszy bez zielonych testów.
3. **`release`** — `publish.sh`: buduje ZIP, tworzy wydanie `v0.9.23` z załącznikiem, a na koniec
   **podmienia `update.json`** w kanale (`latest` dla stabilnej, `latest-beta` zawsze) — to ten
   ostatni krok faktycznie publikuje aktualizację dla sklepów.

`publish.sh` dodatkowo **odmawia publikacji, gdy tag nie zgadza się z wersją w nagłówku** — literówka
w bumpie wywala się, zamiast wypchnąć metadane wskazujące na złą paczkę.

**Twarda blokada u źródła (opcjonalna, zalecana):** te same reguły formatu/kolejności/duplikatu są
w `scripts/pre-receive-tag-guard.sh` — zainstalowany jako **pre-receive hook** w Forgejo odrzuca zły
tag już **przy `git push`** (guard w CI blokuje dopiero *wydanie*, a zły tag i tak ląduje w repo).
Wymaga `[security] DISABLE_GIT_HOOKS = false` w `app.ini` Forgejo + restartu, potem: repo → Settings →
Git Hooks → `pre-receive` → wklej skrypt.

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

---

## Kanały wydań

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

---

## Testing

### CI/CD (Forgejo Actions)

Workflows żyją w **`.forgejo/workflows/`** (jedyny katalog — Forgejo czyta właśnie ten):

| Workflow | Wyzwalacz | Co robi |
|---|---|---|
| `ci.yaml` | push do `main`, PR | `php -l`, `bash -n`, **próbne zbudowanie paczki** (`./build.sh`). |
| `e2e.yaml` | push do `main`, PR | Efemeryczne sklepy: parytet + force-full + **wydajność A/B**. |
| `release.yaml` | tag `v*` | Bramkowany potok: **guard** (format/nagłówek/duplikat/kolejność) → **lint + e2e** na otagowanym drzewie → **release** (`publish.sh`). Wydanie tylko po zielonych testach. |

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

