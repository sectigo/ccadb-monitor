<?php

namespace App\Helper;

use Illuminate\Support\Carbon;
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

        $ua = (string) config('app.http_user_agent', config('app.user_agent'));

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
     * Uses fgetcsv on a stream to correctly handle embedded newlines in quoted fields.
     */
    public function parseCsvToAssoc(string $csv): array
    {
        $rows = [];

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return $rows;
        }

        fwrite($stream, $csv);
        rewind($stream);

        // Read header
        $headers = fgetcsv($stream, 0, ',', '"', '\\');
        if ($headers === false || $headers === null) {
            fclose($stream);
            return $rows;
        }

        // Strip UTF-8 BOM from first header cell if present
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }

        // Read rows; fgetcsv handles embedded newlines in quoted fields
        while (($fields = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($fields === null) {
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

        fclose($stream);

        return $rows;
    }

    /**
     * Get all certificate records filtered by CA Owner or Subordinate CA Owner (case-insensitive match).
     */
    // PHP
    public function getAllCertificateRecordsByOwner(string $owner, string $mode): array
    {
        $needle = mb_strtolower(trim($owner));
        $matched = [];

        if ($mode === 'Standalone') {
            $csv = $this->getCCADBAllCertificateRecords();
            $rows = $this->parseCsvToAssoc($csv);

            foreach ($rows as $row) {
                // Build in‑memory model
                $rec = new \App\Models\CaCertificateRecord([
                    'ca_owner' => $row['CA Owner'] ?? '',
                    'certificate_name' => $row['Certificate Name'] ?? '',
                    'ccadb_record_id' => $row['Salesforce Record ID'] ?? '',
                ]);

                // Attach details (no DB writes)
                $details = [];
                foreach ($row as $field => $value) {
                    if (in_array($field, ['CA Owner', 'Certificate Name', 'Salesforce Record ID'], true)) {
                        continue;
                    }
                    $details[] = new \App\Models\CaCertificateRecordDetail([
                        'field' => $field,
                        'value' => $value,
                    ]);
                }
                // Set the relation collection in memory
                $rec->setRelation('caCertificateRecordDetails', collect($details));

                $caOwner = mb_strtolower(trim((string) $rec->ca_owner));
                // Read sub‑owner from details
                $subOwner = '';
                foreach ($rec->caCertificateRecordDetails as $d) {
                    if ($d->field === 'Subordinate CA Owner') {
                        $subOwner = mb_strtolower(trim((string) $d->value));
                        break;
                    }
                }

                if ($needle !== '' && ($caOwner === $needle || $subOwner === $needle)) {
                    // Compose CSV‑like row
                    $out = [];
                    foreach ($rec->caCertificateRecordDetails as $d) {
                        $out[$d->field] = $d->value;
                    }
                    $out['CA Owner'] = $rec->ca_owner;
                    $out['Certificate Name'] = $rec->certificate_name;
                    $out['Salesforce Record ID'] = $rec->ccadb_record_id;

                    $matched[] = $out;
                }
            }

            return $matched;

        }

        if ($mode === 'Integrated') {
            $records = \App\Models\CaCertificateRecord::query()
                ->with(['caCertificateRecordDetails' => function ($q) {
                    $q->select(['id', 'ca_certificate_record_id', 'field', 'value']);
                }])
                ->get(['id', 'ca_owner', 'certificate_name', 'ccadb_record_id']);

            foreach ($records as $rec) {
                $detailsMap = [];
                foreach ($rec->caCertificateRecordDetails as $d) {
                    $detailsMap[$d->field] = $d->value;
                }

                $caOwner = mb_strtolower(trim((string) $rec->ca_owner));
                $subOwner = mb_strtolower(trim((string) ($detailsMap['Subordinate CA Owner'] ?? '')));

                if ($needle === '' || ($caOwner !== $needle && $subOwner !== $needle)) {
                    continue;
                }

                $row = $detailsMap;
                $row['CA Owner'] = $rec->ca_owner;
                $row['Certificate Name'] = $rec->certificate_name;
                $row['Salesforce Record ID'] = $rec->ccadb_record_id;

                $matched[] = $row;
            }

            return $matched;
        }

        throw new \RuntimeException('Invalid mode specified: ' . $mode);
    }


    /**
     * Remove records where:
     * - Revocation Status is 'Revoked' or 'Parent Cert Revoked'
     * - Valid To (GMT), parsed strictly as Y.m.d (UTC end-of-day), is at least 1 day in the past (<= now(UTC) - 1 day)
     */
    public function disregardExpiredAndRevokedCertificates(array $records): array
    {
        $threshold = Carbon::now('UTC')->subDay();

        $filtered = [];
        foreach ($records as $row) {

            $status = isset($row['Revocation Status']) ? trim((string) $row['Revocation Status']) : '';
            $statusLower = mb_strtolower($status);
            if ($statusLower === 'revoked' || $statusLower === 'parent cert revoked') {
                continue; // disregard revoked
            }

            try{
                $validTo = Carbon::createFromFormat('Y.m.d', $row['Valid To (GMT)'], 'UTC');
            } catch (\Exception $e){
                throw new \RuntimeException('Invalid Date in document: ' . $statusLower);
            }
            if ($validTo !== null && $validTo->lessThanOrEqualTo($threshold)) {
                continue; // disregard expired >= 1 day ago
            }

            $filtered[] = $row;
        }

        return array_values($filtered);
    }
}
