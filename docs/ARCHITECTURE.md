# ARCHITECTURE

## System Architecture

The application follows a layered architecture that separates HTTP request handling, business logic, persistence and background processing.

```
                    Client
                       │
                REST API Request
                       │
                Laravel Controllers
                       │
              Request Validation
                       │
                Application Services
                       │
          Persist Batch Information
                       │
              Dispatch Queue Job
                       │
               HTTP 202 Accepted
                       │
                -------------------
                       │
                  Queue System
                       │
               ProcessBatch Job
                       │
               Download File(s)
                       │
                Compress File(s)
                       │
               Store Compressed File
                       │
            Update Batch/File Status
                       │
                 Query Batch Status
```

---

# Architecture Overview

The system is divided into four logical layers.

## API Layer

Responsible for:

* Receiving client requests.
* Validating incoming data.
* Creating batches.
* Returning HTTP responses.
* Dispatching background jobs.

This layer performs no heavy processing.

---

## Queue Layer

Responsible for scheduling asynchronous work.

The API dispatches a single job containing only the Batch identifier.

Example payload:

```json
{
    "batch_id": 15
}
```

Only the Batch ID is sent to the queue because the database is considered the single source of truth.

Keeping queue payloads small improves reliability and simplifies retries.

---

## Worker Layer

The worker performs all expensive operations.

Responsibilities:

* Retrieve the Batch from the database.
* Acquire processing lock.
* Download every file.
* Validate downloaded content.
* Compress the file.
* Store the compressed result.
* Persist metadata.
* Update file status.
* Update batch progress.
* Release processing lock.

The worker is completely independent from the API process.

---

## Persistence Layer

The database stores all information required to recover processing state.

Three primary entities are used.

### Batch

Represents a client request.

Stores:

* Batch identifier
* Overall status
* Progress
* Creation timestamps

Relationship:

```
Batch
  |
  | 1
  |
  | *
BatchFile
```

---

### BatchFile

Represents a single submitted URL.

Stores:

* Original URL
* Processing status
* Error messages
* Reference to generated file

Relationship:

```
BatchFile
    |
    | *
    |
    | 1
   File
```

---

### File

Represents a compressed file stored by the system.

Stores:

* Original filename
* Compressed filename
* MIME type
* Original size
* Compressed size
* Storage path

Although this entity could have been merged into BatchFile, it was intentionally separated to keep responsibilities isolated and allow future features such as:

* File deduplication
* Reusable compressed files
* Storage migrations
* Metadata expansion

---

# Processing Flow

The complete lifecycle is:

1. Client submits a batch.
2. Request validation succeeds.
3. Batch is stored.
4. BatchFiles are stored.
5. Job is dispatched.
6. API returns HTTP 202 Accepted.
7. Worker starts processing.
8. Each file is processed independently.
9. Batch progress is updated.
10. Batch status becomes Completed, Failed or Partially Completed.

---

# Batch Status Lifecycle

Possible batch states:

```
Pending

↓

Processing

↓

Completed
Failed
Partially Completed
```

---

# File Status Lifecycle

Each file maintains its own state.

```
Pending

↓

Downloading

↓

Compressing

↓

Completed
```

Failure path:

```
Pending

↓

Downloading

↓

Failed
```

or

```
Compressing

↓

Failed
```

This allows partial success inside a batch.

---

# Concurrency Strategy

Background jobs must be idempotent.

Before processing begins, the worker attempts to acquire a lock using the Batch identifier.

Only one worker may process the same batch simultaneously.

If another worker receives the same Batch ID:

* It detects the lock.
* Processing is skipped.
* The duplicated job exits safely.

This prevents duplicate downloads and inconsistent status updates.

Different batches can still be processed concurrently by multiple workers.

---

# Rate Control

Concurrency is intentionally limited by the number of active queue workers.

Each worker processes one job at a time.

Increasing throughput only requires increasing the number of workers.

This approach keeps the implementation simple while remaining horizontally scalable.

---

# Error Handling Strategy

Errors are isolated per file.

Possible failures include:

* Invalid URL
* Download timeout
* Unsupported file type
* Maximum file size exceeded
* Compression failure

A single failed file never causes the remaining files in the same batch to stop processing.

---

# Storage Strategy

Compressed files are stored locally using Laravel Storage.

Reasons:

* Simple deployment.
* No external dependencies.
* Easy Docker integration.
* Suitable for the assignment scope.

A production implementation would likely replace local storage with an object storage solution such as Amazon S3.

---

# Design Decisions

The following decisions were intentionally made:

* Laravel Queue for asynchronous processing.
* MySQL for persistence.
* Local storage for compressed files.
* Queue payload contains only the Batch ID.
* Separate File entity for future extensibility.
* Independent status tracking for each file.
* Background jobs designed to be idempotent.

---

# Future Improvements

Given additional development time, the following enhancements would be considered:

* Redis + Horizon
* Amazon S3
* Distributed workers
* Retry policies
* File deduplication
* Content hashing
* Authentication
* Metrics and monitoring
* Distributed locking
* Horizontal scaling
* Event-driven notifications
