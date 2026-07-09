# Batch File Compression Service

A backend service that receives a batch of file URLs, processes them asynchronously, compresses the downloaded files, and allows clients to query the processing status of each batch.

This project was developed as part of a backend engineering assignment focused on demonstrating software architecture, system design, asynchronous processing, and engineering decision-making rather than building a production-ready platform.

---

## Tech Stack

- PHP 8.x
- Laravel
- MySQL
- Laravel Queue (Database Driver)
- Docker & Docker Compose

---

## Documentation

Project documentation can be found in the `/docs` directory.

- **PROJECT_OVERVIEW.md**
  Project goals, scope, assumptions and implementation strategy.

- **ARCHITECTURE.md**
  System architecture, data model, asynchronous processing, concurrency strategy and design decisions.

- **ROADMAP.md**
  Development roadmap and implementation phases.

---

## Running the Project

```bash
docker compose up --build
```

After the containers are running:

```bash
php artisan migrate
php artisan storage:link
php artisan queue:work
```

The API will be available at:

```
http://localhost:8000
```

---

## Main Features

- Submit a compression batch.
- Background file processing.
- Batch status tracking.
- Per-file status reporting.
- Download compressed files.
- Progress tracking.
- Error reporting.

---

## License

This project was created exclusively for technical evaluation purposes.