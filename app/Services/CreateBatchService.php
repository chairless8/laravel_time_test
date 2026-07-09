<?php

namespace App\Services;

use App\Contracts\CreateBatchServiceInterface;
use App\Enums\BatchStatus;
use App\Enums\FileStatus;
use App\Events\BatchCreated;
use App\Jobs\ProcessBatchJob;
use App\Models\Batch;
use Illuminate\Support\Facades\DB;

class CreateBatchService implements CreateBatchServiceInterface
{
    /**
     * Create a new compression batch and its batch files.
     *
     * @param array<int, string> $urls
     * @return Batch
     */
    public function execute(array $urls): Batch
    {
        $batch = DB::transaction(function () use ($urls) {
            $batch = Batch::create([
                'status' => BatchStatus::Pending,
                'progress' => 0,
            ]);

            foreach ($urls as $url) {
                $batch->batchFiles()->create([
                    'original_url' => $url,
                    'status' => FileStatus::Pending,
                ]);
            }

            // Dispatch domain event BatchCreated
            event(new BatchCreated($batch));

            return $batch;
        });

        // Dispatch ProcessBatchJob immediately after transaction is successfully committed
        ProcessBatchJob::dispatch($batch->id);

        return $batch;
    }
}
