<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helper\CCADB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VerifyCPCPSURLs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'verify:urls:cpcps
    {--caowner= : Run for a specific CA Owner}
    {--mode= : Standalone or Integrated}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run verification for CP, CPS and Combined CP/CPS documents for the specified CA Owner.

    Specify the --caowner option (required).
    Specify the --mode option:
    - Standalone: Run as a standalone command (default behavior), loading a new copy from CCADB upon runtime. Results and errors are only logged through the logging stack
    - Integrated (default): Run as part of a database-backed installation, reusing the most recent CCADB data currently available in the database. Results and errors are logged through the logging stack and also stored in the database for reporting and usage within the WebUI.';

    /**
     * Fields to check for URLs in CCADB records.
     */
    private const URL_FIELDS = [
        'Certificate Practice Statement (CPS) URL',
        'Policy Documentation',
        'CA Document Repository',
        'Certificate Policy (CP) URL',
        'Certificate Practice & Policy Statement',
        'MD/AsciiDoc CP/CPS URL',
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Early validation: show help if options are invalid or missing
        if ($this->showHelpIfInvalidOptions()) {
            return 0;
        }

        // Proceed with normal execution when only --caowner is provided
        $caOwner = $this->option('caowner');
        $mode = $this->option('mode') ?? 'Integrated';

        // Fetch matching CCADB certificate records for the given CA Owner
        try {
            $ccadb = new CCADB();
            $records = $ccadb->getAllCertificateRecordsByOwner($caOwner, $mode);
        } catch (\Throwable $e) {
            $this->error('Failed to retrieve CCADB records: ' . $e->getMessage());
            return 1;
        }

        // Sanitize: discard revoked/parent-revoked and expired (>=1 day past) records
        $records = $ccadb->disregardExpiredAndRevokedCertificates($records);

        // Display a brief summary and count
        $count = count($records);
        $this->info("Found {$count} certificate record(s) for owner '{$caOwner}'.");

        // Process URL fields for each record
        foreach ($records as $i => $row) {
            $this->processRecordUrlFields($row, $i + 1);
        }

        return 0;
    }

    /**
     * Inspect URL fields for a single record and validate them.
     */
    private function processRecordUrlFields(array $row, int $index): void
    {
        $subject = $row['Certificate Name'];
        $salesforceRecordID = $row['Salesforce Record ID'];

        foreach (self::URL_FIELDS as $field) {
            if (!array_key_exists($field, $row)) {
                continue; // field not present in this CSV variant
            }
            $raw = $row[$field];
            $value = is_string($raw) ? trim($raw) : trim((string) $raw);
            if ($value === '') {
                continue; // empty => ignore
            }

            // If multiple URLs are present, they must be separated by "; "
            $parts = strpos($value, '; ') !== false ? explode('; ', $value) : [$value];
            $parts = array_map('trim', $parts);

            // Ensure the field contains only URLs (no extra non-URL content)
            $reconstructed = implode('; ', $parts);
            if ($reconstructed !== $value) {
                foreach ($parts as $part) {
                    $this->logFieldError($field, 'Field contains extra non-URL content', $subject, $salesforceRecordID, $value, $part);
                }
                continue;
            }

            $ua = (string) config('app.http_user_agent', 'CCADBURLMonitor/1.0');

            foreach ($parts as $url) {
                // Validate URL structure and scheme
                if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
                    $this->logFieldError($field, 'Invalid URL format or scheme (must be http/https)', $subject, $salesforceRecordID, $value, $url);
                    continue; // move to next URL
                }

                // HEAD request with redirects allowed
                try {
                    $response = Http::withHeaders([
                        'User-Agent' => $ua,
                    ])->withOptions([
                        'allow_redirects' => [
                            'max' => 10, // allow following 30x redirects
                            'track_redirects' => true,
                        ],
                    ])->timeout(30)->head($url);
                } catch (\Throwable $e) {
                    $this->logFieldError($field, 'HEAD request failed: ' . $e->getMessage(), $subject, $salesforceRecordID, $value, $url);
                    continue;
                }

                if ($response->status() !== 200) {
                    $this->logFieldError($field, 'Non-200 HTTP status: ' . $response->status(), $subject, $salesforceRecordID, $value, $url);
                }
            }
        }
    }

    /**
     * Log an error for a specific field with context, to console and application log.
     */
    private function logFieldError(string $field, string $message, string $subject, string $ccadbRecordID, string $rawValue, ?string $url = null): void
    {
        $prefix = "[Field: {$field}] [Subject: {$subject}] [CCADB Record ID: {$ccadbRecordID}]";
        $suffix = $url ? " [URL: {$url}]" : '';
        $full = $prefix . $suffix . ' ' . $message;
        $this->error($full);
        Log::error('VerifyCPCPSURLs: ' . $message, [
            'field' => $field,
            'subject' => $subject,
            'recordId' => $ccadbRecordID,
            'raw' => $rawValue,
            'url' => $url,
        ]);
    }

    /**
     * Validate options and show help + error when invalid.
     * Returns true when help was shown (and execution should stop).
     */
    private function showHelpIfInvalidOptions(): bool
    {
        // Collect provided options (non-null/non-false)
        $options = array_filter($this->options(), static function ($value) {
            return $value !== null && $value !== false;
        });

        // Determine if --caowner is present with a non-empty value and is the only option
        $hasCaOwner = array_key_exists('caowner', $options) && $options['caowner'] !== '';

        if (!$hasCaOwner) {
            $this->error('The --caowner option is required and must be the only option provided.');
            $this->call('help', ['command_name' => $this->getName()]);
            return true;
        }

        return false;
    }
}
