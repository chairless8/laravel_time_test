<?php

namespace Tests\Feature;

use App\Contracts\ProcessBatchServiceInterface;
use App\Enums\BatchStatus;
use App\Enums\FileStatus;
use App\Events\BatchCompleted;
use App\Models\Batch;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationalResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Test running a job with a non-existent batch ID exits gracefully.
     */
    public function test_missing_batch_exits_gracefully(): void
    {
        Event::fake([BatchCompleted::class]);

        $service = app(ProcessBatchServiceInterface::class);
        
        // Execute with a non-existent ID
        $service->execute(9999);

        // No exception should be thrown, and no event should be dispatched
        Event::assertNotDispatched(BatchCompleted::class);
    }

    /**
     * Test duplicate job execution on an already finished batch does not run again.
     */
    public function test_completed_batch_is_skipped_idempotently(): void
    {
        Event::fake([BatchCompleted::class]);

        $batch = Batch::create([
            'status' => BatchStatus::Completed,
            'progress' => 100,
        ]);

        $batchFile = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/file.txt',
            'status' => FileStatus::Completed,
        ]);

        $service = app(ProcessBatchServiceInterface::class);
        $service->execute($batch->id);

        // Assert nothing changed
        $batch->refresh();
        $this->assertEquals(BatchStatus::Completed, $batch->status);
        
        // Assert no event was dispatched since processing was skipped
        Event::assertNotDispatched(BatchCompleted::class);
    }

    /**
     * Test lock acquisition prevents multiple workers from processing the same batch concurrently.
     */
    public function test_lock_acquisition_and_contention(): void
    {
        Http::fake([
            'https://example.com/file.txt' => Http::response('Hello', 200, ['Content-Type' => 'text/plain']),
        ]);

        $batch = Batch::create([
            'status' => BatchStatus::Pending,
            'progress' => 0,
        ]);

        $batch->batchFiles()->create([
            'original_url' => 'https://example.com/file.txt',
            'status' => FileStatus::Pending,
        ]);

        // Manually acquire the lock to simulate lock contention
        $lockKey = "batch_processing_lock_{$batch->id}";
        $lock = Cache::lock($lockKey, 300);
        $this->assertTrue($lock->get());

        // Now run the service, it should encounter lock contention and skip execution
        $service = app(ProcessBatchServiceInterface::class);
        $service->execute($batch->id);

        // Assert batch remains Pending because execution was skipped due to lock contention
        $batch->refresh();
        $this->assertEquals(BatchStatus::Pending, $batch->status);

        // Release the lock and run again, it should execute successfully
        $lock->release();
        $service->execute($batch->id);

        $batch->refresh();
        $this->assertEquals(BatchStatus::Completed, $batch->status);
    }

    /**
     * Test idempotent partial execution (skips already completed files on retry).
     */
    public function test_idempotent_partial_execution(): void
    {
        // Fake response only for the pending file
        Http::fake([
            'https://example.com/pending.txt' => Http::response('Pending file data', 200, ['Content-Type' => 'text/plain']),
        ]);

        $batch = Batch::create([
            'status' => BatchStatus::Pending,
            'progress' => 50,
        ]);

        $completedFile = File::create([
            'original_filename' => 'completed.txt',
            'compressed_filename' => 'completed_xxx.zip',
            'mime_type' => 'text/plain',
            'original_size' => 10,
            'compressed_size' => 20,
            'storage_path' => 'compressions/completed_xxx.zip',
        ]);

        $batchFile1 = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/completed.txt',
            'status' => FileStatus::Completed,
            'file_id' => $completedFile->id,
        ]);

        $batchFile2 = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/pending.txt',
            'status' => FileStatus::Pending,
        ]);

        // Run the service
        $service = app(ProcessBatchServiceInterface::class);
        $service->execute($batch->id);

        $batch->refresh();
        $batchFile1->refresh();
        $batchFile2->refresh();

        // The overall batch should be completed
        $this->assertEquals(BatchStatus::Completed, $batch->status);
        $this->assertEquals(100, $batch->progress);

        // The completed file remains untouched, and the pending file becomes Completed
        $this->assertEquals(FileStatus::Completed, $batchFile1->status);
        $this->assertEquals(FileStatus::Completed, $batchFile2->status);
        $this->assertNotNull($batchFile2->file_id);

        // Verify the HTTP fake was never hit for the completed file
        Http::assertNotSent(function ($request) {
            return $request->url() === 'https://example.com/completed.txt';
        });
    }

    /**
     * Test unexpected orchestrator exceptions transition DB records to a clean failed state.
     */
    public function test_unexpected_exception_handling(): void
    {
        $downloadServiceMock = $this->mock(\App\Services\DownloadFileService::class);
        $downloadServiceMock->shouldReceive('download')
            ->andThrow(new \RuntimeException("Fatal database disconnect simulated."));

        $batch = Batch::create([
            'status' => BatchStatus::Pending,
            'progress' => 0,
        ]);

        $batchFile = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/file.txt',
            'status' => FileStatus::Pending,
        ]);

        $service = new \App\Services\ProcessBatchService(
            $downloadServiceMock,
            app(\App\Services\CompressFileService::class),
            app(\App\Services\StoreCompressedFileService::class)
        );

        $service->execute($batch->id);

        $batch->refresh();
        $batchFile->refresh();

        // The batch and batch files should be transitioned to failed
        $this->assertEquals(BatchStatus::Failed, $batch->status);
        $this->assertEquals(FileStatus::Failed, $batchFile->status);
        $this->assertStringContainsString('Fatal database disconnect simulated.', $batchFile->error_message);
    }
}
