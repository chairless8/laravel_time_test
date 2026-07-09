# Project Overview

## Objective

The goal of this project is to build a REST API capable of receiving a batch of file URLs, validating them, downloading and compressing each file asynchronously, and exposing their status and progress through API endpoints.

This project focuses on showcasing software engineering practices, clean architecture, single-responsibility services, and operational robustness (idempotency, lock-based concurrency control, failover safety) within the scope of a technical interview backend assignment.

---

## Project Scope

The application supports:

- **Creating Compression Batches**: Submitting 1 to 5 HTTPS file URLs per batch via POST request.
- **Asynchronous Processing**: Immediate job dispatch to database-driven queue workers to process files in the background.
- **Service-Oriented Processing**: A modular download, validation, compression, and storage pipeline.
- **Individual Status Tracking**: Explicit state transition modeling for each batch file.
- **Error Isolation**: Failure to download or validate a single URL does not impact the remaining items in a batch.
- **Batch Progress Monitoring**: Real-time progress updates calculated and tracked inside the batch record.
- **Resilient Operations**: Duplicate execution prevention via cache-based concurrency locks and idempotent skips.
- **Local Storage Storage**: Persisting generated ZIP archives inside the application's local filesystem.

---

## Design Principles

This implementation follows several core software development principles:

- **Single Responsibility**: Every layer and service owns a single job (HTTP routing, validation, download helper, zipping, database persistence).
- **Graceful Error Isolation**: Errors (HTTP timeouts, non-200 responses, unsupported MIME types, size failures) are caught individually and recorded as distinct `error_message` fields inside the `BatchFile` record.
- **Idempotency**: Running the same job multiple times or retrying a failed/partial batch will skip files that are already completed, avoiding duplicate work.
- **Service Container Binding**: Services are decoupled via contracts/interfaces (`CreateBatchServiceInterface`, `ProcessBatchServiceInterface`) registered in the Laravel Service Container.

---

## Assumptions

- **Secure Connections**: The API enforces HTTPS URL validation for all submitted file URLs.
- **MIME Validations**: The system supports plain text (`text/plain`), CSV (`text/csv`), and PDF (`application/pdf`) files. Other formats are rejected.
- **Size Limits**: The maximum download size for a single file is limited to 20 MB.
- **No User Management**: The service operates as a public utility (no authentication required) for the purposes of the assignment.
- **Single-Host Local Storage**: Files are saved to local disks, matching the single-host Docker Compose configuration.

---

## Out of Scope

The following features were intentionally excluded to maintain a simple, maintainable project structure matching the assignment's technical guidelines:

- Authentication or API key validation.
- Distributed object storage (e.g. Amazon S3, MinIO).
- CDN/Edge distribution.
- Virus scanning or media processing.
- Distributed queue scaling dashboards (e.g., Laravel Horizon).
- Resume interrupted downloads (resumable range requests).

---

## Technical Success Criteria

- **High Responsiveness**: Endpoint requests return immediately (HTTP 202 Accepted) after persisting the batch, deferring all heavy CPU/IO processing to background queue workers.
- **Deterministic Workflows**: Multi-worker environments do not trigger race conditions or duplicate downloads thanks to atomic batch locks.
- **Isolated Failure Paths**: A batch containing a mix of valid URLs, invalid mime-types, and connection timeouts will progress to `partially_completed`, preserving the zips for the successful files and documenting the failure causes for the bad files.
- **100% Docker Portability**: The service builds, seeds, runs, queues, and tests inside Docker using simple Docker Compose commands.