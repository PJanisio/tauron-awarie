# Tauron Awarie

Lekki skrypt w PHP (0 zależności, PHP 8.5+) sprawdzający planowane i awaryjne wyłączenia prądu w wybranej lokalizacji za pomocą oficjalnego API Tauron Dystrybucja.

## Zasada działania

1. **Identyfikacja lokalizacji:** Skrypt odpytuje na żywo API Tauronu o identyfikator geograficzny (GAID) dla podanej miejscowości.
2. **Pobieranie danych:** Pobiera listę wyłączeń dla danego obszaru z nadchodzących dni.
3. **Filtrowanie i zapis:** Weryfikuje komunikaty pod kątem podanej ulicy i zapisuje przefiltrowane wyniki do pliku JSON (`outages_[miejscowość]_[timestamp].json`) w czasie lokalnym (CET/CEST). Starsze pliki są automatycznie czyszczone po 30 dniach.

### Struktura pliku JSON

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
