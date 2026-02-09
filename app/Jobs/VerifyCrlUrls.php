<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class VerifyCrlUrls implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of attempts.
     */
    public int $tries = 1;

    /**
     * Max execution time (seconds).
     */
    public int $timeout = 900; // 15 minutes

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('verify:urls:crl', [
            '--caowner' => getenv("CA_OWNER"),
            '--mode'    => 'Integrated',
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $e): void
    {
        logger()->error('CRL URL verification job failed', [
            'exception' => $e,
        ]);
    }
}
