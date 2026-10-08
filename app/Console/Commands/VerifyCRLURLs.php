<?php

namespace App\Console\Commands;

use App\Models\Issue;
use App\Models\Setting;
use Illuminate\Console\Command;
use App\Helper\CCADB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Monolog\Level;
use Paulgsepulveda\MonologTeamsWorkflow\TeamsWorkflowLogHandler;

class VerifyCRLURLs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'verify:urls:crl
    {--caowner= : Run for a specific CA Owner}
    {--mode= : Standalone or Integrated}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run verification for CRLs for the specified CA Owner.

    Specify the --caowner option (required).
    Specify the --mode option:
    - Standalone: Run as a standalone command (default behavior), loading a new copy from CCADB upon runtime. Results and errors are only logged through the logging stack
    - Integrated (default): Run as part of a database-backed installation, reusing the most recent CCADB data currently available in the database. Results and errors are logged through the logging stack and also stored in the database for reporting and usage within the WebUI.';

    /**
     * Fields to check for URLs in CCADB records.
     */
    private const URL_FIELDS = [
        'Full CRL Issued By This CA',
        'JSON Array of Partitioned CRLs',
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

        if ($mode == "Integrated") {
            //If available, discover the last CRL URL check time
            $lastRun = Setting::where("key", "=", "last_crl_url_check")->first()->value ?? 'N/A';
        }

        // Process URL fields for each record
        foreach ($records as $i => $row) {
            $this->processRecordUrlFields($row, $i + 1, $mode);
        }

        if ($mode == "Integrated") {
            $setting = new Setting();
            $setting->setLastCRLURLCheckNow();

            //Set any CRL URL checks as resolved if they were last detected before the last run
            foreach (Issue::where("issue_type", "LIKE", "CRL: %")->where("is_resolved", "=", false)->get() as $issue) {
                $lastDetected = Carbon::parse($issue->last_detected_at);
                if ($lastRun !== 'N/A') {
                    $lastRunCarbon = Carbon::parse($lastRun);
                    if ($lastDetected->lessThan($lastRunCarbon)) {
                        $issue->is_resolved = true;
                        $issue->save();
                    }
                }
            }
        }
        return 0;
    }

    /**
     * Inspect URL fields for a single record and validate them.
     */
    private function processRecordUrlFields(array $row, int $index, string $mode): void
    {
        $issue = new Issue();
        $id = $row['id'] ?? 0;
        $subject = $row['Certificate Name'];
        $salesforceRecordID = $row['Salesforce Record ID'];

        foreach (self::URL_FIELDS as $field) {
            if (!array_key_exists($field, $row)) {
                $this->error("Field '{$field}' is relied upon but not present in the CSV.");
            }
            $raw = $row[$field];
            $value = is_string($raw) ? trim($raw) : trim((string)$raw);
            if ($value == '') {
                continue; // empty => ignore
            }

            if ($field === 'Full CRL Issued By This CA') {
                // Single URL expected
                $parts = [$value];
            } else {
                // JSON decode
                $parts = json_decode($value, true);
            }

            // Ensure the field contains only URLs (no extra non-URL content)
            $ua = (string)config('app.http_user_agent', 'CCADBURLMonitor/1.0');

            foreach ($parts as $url) {
                if ($field === 'JSON Array of Partitioned CRLs' && $value == '[""]') {
                    //Continue if the JSON array field is empty, as that is allowed (it means no CRL have yet been disclosed)
                    continue;
                }
                // Validate URL structure and scheme
                if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^http:\/\//i', $url)) {

                    $this->logFieldError($field, 'Invalid URL format or scheme (must be http)', $subject, $salesforceRecordID, $value, $url);

                    if ($mode === 'Integrated') {
                        $issue->createOrUpdateError($id, $url, 'Invalid URL format or scheme (must be http): ' . $url, 'CRL: Invalid URL format or scheme (must be http)', false);
                    }

                    continue; // move to next URL
                }

                // GET request with redirects allowed (to retrieve full CRL for signature date verification)
                try {
                    $response = Http::withHeaders([
                        'User-Agent' => $ua,
                    ])->withOptions([
                        'allow_redirects' => [
                            'max' => 10, // allow following 30x redirects
                            'track_redirects' => true,
                        ],
                    ])->timeout(30)->get($url);
                } catch (\Throwable $e) {
                    $this->logFieldError($field, 'GET request failed: ' . $e->getMessage(), $subject, $salesforceRecordID, $value, $url);
                    if ($mode === 'Integrated') {
                        $issue->createOrUpdateError($id, $url, 'GET request failed: ' . $e->getMessage(), 'CRL: GET request failed', false);
                    }
                    continue;
                }

                if ($response->status() !== 200) {
                    $this->logFieldError($field, 'Non-200 HTTP status: ' . $response->status(), $subject, $salesforceRecordID, $value, $url);
                    if ($mode === 'Integrated') {
                        $issue->createOrUpdateError($id, $url, 'Non-200 HTTP status: ' . $response->status(), 'CRL: Non-200 HTTP status', false);
                    }
                    continue;
                }

                // Verify CRL signature date
                try {
                    $crlContent = $response->body();
                    $thisUpdateDate = $this->extractCRLThisUpdateDate($crlContent);

                    if ($thisUpdateDate !== null) {
                        if (!$this->isCRLWithinAcceptableAge($thisUpdateDate)) {
                            $maxDays = (int)config('app.crl_max_signature_age_days', 30);
                            $message = "CRL signature exceeds maximum age of {$maxDays} days (signed: " . $thisUpdateDate->format('Y-m-d H:i:s') . ')';
                            $this->logFieldError($field, $message, $subject, $salesforceRecordID, $value, $url);
                            if ($mode === 'Integrated') {
                                $issue->createOrUpdateError($id, $url, $message, 'CRL: CRL too old', false);
                            }
                            continue;
                        } else {
                            // CRL is valid and within acceptable age, resolve any previous issues
                            if ($mode === 'Integrated') {
                                $issue->createOrUpdateError($id, $url, '', 'CRL: CRL too old', true);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logFieldError($field, 'CRL signature date verification failed: ' . $e->getMessage(), $subject, $salesforceRecordID, $value, $url);
                    if ($mode === 'Integrated') {
                        $issue->createOrUpdateError($id, $url, 'CRL signature date verification failed: ' . $e->getMessage(), 'CRL: Signature verification error', false);
                    }
                    continue;
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
        // Note: Avoid including raw/potentially malformed URLs in log context to prevent cURL errors in webhook handlers
        Log::error('VerifyCRLURLs: ' . $message, [
            'field' => $field,
            'subject' => $subject,
            'recordId' => $ccadbRecordID,
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

    /**
     * Extract the thisUpdate date from a CRL (Certificate Revocation List).
     * Returns a Carbon instance or null if unable to parse.
     */
    private function extractCRLThisUpdateDate(string $crlContent): ?Carbon
    {
        try {
            // Try to parse as DER-encoded CRL (binary format)
            // CRL structure: TBSCertList ::= SEQUENCE {
            //   version INTEGER OPTIONAL,
            //   signature AlgorithmIdentifier,
            //   issuer Name,
            //   thisUpdate Time,
            //   nextUpdate Time OPTIONAL,
            //   ...
            // }

            // Attempt to get CRL info using OpenSSL if available
            if (extension_loaded('openssl')) {
                return $this->parseCRLWithOpenSSL($crlContent);
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('Failed to extract CRL thisUpdate date: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse CRL using OpenSSL extension to extract thisUpdate date.
     */
    private function parseCRLWithOpenSSL(string $crlContent): ?Carbon
    {
        // First, try to determine if it's DER or PEM encoded
        $isPem = strpos($crlContent, '-----BEGIN') !== false;

        if (!$isPem) {
            // Try to convert DER to PEM
            $pemCrl = "-----BEGIN X509 CRL-----\n"
                . chunk_split(base64_encode($crlContent), 64, "\n")
                . "-----END X509 CRL-----\n";
        } else {
            $pemCrl = $crlContent;
        }

        // Use openssl_crl_get_public_key and binary parsing
        // Parse the PEM to extract thisUpdate using ASN.1 parsing
        return $this->parseASN1CRLDate($crlContent);
    }

    /**
     * Parse ASN.1 encoded CRL to extract thisUpdate timestamp.
     * This handles DER-encoded CRL data.
     */
    private function parseASN1CRLDate(string $crlData): ?Carbon
    {
        try {
            // DER format starts with 0x30 (SEQUENCE tag)
            if (strlen($crlData) < 4 || ord($crlData[0]) !== 0x30) {
                // Not a valid DER sequence, possibly base64 or PEM
                // Try base64 decode
                $decoded = base64_decode($crlData, true);
                if ($decoded && ord($decoded[0]) === 0x30) {
                    $crlData = $decoded;
                } else {
                    return null;
                }
            }

            // Find thisUpdate field (typically after issuer field)
            // ASN.1 structure: after issuer Name, we have thisUpdate
            // thisUpdate is a Time, which is either UTCTime or GeneralizedTime

            // Use a simplified approach: search for date patterns in the CRL
            // UTCTime: 0x17 tag (13 bytes)
            // GeneralizedTime: 0x18 tag (15 bytes)

            $utcTimeTag = "\x17"; // UTCTime
            $generalizedTimeTag = "\x18"; // GeneralizedTime

            // Find the first occurrence of time tags after position 30 (after header)
            for ($i = 30; $i < strlen($crlData) - 15; $i++) {
                if ($crlData[$i] === $utcTimeTag && ord($crlData[$i + 1]) === 13) {
                    // Found UTCTime (13 bytes)
                    $dateStr = substr($crlData, $i + 2, 13);
                    return $this->parseUTCTime($dateStr);
                } elseif ($crlData[$i] === $generalizedTimeTag && ord($crlData[$i + 1]) === 15) {
                    // Found GeneralizedTime (15 bytes)
                    $dateStr = substr($crlData, $i + 2, 15);
                    return $this->parseGeneralizedTime($dateStr);
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('Error parsing CRL ASN.1 date: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse UTCTime format (YYMMDDhhmmssZ)
     */
    private function parseUTCTime(string $timeStr): ?Carbon
    {
        try {
            // UTCTime format: YYMMDDhhmmssZ (13 chars)
            if (strlen($timeStr) < 12) {
                return null;
            }

            $yy = intval(substr($timeStr, 0, 2));
            $mm = intval(substr($timeStr, 2, 2));
            $dd = intval(substr($timeStr, 4, 2));
            $hh = intval(substr($timeStr, 6, 2));
            $mi = intval(substr($timeStr, 8, 2));
            $ss = intval(substr($timeStr, 10, 2));

            // Handle 2-digit year: 00-49 -> 2000-2049, 50-99 -> 1950-1999
            $year = $yy < 50 ? 2000 + $yy : 1900 + $yy;

            return Carbon::createFromDate($year, $mm, $dd)
                ->setTime($hh, $mi, $ss);
        } catch (\Throwable $e) {
            Log::warning('Error parsing UTCTime: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse GeneralizedTime format (YYYYMMDDhhmmssZ)
     */
    private function parseGeneralizedTime(string $timeStr): ?Carbon
    {
        try {
            // GeneralizedTime format: YYYYMMDDhhmmssZ (15 chars)
            if (strlen($timeStr) < 14) {
                return null;
            }

            $yyyy = intval(substr($timeStr, 0, 4));
            $mm = intval(substr($timeStr, 4, 2));
            $dd = intval(substr($timeStr, 6, 2));
            $hh = intval(substr($timeStr, 8, 2));
            $mi = intval(substr($timeStr, 10, 2));
            $ss = intval(substr($timeStr, 12, 2));

            return Carbon::createFromDate($yyyy, $mm, $dd)
                ->setTime($hh, $mi, $ss);
        } catch (\Throwable $e) {
            Log::warning('Error parsing GeneralizedTime: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if the CRL signature date is within the acceptable age threshold.
     */
    private function isCRLWithinAcceptableAge(Carbon $thisUpdateDate): bool
    {
        $maxHours = (int)config('app.crl_max_signature_age_hours', 720);
        $maxAge = Carbon::now()->subHours($maxHours);

        return $thisUpdateDate->isAfter($maxAge);
    }

}

