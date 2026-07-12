# WC Product Sync — wydajność

Dokument opisuje charakterystykę wydajnościową wtyczki: zmierzone czasy, co je determinuje,
mechanizmy ograniczające zużycie zasobów oraz strojenie. Liczby pochodzą z rzeczywistego stanowiska
testowego (rig), nie z szacunków.

## Metodyka / stanowisko

- **Źródło (SOURCE):** WooCommerce na kontenerze Proxmox (`192.168.66.121`), katalog **492 produkty**
  (simple + variable + grouped) i **~2,1 GB** obrazów.
- **Cel (TARGET):** WooCommerce na QNAP z zainstalowaną wtyczką — mierzone tu, bo jego zużycie
  zasobów zbiera Telegraf → InfluxDB → Grafana.
- **Dopasowanie:** po SKU (fallback: nazwa / `_wps_source_id`).
- **Pomiar:** `tests/perf-run.sh` napędza pełny sync, zapisuje czas do `metrics/perf-history.csv`,
  wstawia adnotację w Grafanie i odczytuje szczytowe CPU celu z InfluxDB.

Sync jest **wsadowy z wznawianiem** (batch + resume), więc „czas" = suma wszystkich batchy jednego
przebiegu (typowo 3 batche dla 492 produktów przy `sync_batch_limit=200`).

## Zmierzone czasy (492 produkty)

| Scenariusz | Czas | Batche | Szczyt CPU celu | Uwagi |
|---|---:|---:|---:|---|
| Pełny sync, **bez obrazów** | ~127 s | 3 | ~30 % | tylko dane (pola tekstowe/cena/stan/atrybuty) |
| Pełny sync, **pierwszy z obrazami** | ~858–892 s | 3 | ~32 % | pobranie + przetworzenie ~2,1 GB obrazów (I/O-bound) |
| Re-sync inkrementalny, **bez zmian obrazów** | ~38–41 s | 3 | ~25–30 % | mapa obrazów → **0 pobrań** |
| Re-sync, **~20 % obrazów zmienionych** | ~104–113 s | 3–5 | ~29–31 % | pobierane tylko nowe/zmienione obrazy |
| **Szybki sync** (update-only, cena/stan) | ~40 s | 3 | ~27–29 % | pomija obrazy/opisy/kategorie, bez create/delete |

Wniosek kluczowy: **sideload obrazów to ~85 % czasu pierwszego pełnego synca** i jest ograniczony
przez I/O (dysk/sieć), nie CPU — stąd niskie szczytowe CPU nawet przy długich przebiegach. Gdy
obrazy się nie zmieniają, przebieg spada z ~15 min do ~40 s.

## Co determinuje czas

1. **Sideload obrazów** — dominujący koszt. Każdy nowy/zmieniony obraz to pobranie (`download_url`)
   + generowanie miniatur (Imagick/GD). Dlatego przebieg z obrazami jest o rząd wielkości dłuższy.
2. **Liczba produktów i wariacji** — każda wariacja to osobny zapis CRUD; produkty variable są
   droższe (zapis wariacji + ewentualny rollup agregatów rodzica).
3. **REST ze źródła** — paginacja (`per_page`, domyślnie 100) + pobranie wariacji per produkt
   variable. Sieć do źródła bywa wąskim gardłem przy wolnym łączu.
4. **Zapisy przez WooCommerce CRUD** — indeksy (`wc_product_meta_lookup`), term cache itd.

## Mechanizmy ograniczające zużycie zasobów

- **Batching + wznawianie:** `sync_batch_limit` (domyślnie 200 produktów) i budżet czasu na batch
  `max_batch_seconds` (domyślnie 20 s). Przebieg zatrzymuje się na granicy **pozycji** (nie strony)
  i wznawia przez WP-Cron — żaden batch nie ginie na limicie czasu PHP. Wznawianie zapisuje offset
  wewnątrz strony (`page_offset`), więc nic nie jest przetwarzane dwukrotnie ani gubione.
- **Ochrona przed timeoutem:** każdy batch podnosi `set_time_limit(900)` i `ignore_user_abort(true)`
  (błąd `set_time_limit` jest logowany, nie połykany). Web SAPI QNAP ma `max_execution_time=30` —
  budżet 20 s + wznawianie utrzymują przebieg poniżej tego limitu.
- **Inkrementalna mapa obrazów (`_wps_image_map`):** klucz źródłowy obrazu → lokalny attachment.
  Pobierane są **tylko** nowe/zmienione obrazy; stare załączniki są sprzątane. To główny powód
  spadku re-synców do ~40 s.
- **Szybki sync (v0.9.16):** cykliczny (np. co godzinę), **tylko aktualizacja istniejących**
  (bez create/delete), tylko wybrane pola (`fast_sync_fields`, np. cena/stan). Pomija obrazy,
  opisy, kategorie, atrybuty, grouped oraz `force_full` — dzięki temu odświeżenie cen/stanów jest
  tanie między codziennymi pełnymi synchronizacjami.
- **Rollup wariacji na żądanie (v0.9.13–v0.9.15):** `WC_Product_Variable::sync()` (przebudowa
  agregatów min/max ceny + statusu stanu rodzica) wołane **tylko** gdy dodano/usunięto wariację lub
  zmieniła się jej cena/stan/status — no-op update nie płaci za rollup.
- **Cap kluczy źródłowych (20 000):** akumulacja kluczy do soft-delete jest ograniczona, by nie
  wysadzić pamięci/transienta na dużych katalogach (patrz „Ograniczenia").
- **Ponawianie REST z backoffem:** 429/5xx ponawiane wykładniczo (do 5 prób); błąd pobrania
  zachowuje postęp i wstrzymuje soft-delete (niepełny widok źródła nie może usuwać produktów).

## Strojenie (ustawienia wtyczki)

| Ustawienie | Domyślnie | Kiedy zmienić |
|---|---|---|
| `per_page` | 100 | Zmniejsz przy wolnym/niestabilnym źródle; 100 to maks. WooCommerce. |
| `sync_batch_limit` | 200 | Mniej = częstsze checkpointy (bezpieczniej na słabym hoście); 0 = bez limitu (legacy). |
| `max_batch_seconds` | 20 | Dopasuj do `max_execution_time` web SAPI; 0 = wyłącz budżet czasu. |
| Pole „Obrazy" | wł. | **Wyłącz, jeśli obrazy są zarządzane lokalnie** — usuwa dominujący koszt. |
| Szybki sync | wył. | Włącz dla częstego odświeżania cen/stanów (interwał w minutach, min. 15). |
| Auto-odświeżanie postępu | **wył.** | Pełne przeładowanie strony admina destabilizuje UI; jest ręczny „Odśwież postęp". |

Praktyczne wnioski:
- **Pierwszy import:** zaplanuj na okno o niskim ruchu — pełny sync z obrazami to ~15 min/500 prod.
- **Codzienny sync:** jeśli obrazy rzadko się zmieniają, przebieg jest zdominowany przez ~40 s
  narzutu danych.
- **Wolatylne pola (cena/stan):** użyj szybkiego synca (np. co godzinę) zamiast częstych pełnych.

## Ograniczenia / skalowanie

- **Sideload I/O:** pierwszy import skaluje się liniowo z liczbą i rozmiarem obrazów; to twardy
  koszt sieci/dysku, nie CPU.
- **Cap 20k kluczy a soft-delete:** na katalogach >20 000 produktów z włączonym soft-delete,
  produkty poza capem mogą wyglądać jak „nieobecne w źródle". Per-run limit usunięć ogranicza zasięg;
  przy realnie dużych katalogach podnieś cap lub wyłącz soft-delete (patrz `TODO.md`).
- **Brak wp-cron = ręczne napędzanie:** jeśli host celu nie odpala WP-Cron (jak QNAP na rigu),
  batche/wznawianie oraz zaplanowane joby trzeba napędzać zewnętrznie (np. systemd timery —
  `tests/systemd/`).

## Ciągła weryfikacja parytetu

`tests/sync-parity-test.sh` (harmonogram w `tests/systemd/`) co godzinę zmienia losowe ceny (`:30`)
i stany (`:00`) na źródle, napędza szybki sync i weryfikuje, że **każdy** produkt simple ma zgodne
`regular_price`/`stock_quantity` źródło↔cel. Wyniki trafiają do InfluxDB/Grafany (metryka
`wps_price_check`, panele + alert e-mail na rozjazd lub brak przebiegu). To pilnuje, że optymalizacje
wydajności nie psują poprawności.

---
*Dane bazowe: `metrics/perf-history.csv`. Powtórzenie pomiaru: `tests/perf-run.sh <label> [force_full]`.*
