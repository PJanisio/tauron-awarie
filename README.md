# Tauron Awarie

Lekki skrypt w PHP (0 zależności, PHP 8.5+) sprawdzający planowane i awaryjne wyłączenia prądu w wybranej lokalizacji za pomocą oficjalnego API Tauron Dystrybucja. Obsługuje sprawdzanie pojedynczych adresów oraz hurtowe weryfikowanie wielu lokalizacji.

## Zasada działania

1. **Identyfikacja lokalizacji:** Skrypt odpytuje na żywo API Tauronu o identyfikator geograficzny (GAID) dla podanej miejscowości.
2. **Pobieranie danych:** Pobiera listę wyłączeń dla danego obszaru z nadchodzących dni.
3. **Filtrowanie i zapis:** Weryfikuje komunikaty pod kątem podanej ulicy i zapisuje przefiltrowane wyniki do pliku JSON (`outages_[miejscowość]_[ulica]_[timestamp].json`) w czasie lokalnym (CET/CEST). Starsze pliki są automatycznie czyszczone po 30 dniach.

## Przykłady użycia

### Sprawdzanie wielu lokalizacji (Zalecane)

Skrypt przetworzy każdy adres z tablicy i wygeneruje osobny plik JSON dla każdej lokalizacji.

```php
require_once __DIR__ . '/tauronAwarie.php';
$cron = new TauronOutageCron(silent: true, debug: false);

$cron->checkMultipleLocations([
    ['cityName' => 'Wrocław', 'street' => 'Energetyczna', 'houseNumber' => '1'],
    ['cityName' => 'Legnickie Pole', 'street' => 'Książąt Śląskich', 'houseNumber' => '1']
]);
```

### Sprawdzanie pojedynczej lokalizacji

```php
require_once __DIR__ . '/tauronAwarie.php';
$cron = new TauronOutageCron(silent: true, debug: false);

$cron->checkOutages('Wrocław', 'Energetyczna', '1');
```

## Struktura pliku JSON

```json
{
  "extraction_date": "2026-09-09 10:22:32",
  "is_affected": true,
  "outages": [
    {
      "location": "Wrocław, ul. Energetyczna 1",
      "start_date": "2026-09-08 07:00:00",
      "end_date": "2026-09-08 17:00:00",
      "type": "Planowane",
      "details": "Wrocław: ul. Średzka, Krótka, Leśna..."
    }
  ]
}
```
