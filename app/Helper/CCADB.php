<?php

namespace App\Helper;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CCADB
{
    /**
     * Download the CCADB All Certificate Records CSV from the configured URL.
     *
     * Returns the raw CSV content as a string.
     * Throws \RuntimeException on failures.
     */
    public function getCCADBAllCertificateRecords(): string
    {
        $url = (string) config('app.ccadb_all_certificate_records_url', env('CCADB_ALL_CERTIFICATE_RECORDS_URL'));

        if (empty($url)) {
            throw new \RuntimeException('CCADB_ALL_CERTIFICATE_RECORDS_URL is not configured.');
        }

        $ua = (string) config('app.http_user_agent', 'CCADBURLMonitor/1.0');

        try {
            $response = Http::withHeaders([
                'User-Agent' => $ua,
            ])->timeout(60)->accept('text/csv')->get($url);
        } catch (\Throwable $e) {
            Log::error('Failed to request CCADB CSV', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Failed to download CCADB CSV: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->successful()) {
            $status = $response->status();
            Log::error('CCADB CSV request unsuccessful', ['status' => $status]);
            throw new \RuntimeException('Failed to download CCADB CSV: HTTP ' . $status);
        }

        $body = $response->body();
        if ($body === '' || $body === null) {
            throw new \RuntimeException('Downloaded CCADB CSV is empty.');
        }

        return $body;
    }

    /**
     * Parse a CSV string into an array of associative arrays.
     * The first row is treated as header. Empty lines are skipped.
     */
    public function parseCsvToAssoc(string $csv): array
    {
        $rows = [];
        $lines = preg_split('/\r\n|\n|\r/', $csv);
        if (!$lines) {
            return $rows;
        }

        $headers = null;
        foreach ($lines as $line) {
            if ($line === '' || $line === false) {
                continue;
            }
            $fields = str_getcsv($line);
            if ($fields === null) {
                continue;
            }
            if ($headers === null) {
                $headers = $fields;
                continue;
            }
            // Normalize number of fields to headers length
            if (count($fields) < count($headers)) {
                $fields = array_pad($fields, count($headers), null);
            } elseif (count($fields) > count($headers)) {
                $fields = array_slice($fields, 0, count($headers));
            }
            $rows[] = array_combine($headers, $fields);
        }

        return $rows;
    }

    /**
     * Get all certificate records filtered by CA Owner or Subordinate CA Owner (case-insensitive match).
     * Returns the matched rows as associative arrays keyed by CSV headers.
     */
    public function getAllCertificateRecordsByOwner(string $owner): array
    {
        $csv = $this->getCCADBAllCertificateRecords();
        $rows = $this->parseCsvToAssoc($csv);

        $needle = mb_strtolower(trim($owner));
        $matched = [];
        foreach ($rows as $row) {
            $caOwner = isset($row['CA Owner']) ? mb_strtolower(trim((string) $row['CA Owner'])) : '';
            $subOwner = isset($row['Subordinate CA Owner']) ? mb_strtolower(trim((string) $row['Subordinate CA Owner'])) : '';
            if ($needle !== '' && ($caOwner === $needle || $subOwner === $needle)) {
                $matched[] = $row;
            }
        }

        return $matched;
    }
}
