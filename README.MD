# Batch File Compression Service

A resilient, asynchronous backend service built with Laravel 12 that receives a batch of file URLs, downloads and validates them, compresses them into ZIP archives, stores them locally, and allows clients to query the batch processing progress.

---

## Tech Stack

- **PHP 8.3**
- **Laravel 12**
- **MySQL 8.0**
- **Laravel Queue** (database driver)
- **Docker & Docker Compose**

---

## Documentation Index

Detailed design, trade-offs, and operations documentation are located in the `/docs` directory:

1. [Project Overview](file:///Volumes/Macintosh%20HD%20-%20Data/2026/github/laravel_time_test/docs/PROJECT_OVERVIEW.md) — Objectives, scope, assumptions, and out-of-scope items.
2. [Architecture Guide](file:///Volumes/Macintosh%20HD%20-%20Data/2026/github/laravel_time_test/docs/ARCHITECTURE.md) — Service-Oriented Architecture design, queue orchestration flow, entity models, lifecycle transitions, and concurrency locking strategies.
3. [API Documentation](file:///Volumes/Macintosh%20HD%20-%20Data/2026/github/laravel_time_test/docs/API.md) — Complete endpoint documentation with request/response schemas.
4. [Trade-offs & Decisions](file:///Volumes/Macintosh%20HD%20-%20Data/2026/github/laravel_time_test/docs/TRADE_OFFS.md) — The rationale behind critical design choices.
5. [Development Roadmap Summary](file:///Volumes/Macintosh%20HD%20-%20Data/2026/github/laravel_time_test/docs/ROAD_MAP.md) — Chronological log of phases and implementation milestones.
6. [Future Improvements](file:///Volumes/Macintosh%20HD%20-%20Data/2026/github/laravel_time_test/docs/FUTURE_IMPROVEMENTS.md) — Production scalability recommendations.

---

## Running the Project Locally

### 1. Build and Start Docker Containers
Spin up the MySQL database, Nginx web server, php-fpm app server, and background queue worker:
```bash
docker compose up -d --build
```

### 2. Prepare Environment & Databases
Run migrations to set up database schemas:
```bash
docker compose exec compression-app php artisan migrate
```

### 3. Setup Storage Link
Ensure local storage is symlinked to the public directory:
```bash
docker compose exec compression-app php artisan storage:link
```

The REST API will be available at:
`http://localhost:8000/api/batches`

---

## Running Automated Tests

Run the full PHPUnit test suite inside the application container:
```bash
docker compose exec compression-app php artisan test
```

---

## Key Architectural Highlights

- **Service-Oriented Design**: Business logic is separated into specific single-responsibility services (`DownloadFileService`, `CompressFileService`, `StoreCompressedFileService`) managed by a thin orchestrator job.
- **Cache-Based Concurrency Lock**: Active batches are locked in Cache to prevent race conditions when running multiple parallel queue workers.
- **Idempotent Retries**: Rerunning a job will not download or zip files that already reached a completed status.
- **Isolation of Failures**: Individual file failures (timeouts, MIME validation errors, download errors) are isolated, recording distinct details on the corresponding `BatchFile` database record while allowing other files in the batch to proceed.