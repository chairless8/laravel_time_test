<?php

namespace Tests\Feature;

use App\Contracts\ProcessBatchServiceInterface;
use App\Events\BatchCompleted;
use App\Enums\BatchStatus;
use App\Enums\FileStatus;
use App\Models\Batch;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    /**
     * Test successful download, validation, compression, storage, and persistence.
     */
    public function test_successful_file_processing_pipeline(): void
    {
        Event::fake([BatchCompleted::class]);

        // Mock remote download response
        Http::fake([
            'https://example.com/valid.txt' => Http::response('Valid text content', 200, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => 'attachment; filename="valid.txt"',
            ]),
        ]);

        $batch = Batch::create([
            'status' => BatchStatus::Pending,
            'progress' => 0,
        ]);

        $batchFile = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/valid.txt',
            'status' => FileStatus::Pending,
        ]);

        // Resolve service and execute
        $service = app(ProcessBatchServiceInterface::class);
        $service->execute($batch->id);

        // Refresh models
        $batch->refresh();
        $batchFile->refresh();

        $this->assertEquals(BatchStatus::Completed, $batch->status);
        $this->assertEquals(100, $batch->progress);

        $this->assertEquals(FileStatus::Completed, $batchFile->status);
        $this->assertNull($batchFile->error_message);
        $this->assertNotNull($batchFile->file_id);

        // Assert file metadata persisted in files table
        $fileModel = File::find($batchFile->file_id);
        $this->assertNotNull($fileModel);
        $this->assertEquals('valid.txt', $fileModel->original_filename);
        $this->assertEquals('text/plain', $fileModel->mime_type);
        $this->assertEquals(strlen('Valid text content'), $fileModel->original_size);
        $this->assertStringContainsString('compressions/valid_', $fileModel->storage_path);
        $this->assertEquals(hash('sha256', 'Valid text content'), $fileModel->checksum);

        // Assert ZIP file exists in Laravel Storage
        Storage::assertExists($fileModel->storage_path);
        
        // Assert ZIP content is valid and contains our file
        $fullPath = Storage::path($fileModel->storage_path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($fullPath));
        $this->assertEquals('valid.txt', $zip->getNameIndex(0));
        $zip->close();

        Event::assertDispatched(BatchCompleted::class);
    }

    /**
     * Test validation fails when MIME type is unsupported.
     */
    public function test_unsupported_mime_type_fails(): void
    {
        Http::fake([
            'https://example.com/unsupported.png' => Http::response("\x89PNG\r\n\x1a\n", 200, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'attachment; filename="unsupported.png"',
            ]),
        ]);

        $batch = Batch::create(['status' => BatchStatus::Pending, 'progress' => 0]);
        $batchFile = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/unsupported.png',
            'status' => FileStatus::Pending,
        ]);

        $service = app(ProcessBatchServiceInterface::class);
        $service->execute($batch->id);

        $batch->refresh();
        $batchFile->refresh();

        $this->assertEquals(BatchStatus::Failed, $batch->status);
        $this->assertEquals(FileStatus::Failed, $batchFile->status);
        $this->assertStringContainsString('Unsupported content type: application/octet-stream', $batchFile->error_message);
    }

    /**
     * Test HTTP request timeout error handling.
     */
    public function test_download_timeout_fails_gracefully(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException("Connection timed out.");
        });

        $batch = Batch::create(['status' => BatchStatus::Pending, 'progress' => 0]);
        $batchFile = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/timeout.txt',
            'status' => FileStatus::Pending,
        ]);

        $service = app(ProcessBatchServiceInterface::class);
        $service->execute($batch->id);

        $batch->refresh();
        $batchFile->refresh();

        $this->assertEquals(BatchStatus::Failed, $batch->status);
        $this->assertEquals(FileStatus::Failed, $batchFile->status);
        $this->assertStringContainsString('HTTP download error: Connection timed out.', $batchFile->error_message);
    }

    /**
     * Test HTTP client non-successful download response (e.g. 404).
     */
    public function test_download_http_error_fails_gracefully(): void
    {
        Http::fake([
            'https://example.com/notfound.txt' => Http::response('Not Found', 404),
        ]);

        $batch = Batch::create(['status' => BatchStatus::Pending, 'progress' => 0]);
        $batchFile = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/notfound.txt',
            'status' => FileStatus::Pending,
        ]);

        $service = app(ProcessBatchServiceInterface::class);
        $service->execute($batch->id);

        $batch->refresh();
        $batchFile->refresh();

        $this->assertEquals(BatchStatus::Failed, $batch->status);
        $this->assertEquals(FileStatus::Failed, $batchFile->status);
        $this->assertStringContainsString('Failed to download file from URL', $batchFile->error_message);
        $this->assertStringContainsString('Status code: 404', $batchFile->error_message);
    }

    /**
     * Test partial batch success (one URL succeeds, one fails).
     */
    public function test_partial_batch_success(): void
    {
        Http::fake([
            'https://example.com/success.txt' => Http::response('Success text', 200, [
                'Content-Type' => 'text/plain',
            ]),
            'https://example.com/fail.png' => Http::response("\x89PNG\r\n\x1a\n", 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $batch = Batch::create(['status' => BatchStatus::Pending, 'progress' => 0]);
        $batchFile1 = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/success.txt',
            'status' => FileStatus::Pending,
        ]);
        $batchFile2 = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/fail.png',
            'status' => FileStatus::Pending,
        ]);

        $service = app(ProcessBatchServiceInterface::class);
        $service->execute($batch->id);

        $batch->refresh();
        $batchFile1->refresh();
        $batchFile2->refresh();

        $this->assertEquals(BatchStatus::PartiallyCompleted, $batch->status);
        $this->assertEquals(100, $batch->progress);

        $this->assertEquals(FileStatus::Completed, $batchFile1->status);
        $this->assertEquals(FileStatus::Failed, $batchFile2->status);
    }

    /**
     * Test complete batch failure (all files fail).
     */
    public function test_complete_batch_failure(): void
    {
        Http::fake([
            'https://example.com/fail1.png' => Http::response("\x89PNG\r\n\x1a\n", 200, [
                'Content-Type' => 'image/png',
            ]),
            'https://example.com/fail2.png' => Http::response("\x89PNG\r\n\x1a\n", 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $batch = Batch::create(['status' => BatchStatus::Pending, 'progress' => 0]);
        $batchFile1 = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/fail1.png',
            'status' => FileStatus::Pending,
        ]);
        $batchFile2 = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/fail2.png',
            'status' => FileStatus::Pending,
        ]);

        $service = app(ProcessBatchServiceInterface::class);
        $service->execute($batch->id);

        $batch->refresh();
        $batchFile1->refresh();
        $batchFile2->refresh();

        $this->assertEquals(BatchStatus::Failed, $batch->status);
        $this->assertEquals(100, $batch->progress);

        $this->assertEquals(FileStatus::Failed, $batchFile1->status);
        $this->assertEquals(FileStatus::Failed, $batchFile2->status);
    }

    /**
     * Test service orchestration (delegation to sub-services).
     */
    public function test_service_orchestration(): void
    {
        $downloadServiceMock = $this->mock(\App\Services\DownloadFileService::class);
        $compressServiceMock = $this->mock(\App\Services\CompressFileService::class);
        $storageServiceMock = $this->mock(\App\Services\StoreCompressedFileService::class);

        $tempPath = tempnam(sys_get_temp_dir(), 'test_orc_');
        file_put_contents($tempPath, 'Hello Orc');

        $zipPath = tempnam(sys_get_temp_dir(), 'test_orc_zip_');
        file_put_contents($zipPath, 'Fake ZIP bytes');

        $downloadServiceMock->shouldReceive('download')
            ->once()
            ->andReturn($tempPath);

        $compressServiceMock->shouldReceive('compress')
            ->once()
            ->andReturn($zipPath);

        $storageServiceMock->shouldReceive('store')
            ->once()
            ->andReturn(new File());

        config(['compression.max_file_size' => 1000]);
        config(['compression.allowed_mime_types' => ['text/plain']]);

        $batch = Batch::create(['status' => BatchStatus::Pending, 'progress' => 0]);
        $batchFile = $batch->batchFiles()->create([
            'original_url' => 'https://example.com/valid.txt',
            'status' => FileStatus::Pending,
        ]);

        $service = new \App\Services\ProcessBatchService(
            $downloadServiceMock,
            $compressServiceMock,
            $storageServiceMock
        );

        $service->execute($batch->id);

        @unlink($tempPath);
        @unlink($zipPath);
    }
}
