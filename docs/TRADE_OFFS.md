# Trade-offs & Decisions

This document summarizes key architectural choices and engineering trade-offs made during the development of the Batch File Compression Service.

---

## 1. Laravel Database Queue Driver vs. Redis / RabbitMQ

- **Decision**: Used Laravel's native `database` queue driver.
- **Rationale**: 
  - **Simplicity**: Avoids adding container infrastructure dependencies (like Redis, RabbitMQ, or Beanstalkd) to the Docker stack, keeping local deployment simple.
  - **Transactional Integrity**: Migrations and jobs use the same database connection, allowing for reliable cleanups and straightforward querying.
- **Trade-off**: The database driver is not suitable for high-throughput messaging workloads because polling introduces DB read/write overhead. (See [Future Improvements](file:///Volumes/Macintosh%20HD%20-%20Data/2026/github/laravel_time_test/docs/FUTURE_IMPROVEMENTS.md)).

---

## 2. Local Filesystem Storage vs. S3 Cloud Storage

- **Decision**: Stored compressed files locally inside `storage/app/compressions/` using `Storage::disk('local')`.
- **Rationale**: 
  - **Self-contained**: Running and testing the application requires no AWS credentials, network configuration, or MinIO setups.
  - **Zero Cost**: Testing uploads is instant and costs nothing.
  - **Portability**: Laravel's file storage disk abstraction makes it easy to switch to Amazon S3 or Google Cloud Storage in the future by updating config files without changing application code.
- **Trade-off**: Local disk files do not scale across multiple web nodes in a load-balanced production cluster.

---

## 3. Service Layer & Container Bindings vs. Direct Eloquent/Controller Logic

- **Decision**: Split core logic into dedicated services (`DownloadFileService`, `CompressFileService`, `StoreCompressedFileService`, `ProcessBatchService`) and bound them to interfaces (`CreateBatchServiceInterface`, `ProcessBatchServiceInterface`).
- **Rationale**:
  - **Single Responsibility**: Controllers only route requests, form requests handle validation, and services execute business actions.
  - **Testability**: Decoupling services enables mock injection, allowing unit tests to stub out network calls or file operations easily.
- **Trade-off**: Slightly increases file counts and boilerplate registrations inside `AppServiceProvider`.

---

## 4. Eloquent ActiveRecord vs. Data Mapper / Repository Pattern

- **Decision**: Used Laravel Eloquent models directly for database operations instead of wrapping them in a Repository pattern.
- **Rationale**:
  - **Boilerplate Reduction**: Repositories wrapping Eloquent models often end up repeating simple query methods (`find`, `create`, `update`, `delete`), creating a redundant layer of abstraction.
  - **Active Record Power**: Eloquent is highly expressive and handles relations, cascading deletes, transactions, and eager loading natively.
- **Trade-off**: Direct model interaction binds the service layer tightly to the Eloquent ORM.

---

## 5. Cache Locks vs. Database Row Locking (`selectForUpdate`)

- **Decision**: Used Laravel's atomic cache locks (`Cache::lock`) to prevent concurrent workers from processing the same batch.
- **Rationale**:
  - **Deadlock Mitigation**: Cache-based locks fail fast (returning false if the lock is held), preventing workers from blocking database connection pools.
  - **TTL Safety**: Automatic timeout releases locks if a queue worker container crashes mid-task, avoiding permanent deadlocks.
- **Trade-off**: Relies on cache database consistency (if using an ephemeral cache driver like `array` or `file`, locks are less robust than in Redis).

---

## 6. Omission of Automatic Retry Policies

- **Decision**: Intentionally omitted automatic HTTP download retries or queue job retries in this version.
- **Rationale**:
  - **Immediate Failure Feedback**: Spec validation errors (e.g. invalid size, bad MIME) are permanent, so retrying them is wasteful.
  - **State Clarity**: Forcing immediate transitions to `Failed` gives developers and clients clear logging of execution errors without waiting for worker retry timeouts.
- **Trade-off**: Temporary network blips will immediately fail a file rather than recovering automatically.

---

## 7. Sequential vs. Concurrent File Downloads within a Batch

- **Decision**: Background queue jobs process files sequentially in a loop inside `ProcessBatchService`, rather than spawning concurrent async child processes (via fibers or reactPHP).
- **Rationale**:
  - **Deterministic Progress**: Sequential loops guarantee that progress percentages update predictably (`25% -> 50% -> 75% -> 100%`).
  - **Resource Control**: Spawning concurrent downloads can saturate network bandwidth and memory, leading to worker exhaustion.
- **Trade-off**: Total processing time for a batch is the sum of download/compression times for all its files.
