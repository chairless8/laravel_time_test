<?php

namespace Tests\Feature;

use App\Events\BatchCompleted;
use App\Events\BatchCreated;
use App\Events\BatchProcessingStarted;
use App\Jobs\ProcessBatchJob;
use App\Models\Batch;
use App\Enums\BatchStatus;
use App\Enums\FileStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use App\Contracts\ProcessBatchServiceInterface;
use Tests\TestCase;

class QueueIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the ProcessBatchJob is dispatched when a batch is created via the API.
     */
    public function test_job_and_batch_created_event_dispatched_on_batch_creation(): void
    {
        Queue::fake();
        Event::fake([BatchCreated::class]);

        $payload = [
            'urls' => [
                'https://example.com/file.txt',
            ],
        ];

        $response = $this->postJson('/api/batches', $payload);

        $response->assertStatus(202);

        $batch = Batch::first();
        $this->assertNotNull($batch);

        // Verify job was dispatched with the correct batch ID
        Queue::assertPushed(ProcessBatchJob::class, function (ProcessBatchJob $job) use ($batch) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('batchId');
            $property->setAccessible(true);
            return $property->getValue($job) === $batch->id;
        });

        // Verify BatchCreated event was dispatched
        Event::assertDispatched(BatchCreated::class, function (BatchCreated $event) use ($batch) {
            return $event->batch->id === $batch->id;
        });
    }

    /**
     * Test job execution and all status transitions + events.
     */
    public function test_queue_execution_batch_and_file_status_transitions_and_events(): void
    {
        Http::fake([
            'https://example.com/file1.txt' => Http::response('Hello queue', 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        Event::fake([BatchProcessingStarted::class, BatchCompleted::class]);

        // Create a batch with files
        $batch = Batch::create([
            'status' => BatchStatus::Pending,
            'progress' => 0,
        ]);

        $batchFile = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/file1.txt',
            'status' => FileStatus::Pending,
        ]);

        // Assert initial state
        $this->assertEquals(BatchStatus::Pending, $batch->status);
        $this->assertEquals(0, $batch->progress);
        $this->assertEquals(FileStatus::Pending, $batchFile->status);

        // Execute the job directly
        $job = new ProcessBatchJob($batch->id);
        $job->handle(app(ProcessBatchServiceInterface::class));

        // Refresh models
        $batch->refresh();
        $batchFile->refresh();

        // Assert completed state
        $this->assertEquals(BatchStatus::Completed, $batch->status);
        $this->assertEquals(100, $batch->progress);
        $this->assertNotNull($batch->finished_at);
        
        $this->assertEquals(FileStatus::Completed, $batchFile->status);
        $this->assertNotNull($batchFile->started_at);
        $this->assertNotNull($batchFile->finished_at);

        // Verify events were dispatched
        Event::assertDispatched(BatchProcessingStarted::class, function (BatchProcessingStarted $event) use ($batch) {
            return $event->batch->id === $batch->id;
        });

        Event::assertDispatched(BatchCompleted::class, function (BatchCompleted $event) use ($batch) {
            return $event->batch->id === $batch->id;
        });
    }
}
