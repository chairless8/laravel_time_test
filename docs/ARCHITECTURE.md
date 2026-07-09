# System Architecture

This document describes the architectural design, processing flows, data models, concurrency control strategies, and error handling behaviors implemented in the Batch File Compression Service.

---

## Architecture Design

The application follows a Service-Oriented Architecture (SOA) that separates HTTP request handling, background job dispatching, orchestration, and specific task execution.

```
                    Client Request
                          │
                   POST /api/batches
                          │
              [ Laravel BatchController ]
                          │
            Validate URL list payload (1-5 URLs)
                          │
         [ CreateBatchServiceInterface ]
                          │
              [ CreateBatchService ]
                          │
           1. Write Batch/BatchFiles to DB
           2. Dispatch ProcessBatchJob
           3. Fire BatchCreated Domain Event
                          │
                   HTTP 202 Accepted
                          │
               =======================
                 Queue (Database)
               =======================
                          │
                  [ ProcessBatchJob ]
                          │
         [ ProcessBatchServiceInterface ]
                          │
              [ ProcessBatchService ]
                          │
             Acquire Atomic Cache Lock
                          │
                  Loop through files
                 (Idempotent check)
                /         |         \
               /          |          \
 [DownloadService] [CompressService] [StoreService]
  Download URL     Zip Archive       Save to Disk
  to temp file     generation        & link to DB
               \          |          /
                \         |         /
                 Update File Progress
                          │
              Finalize Batch Status
           (Completed/Partial/Failed)
                          │
             Release Atomic Cache Lock
                          │
             Fire BatchCompleted Event
```

---

## Architectural Layers

### 1. API Layer
- **Form Request**: `StoreBatchRequest` validates that the payload has a list of `urls`, containing between 1 and 5 elements, all being valid HTTPS URLs.
- **Controller**: `BatchController` handles endpoints (`POST /api/batches`, `GET /api/batches`, `GET /api/batches/{uuid}`) and injects service contracts.
- **Service Contract**: `CreateBatchServiceInterface` binds to `CreateBatchService` in the service container.
- **Responsibility**: Validate input, write initial records, dispatch the queue job, and immediately return a 202 response.

### 2. Queue Orchestration Layer
- **Job**: `ProcessBatchJob` is a thin worker wrapper that accepts the Batch ID in its constructor.
- **Resolving Contracts**: In its `handle()` method, it resolves `ProcessBatchServiceInterface` from Laravel's container and calls it.
- **Queue Payload**: Contains only the Batch ID, keeping the queue lightweight and database-synchronized.

### 3. Service Layer (Business Logic)
The queue job delegates execution to `ProcessBatchService`, which acts as an orchestrator and delegates tasks to specific helper services:
- **`DownloadFileService`**: Streams the remote file to a local temporary file using Laravel HTTP Client. Enforces request timeouts.
- **`CompressFileService`**: Compresses the downloaded file into a ZIP archive using PHP's native `ZipArchive` in a temporary path.
- **`StoreCompressedFileService`**: Copies the ZIP stream to local storage (`Storage::disk('local')`), creates the `File` metadata record, and associates it with the `BatchFile` record.
- **`ProcessBatchService` (Orchestrator)**: Manages lock acquisition, idempotency checks, loops through files, validates size/MIME type after download, calculates batch progress percentages, captures failures, unlinks temp files, updates status fields, and dispatches domain events.

---

## Domain Events

The system communicates lifecycle state transitions using lightweight domain events:
- **`BatchCreated`**: Fired in `CreateBatchService` after the database records are written and the job is queued.
- **`BatchProcessingStarted`**: Fired in `ProcessBatchService` when a worker starts executing a batch.
- **`BatchCompleted`**: Fired in `ProcessBatchService` once all files are processed and the final batch status is resolved.

---

## State Transition Models

### Batch Lifecycle
A batch transitions through the following statuses (`App\Enums\BatchStatus`):
```
Pending ──► Processing ──► Completed (All files processed successfully)
                      ├──► PartiallyCompleted (Some files succeeded, some failed)
                      └──► Failed (All files failed or fatal orchestrator error)
```

### BatchFile Lifecycle
Each file transitions through a granular state machine (`App\Enums\FileStatus`):
```
Pending ──► Downloading ──► Downloaded ──► Compressing ──► Stored ──► Completed
   │            │              │             │              │
   └────────────┴──────────────┴─────────────┴──────────────┴───► Failed
```
*Failure during download, validation, compression, or storage transitions the status directly to `Failed`, logging the error trace inside `error_message`.*

---

## Concurrency & Idempotency Strategy

### Cache-Based Locking
To prevent multiple background workers from processing the same batch concurrently:
- `ProcessBatchService` requests a cache lock named `batch_processing_lock_{batchId}`.
- If the lock is already held, the service logs the event and exits successfully (preventing job failure and constant queue retries).
- The lock has a TTL (default 300 seconds) which automatically releases the lock if a queue worker crashes or is terminated mid-execution.

### Idempotency Skip Checks
- **Batch Level**: If a job starts and the batch status is already `Completed`, `PartiallyCompleted`, or `Failed`, the service exits immediately.
- **File Level**: If a batch execution was interrupted and retried, any `BatchFile` that was already completed (`status === FileStatus::Completed`) is skipped. Only files still in `Pending` or `Failed` status are reprocessed.

---

## Error Handling & Isolation

- **File-Level Isolation**: A `try...catch` wrapper inside the `BatchFile` processing loop ensures that any failure (HTTP timeout, unsupported MIME type, size limit exceed, ZIP compression error) is logged on the specific record without halting the download of remaining files.
- **Orchestrator-Level Recovery**: If a fatal error occurs outside the file loop (e.g. database disconnect, code crash):
  - The `Batch` status is updated to `Failed`.
  - All non-completed `BatchFile` records are marked as `Failed` with the description of the crash.
  - The cache lock is released in a master `finally` block.

---

## Storage Strategy

- **Local Storage**: Files are saved to local disks using Laravel's file abstraction `Storage::disk('local')`. Path is `compressions/{original_name}_{uniqid}.zip`.
- **Database Metadata**: We track details in the `files` table:
  - `original_filename` & `compressed_filename`
  - `mime_type` (e.g. `text/plain`, `text/csv`, `application/pdf`)
  - `original_size` & `compressed_size` (bytes)
  - `storage_path` & `checksum` (SHA-256 hash of the original file content).
- **Separation of Concerns**: Storing files in a distinct `files` table linked via foreign key to `batch_files` isolates file storage metadata from queue tracking.
