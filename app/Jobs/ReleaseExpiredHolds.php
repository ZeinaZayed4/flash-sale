<?php

namespace App\Jobs;

use App\Models\Hold;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use function Symfony\Component\Translation\t;

class ReleaseExpiredHolds implements ShouldQueue
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
        return 'release-expired-holds';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $expiredHolds = Hold::findExpired();
        $count = $expiredHolds->count();

        if ($count == 0) {
            Log::debug('No expired holds to release');
            return;
        }

        Log::info('Starting expired holds cleanup', [
            'expired_count' => $count,
        ]);

        $released = 0;
        $errors = 0;

        foreach ($expiredHolds as $hold) {
            try {
                $hold->release();
                $released++;
            } catch (Exception $e) {
                $errors++;
                Log::error('Failed to release hold', [
                    'hold_id' => $hold->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('Expired holds cleanup completed', [
            'total_found' => $count,
            'released' => $released,
            'errors' => $errors,
            'duration_ms' => $duration,
        ]);
    }
}
