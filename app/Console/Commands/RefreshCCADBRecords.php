<?php

namespace App\Console\Commands;

use App\Models\CaCertificateRecord;
use App\Models\Setting;
use Illuminate\Console\Command;

class RefreshCCADBRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'refresh:ccadb-records';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh CCADB certificate records from the configured source and update the local database accordingly. This is only required if using the Integrated and WebUI modes.';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $CCADB = new \App\Helper\CCADB();

        try {
            $this->info('Starting CCADB certificate records refresh...');
            $csvContent = $CCADB->getCCADBAllCertificateRecords();
            $records = $CCADB->parseCsvToAssoc($csvContent);
            $this->info("Fetched " . count($records) . " records from CCADB. Sanitizing Records...");
            $records = $CCADB->disregardExpiredAndRevokedCertificates($records);
            $this->info("Processing Records...");

            foreach ($records as $record) {
                //Only add records where CA Owner or Subordinate CA Owner matches app.config('app.ca_owner'), if set.
                if(!empty(config('app.ca_owner'))) {
                    $needle = mb_strtolower(trim((string) config('app.ca_owner')));
                    $caOwner = mb_strtolower(trim((string) $record['CA Owner']));
                    $subOwner = mb_strtolower(trim((string) ($record['Subordinate CA Owner'] ?? '')));
                    if($caOwner !== $needle && $subOwner !== $needle) {
                        continue; // Skip this record
                    }
                }


                $this->info("Processing Record Salesforce ID: " . $record['Salesforce Record ID']);

                if(CaCertificateRecord::where("ccadb_record_id", "=", $record['Salesforce Record ID'])->exists()) {
                    // Update existing record
                    $caRecord = CaCertificateRecord::where("ccadb_record_id", "=", $record['Salesforce Record ID'])->first();
                } else {
                    // Create new record
                    $caRecord = new CaCertificateRecord();
                    $caRecord->ccadb_record_id = $record['Salesforce Record ID'];
                }

                $caRecord->ca_owner = $record['CA Owner'];
                $caRecord->certificate_name = $record['Certificate Name'];
                $caRecord->save();

                //foreach additional column in the CSV, update or create a CaCertificateRecordDetail
                foreach ($record as $field => $value) {
                    if (in_array($field, ['Salesforce Record ID', 'CA Owner', 'Certificate Name'])) {
                        continue; // Skip these fields as they are already handled
                    }
                    $detail = $caRecord->caCertificateRecordDetails()->firstOrNew(['field' => $field]);
                    $detail->value = $value;
                    $detail->save();
                }
            }

            $setting = new Setting();
            $setting->setLastCCADBRefreshNow();
            $this->info('CCADB certificate records have been successfully refreshed.');
        } catch (\RuntimeException $e) {
            $this->error('Error refreshing CCADB records: ' . $e->getMessage());
            return 1;
        }



    }
}
