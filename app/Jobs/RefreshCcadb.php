<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RefreshCcadb implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800; // 30 minutes

    public function handle(): void
    {
        Artisan::call('refresh:ccadb-records');
    }

    public function failed(Throwable $e): void
    {
        logger()->error('CCADB refresh job failed', [
            'exception' => $e,
        ]);
    }
}
