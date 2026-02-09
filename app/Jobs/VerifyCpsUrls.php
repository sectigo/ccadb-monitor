<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class VerifyCpsUrls implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900; // 15 minutes

    public function handle(): void
    {
        Artisan::call('verify:urls:cpcps', [
            '--caowner' => getenv('CA_OWNER'),
            '--mode'    => 'Integrated',
        ]);
    }

    public function failed(Throwable $e): void
    {
        logger()->error('CPS URL verification job failed', [
            'exception' => $e,
        ]);
    }
}
