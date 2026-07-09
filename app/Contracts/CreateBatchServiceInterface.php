<?php

namespace App\Contracts;

use App\Models\Batch;

interface CreateBatchServiceInterface
{
    /**
     * Create a new compression batch and its batch files.
     *
     * @param array<int, string> $urls
     * @return Batch
     */
    public function execute(array $urls): Batch;
}
