<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test successful batch creation.
     */
    public function test_successful_batch_creation(): void
    {
        $payload = [
            'urls' => [
                'https://example.com/file1.txt',
                'https://example.com/image.png',
            ],
        ];

        $response = $this->postJson('/api/batches', $payload);

        $response->assertStatus(202);

        // Validate JSON structure
        $response->assertJsonStructure([
            'data' => [
                'uuid',
                'status',
                'progress',
                'files' => [
                    '*' => [
                        'original_url',
                        'status',
                        'error_message',
                        'started_at',
                        'finished_at',
                    ]
                ],
                'created_at',
                'updated_at',
            ]
        ]);

        // Validate values
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.progress', 0);
        $response->assertJsonCount(2, 'data.files');
        $response->assertJsonPath('data.files.0.original_url', 'https://example.com/file1.txt');
        $response->assertJsonPath('data.files.0.status', 'pending');

        // Validate database persistence
        $this->assertDatabaseHas('batches', [
            'status' => 'pending',
            'progress' => 0,
        ]);

        $this->assertDatabaseHas('batch_files', [
            'original_url' => 'https://example.com/file1.txt',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('batch_files', [
            'original_url' => 'https://example.com/image.png',
            'status' => 'pending',
        ]);
    }

    /**
     * Test empty URL list.
     */
    public function test_empty_url_list_returns_validation_error(): void
    {
        $payload = [
            'urls' => [],
        ];

        $response = $this->postJson('/api/batches', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['urls']);
    }

    /**
     * Test more than 5 URLs.
     */
    public function test_more_than_5_urls_returns_validation_error(): void
    {
        $payload = [
            'urls' => [
                'https://example.com/1.txt',
                'https://example.com/2.txt',
                'https://example.com/3.txt',
                'https://example.com/4.txt',
                'https://example.com/5.txt',
                'https://example.com/6.txt',
            ],
        ];

        $response = $this->postJson('/api/batches', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['urls']);
    }

    /**
     * Test invalid URL format.
     */
    public function test_invalid_url_format_returns_validation_error(): void
    {
        $payload = [
            'urls' => [
                'not-a-valid-url',
            ],
        ];

        $response = $this->postJson('/api/batches', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['urls.0']);
    }

    /**
     * Test HTTP instead of HTTPS.
     */
    public function test_http_instead_of_https_returns_validation_error(): void
    {
        $payload = [
            'urls' => [
                'http://example.com/insecure.txt',
            ],
        ];

        $response = $this->postJson('/api/batches', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['urls.0']);
    }

    /**
     * Test retrieve batch by UUID.
     */
    public function test_retrieve_batch_by_uuid(): void
    {
        $batch = Batch::create([
            'status' => \App\Enums\BatchStatus::Processing,
            'progress' => 50,
        ]);

        $batch->batchFiles()->create([
            'original_url' => 'https://example.com/file.zip',
            'status' => \App\Enums\FileStatus::Downloading,
        ]);

        $response = $this->getJson("/api/batches/{$batch->uuid}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.uuid', $batch->uuid);
        $response->assertJsonPath('data.status', 'processing');
        $response->assertJsonPath('data.progress', 50);
        $response->assertJsonCount(1, 'data.files');
        $response->assertJsonPath('data.files.0.original_url', 'https://example.com/file.zip');
        $response->assertJsonPath('data.files.0.status', 'downloading');
    }

    /**
     * Test retrieve batch list.
     */
    public function test_retrieve_batch_list(): void
    {
        $batch1 = Batch::create([
            'status' => \App\Enums\BatchStatus::Completed,
            'progress' => 100,
        ]);

        $batch2 = Batch::create([
            'status' => \App\Enums\BatchStatus::Pending,
            'progress' => 0,
        ]);

        $response = $this->getJson('/api/batches');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'uuid',
                    'status',
                    'progress',
                    'files',
                    'created_at',
                    'updated_at',
                ]
            ]
        ]);
        
        $response->assertJsonCount(2, 'data');
    }
}
