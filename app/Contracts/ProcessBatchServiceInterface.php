<?php

namespace App\Contracts;

interface ProcessBatchServiceInterface
{
    /**
     * Execute the processing workflow for the specified batch.
     *
     * @param int $batchId
     * @return void
     */
    public function execute(int $batchId): void;
}
