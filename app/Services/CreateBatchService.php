<?php

namespace App\Services;

use App\Enums\BatchStatus;
use App\Enums\FileStatus;
use App\Models\Batch;
use Illuminate\Support\Facades\DB;

class CreateBatchService
{
    /**
     * Create a new compression batch and its batch files.
     *
     * @param array<int, string> $urls
     * @return Batch
     */
    public function execute(array $urls): Batch
    {
        return DB::transaction(function () use ($urls) {
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

            return $batch;
        });
    }
}
