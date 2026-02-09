<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('base')->with([
        'cpsErrors' => \App\Models\Issue::where("issue_type", "LIKE", "CPS: %")->where("is_resolved", "=", false)->count(),
        'crlErrors' => \App\Models\Issue::where("issue_type", "LIKE", "CRL: %")->where("is_resolved", "=", false)->count(),
        'totalCaRecords' => \App\Models\CaCertificateRecord::count(),
        'ccadbLastRefresh' => \App\Models\Setting::where("key", "=", "last_ccadb_refresh")->first()->value ?? 'N/A',
        'cpsUrlLastCheck' => \App\Models\Setting::where("key", "=", "last_cpcps_url_check")->first()->value ?? 'N/A',
        'crlUrlLastCheck' => \App\Models\Setting::where("key", "=", "last_crl_url_check")->first()->value ?? 'N/A',
        'errors' => \App\Models\Issue::where("is_resolved", "=", false)->orderBy("last_detected_at", "desc")
            ->join('ca_certificate_records', 'ca_certificate_records.id', '=', 'issues.ca_certificate_record_id') ->get()

    ]);
});

Route::get('/recheck/ccadb', function () {
    \App\Jobs\RefreshCcadb::dispatch();

    return back()->with(
        'status',
        'CCADB refresh queued. This may take several minutes.'
    );
})->name('recheck.ccadb');

Route::get('/recheck/cps', function () {
    \App\Jobs\VerifyCpsUrls::dispatch();

    return back()->with(
        'status',
        'CPS URL verification queued.'
    );
})->name('recheck.cps');


Route::get('/recheck/crl', function () {
    \App\Jobs\VerifyCrlUrls::dispatch();

    return back()->with(
        'status',
        'CRL URL re-check queued. This may take several minutes.'
    );
})->name('recheck.crl');

