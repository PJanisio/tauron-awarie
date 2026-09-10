<?php

/*
tauronAwarie.php - Cron script to check Tauron outages for a specific city, street, and house number.
Author: Pawel 'Pavlus' Janisio
License: MIT
github: https://github.com/PJanisio/tauron-awarie
*/

class TauronOutageCron
{

    private const API_URL = 'https://www.tauron-dystrybucja.pl/waapi/outages/area';
    private const FETCH_RANGE_DAYS = 7;
    private const JSON_RETENTION_DAYS = 30; // Keep generated JSONs for 30 days

    private bool $silent;
    private bool $debug;

    public function __construct(bool $silent = true, bool $debug = false)
    {
        $this->silent = $silent;
        $this->debug = $debug;
    }

    private function logDebug(string $message): void
    {
        if ($this->debug) {
            echo "[DEBUG] $message\n";
        }
    }

    private function cleanupOldJsonFiles(): void
    {
        $files = glob(__DIR__ . '/outages_*.json');
        $cutoff = time() - (self::JSON_RETENTION_DAYS * 86400);

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                unlink($file);
                $this->logDebug("Deleted old JSON file: " . basename($file));
            }
        }
    }

    public function checkOutages(string $cityName, string $street, string $houseNumber): void
    {
        // Run cleanup of outdated JSON files before proceeding
        $this->cleanupOldJsonFiles();
        $this->logDebug("Starting check for: $cityName, $street $houseNumber");
        $gaids = $this->getGaidsForCity($cityName);
        
        if (!$gaids) {
            $this->logDebug("Nie znaleziono miejscowości w dynamicznym API Tauron: {$cityName}");
            return;
        }

        $this->logDebug("Found GAIDs: Province={$gaids['provinceGaid']}, District={$gaids['districtGaid']}, Commune={$gaids['communeGaid']}");

        $outages = $this->fetchOutagesFromApi($gaids);

        if (empty($outages)) {
            $this->logDebug("No outages returned from API for this area.");
        }

        // Pass cityName as well so it can be saved in the JSON output
        $this->filterAndSaveResults($outages, $cityName, $street, $houseNumber);
    }

   private function getGaidsForCity(string $targetCity): ?array
    {
        $this->logDebug("Odpytywanie WAAPI Tauron (na żywo) o miejscowość: {$targetCity}");
        
        // Generate timestamp in milliseconds (cache-buster similar to jQuery)
        $timestamp = (int)(microtime(true) * 1000);
        
        // Correct endpoint from network debug
        $url = 'https://www.tauron-dystrybucja.pl/waapi/enum/geo/cities?partName=' . urlencode($targetCity) . '&_=' . $timestamp;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/javascript, */*; q=0.01',
                'X-Requested-With: XMLHttpRequest', // Simulate AJAX request
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer: https://www.tauron-dystrybucja.pl/wylaczenia'
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || $response === false) {
            $this->logDebug("Błąd pobierania słownika. HTTP Code: {$httpCode}");
            return null;
        }

        $cities = json_decode($response, true);
        
        if (empty($cities) || !is_array($cities)) {
            $this->logDebug("API słownikowe zwróciło pustą listę.");
            return null;
        }

        $targetCityLower = mb_strtolower(trim($targetCity), 'UTF-8');
        $suggestions = [];

        foreach ($cities as $cityData) {
            // Securely fetch keys according to WAAPI JSON standard
            $name = trim($cityData['Name'] ?? '');
            $nameLower = mb_strtolower($name, 'UTF-8');
            
            if ($nameLower === $targetCityLower) {
                $this->logDebug("Znaleziono idealne dopasowanie API dla: {$name}");
                return [
                    'provinceGaid' => (int)($cityData['ProvinceGAID'] ?? 0),
                    'districtGaid' => (int)($cityData['DistrictGAID'] ?? 0),
                    'communeGaid'  => (int)($cityData['OwnerGAID'] ?? 0), 
                ];
            }
            
            if (str_contains($nameLower, $targetCityLower)) {
                $district = trim($cityData['DistrictName'] ?? 'unknown');
                $suggestions[] = "$name (pow. $district)";
            }
        }

        if (!empty($suggestions)) {
            $this->logDebug("Brak dokładnego dopasowania. Podobne miejscowości w API: " . implode(', ', $suggestions));
        } else {
            $this->logDebug("Miejscowość '{$targetCity}' nie została znaleziona w dynamicznej odpowiedzi API.");
        }
        
        return null;
    }

    private function fetchOutagesFromApi(array $gaids): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $end = $now->modify('+' . self::FETCH_RANGE_DAYS . ' days');

        $queryParams = [
            'provinceGAID' => $gaids['provinceGaid'],
            'districtGAID' => $gaids['districtGaid'],
            'communeGAID'  => $gaids['communeGaid'],
            'fromDate'     => $now->format('Y-m-d\TH:i:s.000\Z'),
            'toDate'       => $end->format('Y-m-d\TH:i:s.000\Z'),
        ];

        $url = self::API_URL . '?' . http_build_query($queryParams);
        $this->logDebug("Fetching outages from URL: {$url}");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: PHP Cron script'
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || $response === false) {
            $this->logDebug("API request failed with HTTP code: {$httpCode}");
            error_log("Błąd komunikacji z API Tauron. HTTP Code: $httpCode");
            return [];
        }

        $this->logDebug("API request successful, parsing JSON response.");
        $data = json_decode($response, true);
        return $data['OutageItems'] ?? [];
    }

    private function filterAndSaveResults(array $outages, string $cityName, string $street, string $houseNumber): void
    {
        $affectedOutages = [];
        $streetLower = mb_strtolower($street, 'UTF-8');
        $targetTimeZone = new DateTimeZone('Europe/Warsaw');

        $this->logDebug("Filtering " . count($outages) . " outages for street: $street");

        foreach ($outages as $outage) {
            $message = $outage['Message'] ?? '';
            $messageLower = mb_strtolower($message, 'UTF-8');
            
            if (str_contains($messageLower, $streetLower)) {
                $startDate = (new DateTimeImmutable($outage['StartDate']))->setTimezone($targetTimeZone);
                $endDate   = (new DateTimeImmutable($outage['EndDate']))->setTimezone($targetTimeZone);

                $affectedOutages[] = [
                    'location'   => sprintf('%s, ul. %s %s', $cityName, $street, $houseNumber),
                    'start_date' => $startDate->format('Y-m-d H:i:s'),
                    'end_date'   => $endDate->format('Y-m-d H:i:s'),
                    'type'       => ($outage['TypeId'] === 1) ? 'Planowane' : 'Awaryjne',
                    'details'    => $message
                ];
            }
        }

        $extractionTime = new DateTimeImmutable('now', $targetTimeZone);

        $output = [
            'extraction_date' => $extractionTime->format('Y-m-d H:i:s'),
            'is_affected'     => !empty($affectedOutages),
            'outages'         => $affectedOutages
        ];
        
        $safeCityName = preg_replace('/[^\p{L}0-9_\-]/u', '_', $cityName);
        $filename = sprintf('outages_%s_%s.json', $safeCityName, $extractionTime->format('Y-m-d_His'));
        $outputPath = __DIR__ . '/' . $filename;
        $jsonString = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        file_put_contents($outputPath, $jsonString);
        $this->logDebug("Results successfully saved to " . $outputPath);

        if (!$this->silent) {
            echo "<pre>\n" . htmlspecialchars($jsonString) . "\n</pre>\n";
        }
    }
    
}
