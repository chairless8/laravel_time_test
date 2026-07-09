<?php

namespace App\Jobs;

use App\Contracts\ProcessBatchServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected int $batchId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ProcessBatchServiceInterface $service): void
    {
        $service->execute($this->batchId);
    }
}
