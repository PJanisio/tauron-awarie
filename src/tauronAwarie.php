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
    private const JSON_OUTPUT_DIR = __DIR__; // Default directory for JSON files

    private bool $silent;
    private bool $debug;

    /**
     * Initializes the cron class with output visibility settings.
     * 
     * @param bool $silent If true, disables direct output to the browser/console (except debug).
     * @param bool $debug If true, prints execution steps and error details.
     */
    public function __construct(bool $silent = true, bool $debug = false)
    {
        $this->silent = $silent;
        $this->debug = $debug;
    }

    /**
     * Outputs debug messages if debug mode is enabled.
     * 
     * @param string $message The debug information to display.
     */
    private function logDebug(string $message): void
    {
        if ($this->debug) {
            echo "[DEBUG] $message\n";
        }
    }

    /**
     * Removes outdated JSON files from the output directory based on the retention policy.
     * Keeps the directory clean from obsolete outage reports.
     */
    private function cleanupOldJsonFiles(): void
    {
        $dir = rtrim(self::JSON_OUTPUT_DIR, '/\\');
        $files = glob($dir . '/outages_*.json');
        $cutoff = time() - (self::JSON_RETENTION_DAYS * 86400);

        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    unlink($file);
                    $this->logDebug("Deleted old JSON file: " . basename($file));
                }
            }
        }
    }

    /**
     * Main entry point to check power outages for a single location.
     * Fetches city IDs, retrieves area outages, filters by street, and saves the result.
     * 
     * @param string $cityName Name of the city to check.
     * @param string $street Name of the street to filter the outages by.
     * @param string $houseNumber House number for exact location logging.
     */
    public function checkOutages(string $cityName, string $street, string $houseNumber): void
    {
        // Run cleanup of outdated JSON files before proceeding
        $this->cleanupOldJsonFiles();
        
        $this->logDebug("Starting check for: $cityName, $street $houseNumber");

        $gaids = $this->getGaidsForCity($cityName);

        if (!$gaids) {
            $this->logDebug("City not found in dynamic Tauron API: {$cityName}");
            return;
        }

        $this->logDebug("Found GAIDs: Province={$gaids['provinceGaid']}, District={$gaids['districtGaid']}, Commune={$gaids['communeGaid']}");

        $outages = $this->fetchOutagesFromApi($gaids);

        if (empty($outages)) {
            $this->logDebug("No outages returned from API for this area.");
        }

        // Pass all location details so they can be saved in the JSON output
        $this->filterAndSaveResults($outages, $cityName, $street, $houseNumber);
    }

    private function getGaidsForCity(string $targetCity): ?array
    {
        $this->logDebug("Querying WAAPI Tauron (live) for city: {$targetCity}");
        
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
        $curlError = curl_error($ch);

        if ($httpCode !== 200 || $response === false) {
            $this->logDebug("Dictionary API error. HTTP Code: {$httpCode}, cURL Error: {$curlError}");
            return null;
        }

        $cities = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logDebug("JSON decode error (Cities): " . json_last_error_msg());
            return null;
        }
        
        if (empty($cities) || !is_array($cities)) {
            $this->logDebug("Dictionary API returned an empty or invalid list.");
            return null;
        }

        $targetCityLower = mb_strtolower(trim($targetCity), 'UTF-8');
        $suggestions = [];

        foreach ($cities as $cityData) {
            // Securely fetch keys according to WAAPI JSON standard
            $name = trim($cityData['Name'] ?? '');
            $nameLower = mb_strtolower($name, 'UTF-8');

            if ($nameLower === $targetCityLower) {
                $this->logDebug("Found exact API match for: {$name}");
                return [
                    'provinceGaid' => (int)($cityData['ProvinceGAID'] ?? 0),
                    'districtGaid' => (int)($cityData['DistrictGAID'] ?? 0),
                    'communeGaid'  => (int)($cityData['OwnerGAID'] ?? 0),
                ];
            }

            if (str_contains($nameLower, $targetCityLower)) {
                $district = trim($cityData['DistrictName'] ?? 'unknown');
                $suggestions[] = "$name (district: $district)";
            }
        }

        if (!empty($suggestions)) {
            $this->logDebug("No exact match. Similar cities in API: " . implode(', ', $suggestions));
        } else {
            $this->logDebug("City '{$targetCity}' not found in dynamic API response.");
        }

        return null;
    }

    /**
     * Fetches all registered outages for a specific geographical area defined by GAIDs.
     * Uses a configured date range extending from the current UTC time.
     * 
     * @param array $gaids Area identifiers containing province, district, and commune GAIDs.
     * @return array List of outage items from the API, or an empty array on failure.
     */
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
        $curlError = curl_error($ch);

        if ($httpCode !== 200 || $response === false) {
            $this->logDebug("API request failed. HTTP Code: {$httpCode}, cURL Error: {$curlError}");
            error_log("Tauron API communication error. HTTP Code: $httpCode, cURL Error: $curlError");
            return [];
        }

        $this->logDebug("API request successful, parsing JSON response.");
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logDebug("JSON decode error (Outages): " . json_last_error_msg());
            return [];
        }

        return $data['OutageItems'] ?? [];
    }

    /**
     * Filters the raw API outage data to match the provided street name.
     * Converts timezones, constructs the final data array, and exports it to a nicely formatted JSON file.
     * 
     * @param array $outages Raw array of outages retrieved from the API.
     * @param string $cityName Original city name used for file naming and data structure.
     * @param string $street Street name used to filter outage messages.
     * @param string $houseNumber House number included in the final data output.
     */
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

        // Sanitize both city and street name to avoid file overwriting in batch mode
        $safeCityName = preg_replace('/[^\p{L}0-9_\-]/u', '_', $cityName);
        $filename = sprintf('outages_%s_%s.json', $safeCityName, $extractionTime->format('Y-m-d_His'));
        
        $dir = rtrim(self::JSON_OUTPUT_DIR, '/\\');
        $outputPath = $dir . '/' . $filename;
        
        $jsonString = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // Optimize: Check if file was successfully written
        if (file_put_contents($outputPath, $jsonString) !== false) {
            $this->logDebug("Results successfully saved to " . $outputPath);
        } else {
            $this->logDebug("Failed to save results to " . $outputPath);
            error_log("TauronOutageCron: Failed to save JSON file to $outputPath");
        }

        if (!$this->silent) {
            echo "<pre>\n" . htmlspecialchars($jsonString) . "\n</pre>\n";
        }
    }
}
