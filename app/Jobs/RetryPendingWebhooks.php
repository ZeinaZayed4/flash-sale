<?php

namespace App\Jobs;

use App\Models\PaymentWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RetryPendingWebhooks implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    public function uniqueId(): string
    {
        return 'retry-pending=webhooks';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting pending webhooks retry job');

        $processed = PaymentWebhook::retryPendingWebhooks();

        Log::info('Pending webhooks retry completed', [
            'processed_count' => $processed,
        ]);
    }
}
