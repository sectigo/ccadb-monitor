<?php

use App\Console\Commands\VerifyCPCPSURLs;
use App\Console\Commands\VerifyCRLURLs;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Schedule::command('refresh:ccadb-records')->hourly();
Schedule::command('verify:urls:crl --caowner=' . getenv('CA_OWNER') . ' --mode=Integrated')->hourly();
Schedule::command('verify:urls:cpcps --caowner=' . getenv('CA_OWNER') . ' --mode=Integrated')->hourly();

