<?php

namespace App\Services;

use App\Contracts\ProcessBatchServiceInterface;
use App\Enums\BatchStatus;
use App\Enums\FileStatus;
use App\Events\BatchCompleted;
use App\Events\BatchProcessingStarted;
use App\Models\Batch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessBatchService implements ProcessBatchServiceInterface
{
    public function __construct(
        protected DownloadFileService $downloadService,
        protected CompressFileService $compressService,
        protected StoreCompressedFileService $storageService
    ) {}

    /**
     * Execute the processing workflow for the specified batch.
     *
     * @param int $batchId
     * @return void
     */
    public function execute(int $batchId): void
    {
        Log::info("ProcessBatchService: Verification started for Batch {$batchId}.");

        $batch = Batch::find($batchId);

        if (!$batch) {
            Log::warning("ProcessBatchService: Batch {$batchId} not found. Gracefully exiting.");
            return;
        }

        // Idempotency check: Skip if already finished
        if (in_array($batch->status, [BatchStatus::Completed, BatchStatus::PartiallyCompleted, BatchStatus::Failed])) {
            Log::info("ProcessBatchService: Batch {$batchId} is already completed (status: {$batch->status->value}). Skipping.");
            return;
        }

        // Acquire concurrency lock
        $lockKey = "batch_processing_lock_{$batchId}";
        $lockTtl = config('compression.lock_ttl', 300);
        $lock = Cache::lock($lockKey, $lockTtl);

        if (!$lock->get()) {
            Log::warning("ProcessBatchService: Batch {$batchId} is already locked by another worker. Skipping.");
            return;
        }

        Log::info("ProcessBatchService: Lock acquired for Batch {$batchId} with TTL of {$lockTtl} seconds.");

        try {
            // Mark batch as processing
            $batch->update([
                'status' => BatchStatus::Processing,
            ]);

            event(new BatchProcessingStarted($batch));
            Log::info("ProcessBatchService: Batch {$batchId} status transitioned to Processing.");

            $batchFiles = $batch->batchFiles;
            $totalFiles = $batchFiles->count();

            if ($totalFiles === 0) {
                $batch->update([
                    'status' => BatchStatus::Completed,
                    'progress' => 100,
                    'finished_at' => Carbon::now(),
                ]);
                event(new BatchCompleted($batch));
                Log::info("ProcessBatchService: Batch {$batchId} completed successfully with 0 files.");
                return;
            }

            $allSucceeded = true;
            $allFailed = true;

            foreach ($batchFiles as $index => $batchFile) {
                // Idempotency check: Skip if file was already completed in a previous attempt
                if ($batchFile->status === FileStatus::Completed) {
                    Log::info("ProcessBatchService: BatchFile {$batchFile->id} is already Completed. Skipping duplicate processing.");
                    $allFailed = false; // At least this file succeeded previously

                    // Update progress inline even for skipped files
                    $progress = (int) (($index + 1) / $totalFiles * 100);
                    $batch->update(['progress' => $progress]);
                    continue;
                }

                $tempPath = null;
                $zipPath = null;

                try {
                    // 1. Transition to Downloading and record started_at
                    $batchFile->update([
                        'status' => FileStatus::Downloading,
                        'started_at' => $batchFile->started_at ?? Carbon::now(),
                    ]);
                    Log::info("ProcessBatchService: BatchFile {$batchFile->id} status transitioned to Downloading.");

                    // 2. Download remote file
                    $tempPath = $this->downloadService->download($batchFile->original_url);
                    Log::info("ProcessBatchService: BatchFile {$batchFile->id} downloaded successfully.");

                    // Verify downloaded file exists and is not empty
                    if (!file_exists($tempPath)) {
                        throw new \Exception("Downloaded temporary file does not exist locally.");
                    }

                    // 3. Validate content type and size
                    $fileSize = @filesize($tempPath);
                    if ($fileSize === false || $fileSize === 0) {
                        throw new \Exception("Downloaded file is empty or missing.");
                    }

                    if ($fileSize > config('compression.max_file_size')) {
                        throw new \Exception("File size exceeds limit of " . (config('compression.max_file_size') / 1024 / 1024) . " MB.");
                    }

                    $mimeType = @mime_content_type($tempPath);
                    if (!$mimeType) {
                        throw new \Exception("Could not determine file content type.");
                    }

                    // Coerce mime type for markdown/text files starting with HTML tags
                    $urlPath = parse_url($batchFile->original_url, PHP_URL_PATH);
                    $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
                    if (($mimeType === 'text/html' || $mimeType === 'text/plain') && in_array($extension, ['md', 'txt', 'csv'])) {
                        if ($extension === 'csv') {
                            $mimeType = 'text/csv';
                        } else {
                            $mimeType = 'text/plain';
                        }
                    }

                    if (!in_array($mimeType, config('compression.allowed_mime_types'))) {
                        throw new \Exception("Unsupported content type: {$mimeType}.");
                    }

                    // 4. Transition to Downloaded
                    $batchFile->update([
                        'status' => FileStatus::Downloaded,
                    ]);
                    Log::info("ProcessBatchService: BatchFile {$batchFile->id} status transitioned to Downloaded.");

                    // Parse filename from URL
                    $originalFilename = basename(parse_url($batchFile->original_url, PHP_URL_PATH));
                    if (!$originalFilename) {
                        $originalFilename = 'downloaded_file';
                    }

                    // 5. Transition to Compressing
                    $batchFile->update([
                        'status' => FileStatus::Compressing,
                    ]);
                    Log::info("ProcessBatchService: BatchFile {$batchFile->id} status transitioned to Compressing.");

                    // 6. Compress file
                    $zipPath = $this->compressService->compress($tempPath, $originalFilename);
                    Log::info("ProcessBatchService: BatchFile {$batchFile->id} zipped successfully.");

                    if (!file_exists($zipPath)) {
                        throw new \Exception("Zipped temporary file does not exist locally.");
                    }

                    // 7. Transition to Stored
                    $batchFile->update([
                        'status' => FileStatus::Stored,
                    ]);
                    Log::info("ProcessBatchService: BatchFile {$batchFile->id} status transitioned to Stored.");

                    $checksum = hash_file('sha256', $tempPath);
                    
                    // Store the ZIP, save File model and link to BatchFile
                    $this->storageService->store(
                        $zipPath,
                        $originalFilename,
                        $mimeType,
                        $fileSize,
                        $checksum,
                        $batchFile
                    );
                    Log::info("ProcessBatchService: BatchFile {$batchFile->id} stored in Laravel Storage.");

                    // 10. Transition to Completed
                    $batchFile->update([
                        'status' => FileStatus::Completed,
                        'finished_at' => Carbon::now(),
                        'error_message' => null, // Clear any previous error on retry success
                    ]);
                    Log::info("ProcessBatchService: BatchFile {$batchFile->id} status transitioned to Completed.");

                    $allFailed = false;
                } catch (\Throwable $e) {
                    Log::error("ProcessBatchService: BatchFile {$batchFile->id} failed: " . $e->getMessage());

                    $batchFile->update([
                        'status' => FileStatus::Failed,
                        'error_message' => $e->getMessage(),
                        'finished_at' => Carbon::now(),
                    ]);

                    $allSucceeded = false;
                } finally {
                    // Clean up local temp files
                    if ($tempPath && file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                    if ($zipPath && file_exists($zipPath)) {
                        @unlink($zipPath);
                    }
                }

                // Update Batch progress after every processed file
                $progress = (int) (($index + 1) / $totalFiles * 100);
                $batch->update([
                    'progress' => $progress,
                ]);
            }

            // Determine final Batch status
            $finalStatus = BatchStatus::Failed;
            if ($allSucceeded) {
                $finalStatus = BatchStatus::Completed;
            } elseif (!$allFailed) {
                $finalStatus = BatchStatus::PartiallyCompleted;
            }

            $batch->update([
                'status' => $finalStatus,
                'finished_at' => Carbon::now(),
            ]);

            event(new BatchCompleted($batch));
            Log::info("ProcessBatchService: Batch {$batchId} processing completed. Final status: {$finalStatus->value}.");
        } catch (\Throwable $e) {
            Log::critical("ProcessBatchService: Unexpected fatal error while processing Batch {$batchId}: " . $e->getMessage());

            // Failure Recovery: Transition database records to a consistent failed state
            try {
                $batch->update([
                    'status' => BatchStatus::Failed,
                    'finished_at' => Carbon::now(),
                ]);

                foreach ($batch->batchFiles as $bf) {
                    if ($bf->status !== FileStatus::Completed) {
                        $bf->update([
                            'status' => FileStatus::Failed,
                            'error_message' => 'Unexpected fatal error: ' . $e->getMessage(),
                            'finished_at' => Carbon::now(),
                        ]);
                    }
                }

                event(new BatchCompleted($batch));
            } catch (\Throwable $dbException) {
                Log::error("ProcessBatchService: Failed to mark database records as failed: " . $dbException->getMessage());
            }
        } finally {
            // Release concurrency lock
            $lock->release();
            Log::info("ProcessBatchService: Lock released for Batch {$batchId}.");
        }
    }
}
