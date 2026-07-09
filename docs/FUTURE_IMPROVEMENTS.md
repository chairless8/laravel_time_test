# Future Improvements

This document lists recommended enhancements for transitioning the Batch File Compression Service from a local assignment structure into a production-grade system.

---

## 1. High-Performance Queues (Redis + Horizon)
- **Problem**: The `database` queue driver scales poorly because workers continuously poll the database, increasing resource contention.
- **Improvement**: 
  - Migrate to **Redis** for in-memory, high-throughput job dispatching.
  - Install **Laravel Horizon** to provide real-time queue dashboards, failure monitoring, auto-scaling worker counts, and detailed retry execution metrics.

---

## 2. Distributed Object Storage (Amazon S3)
- **Problem**: Local filesystem storage limits the application to a single server instance, as files stored on a local node cannot be retrieved by web servers on other nodes.
- **Improvement**: 
  - Change the filesystem configuration to use the **Amazon S3** driver (or any S3-compatible service like MinIO/DigitalOcean Spaces).
  - Configure the download endpoint to generate presigned, temporary S3 download URLs for clients, offloading bandwidth consumption from the application servers.

---

## 3. Resilient Network Request Retries
- **Problem**: Temporary remote server blips immediately fail the `BatchFile` download process.
- **Improvement**: 
  - Configure HTTP client retry policies inside the `DownloadFileService` using exponential backoffs:
    ```php
    Http::timeout(30)->retry(3, 100);
    ```
  - Inspect response headers for `Retry-After` limits to prevent spamming rate-limited APIs.

---

## 4. Event-Driven Webhooks & Notifications
- **Problem**: Clients must poll `GET /api/batches/{uuid}` continuously to detect progress and completions.
- **Improvement**:
  - Add a `webhook_url` parameter to the batch creation payload.
  - Listen for the `BatchCompleted` domain event and trigger an outbound HTTPS POST request containing the completed batch payload, notifying the client's webhook endpoint instantly.

---

## 5. File Deduplication (Content Hashing)
- **Problem**: If the same file is submitted multiple times, the service downloads and compresses it repeatedly, wasting database space and bandwidth.
- **Improvement**:
  - Before initiating downloads, verify if the URL or file checksum already exists in the database.
  - If a file match is found, link the existing `File` record to the new `BatchFile` immediately, bypassing download and compression entirely.

---

## 6. Security & Authentication
- **Problem**: The API endpoints are currently public and unprotected.
- **Improvement**:
  - Integrate **Laravel Sanctum** to issue secure API tokens for clients.
  - Implement API rate-limiting middleware (`throttle`) to prevent API abuse and denial-of-service attempts.

---

## 7. Virus Scanning
- **Problem**: Allowing users to upload random remote files to local storage poses security risks (e.g. hosting malware).
- **Improvement**:
  - Integrate a scanner like **ClamAV** into the processing pipeline.
  - Pipe downloaded temporary files through the scanner before zipping or saving them, failing validation instantly if a threat is detected.

---

## 8. Concurrency & Performance Metrics
- **Problem**: It is difficult to track long-term performance bottlenecks (e.g. average download wait times, CPU spikes during zip creation).
- **Improvement**:
  - Track core indicators (average download speeds, compression ratios, lock wait times) and export them to monitoring tools like Prometheus, Grafana, or Datadog.
