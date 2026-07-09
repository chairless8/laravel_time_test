# Project Overview

## Objective

The goal of this project is to build a REST API capable of receiving a batch of file URLs, processing them asynchronously, compressing each downloaded file, and exposing the processing status through dedicated endpoints.

The assignment focuses on demonstrating software engineering practices rather than implementing every possible feature. Therefore, priority is given to simplicity, maintainability and clear design decisions.

---

# Project Scope

The application supports:

- Creating compression batches.
- Processing between 1 and 5 URLs per batch.
- Asynchronous processing using Laravel Queues.
- Individual status tracking for every file.
- Batch progress tracking.
- Local storage for compressed files.
- Metadata persistence.
- Error reporting.

---

# Design Principles

This implementation follows several principles:

- Keep responsibilities clearly separated.
- Prefer readability over unnecessary complexity.
- Make background jobs idempotent whenever possible.
- Design for future extensibility.
- Deliver a complete and functional solution within the given time constraints.

---

# Assumptions

To keep the project focused, several assumptions were made.

- HTTPS URLs are preferred.
- Files are downloaded into local storage.
- The service is intended for relatively small files.
- Compression is performed locally.
- The system is designed for a single application instance.

These assumptions are documented intentionally and can be revisited for a production implementation.

---

# Out of Scope

The following features are intentionally excluded from this implementation:

- Authentication / Authorization
- Distributed storage (Amazon S3, Azure Blob, etc.)
- CDN integration
- Virus scanning
- File deduplication
- Resume interrupted downloads
- Distributed queue infrastructure
- Multi-region deployments

These decisions were made to keep the implementation aligned with the assignment scope while leaving room for future improvements.

---

# Development Strategy

The project is developed incrementally.

Each implementation phase produces a working version of the application.

The implementation order is:

1. Project setup
2. Data model
3. Request validation
4. REST API
5. Queue integration
6. Background processing
7. File storage
8. Concurrency control
9. Testing
10. Documentation

---

# Success Criteria

The project is considered complete when it satisfies the following goals:

- Requests return immediately after creating a batch.
- Background jobs process each file independently.
- Clients can monitor processing progress.
- Failed files do not prevent successful files from completing.
- The project is fully reproducible using Docker.
- Technical decisions are documented and easy to explain during code review.