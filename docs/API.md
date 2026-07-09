# API Documentation

The Batch File Compression Service exposes a REST API for submitting, listing, and retrieving batch compression operations.

All API endpoints are prefixed with `/api`.

---

## Interactive Postman Execution Guide

For a guided, automated review of the API, use the preconfigured Postman collection:
- **File Path**: [docs/postman/Batch_File_Compression_Service_API.postman_collection.json](file:///Volumes/Macintosh%20HD%20-%20Data/2026/github/laravel_time_test/docs/postman/Batch_File_Compression_Service_API.postman_collection.json)

### Importing the Collection
1. Click **Import** in Postman.
2. Choose the JSON file from the path above.
3. The collection defines two variables:
   - `base_url`: Default value is `http://localhost:8000`. Modify this if your Docker environment runs on a different port.
   - `batch_uuid`: Initially blank. It is automatically filled by the Test script.

### Recommended Execution Flow
1. **Create Batch** (in the `Happy Path` folder): Submits three public stable sample files. On success (HTTP 202 Accepted), the Test script captures `data.uuid` from the response and stores it in the `batch_uuid` collection variable.
2. **List Batches**: Returns a list of all batches (HTTP 200 OK) to confirm the new batch has been queued.
3. **Get Batch by UUID**: Retrieves processing details using `{{batch_uuid}}` (HTTP 200 OK). Repeated requests track transition progress. Once completed successfully, the response exposes a nested `file.download_url` attribute (e.g. `http://localhost:8000/storage/compressions/...`).

### File Storage Access
Compressed files are stored using Laravel's public filesystem (default disk `FILESYSTEM_DISK=public` is configured in the environment). This writes ZIP archives to `storage/app/public/compressions/`. Reviewers can download them directly through HTTP at `/storage/compressions/{file}.zip` using the symbolic link setup (`php artisan storage:link`).

---

## Endpoints Summary

| Method | Route | Description |
| :--- | :--- | :--- |
| **POST** | `/api/batches` | Create a new file compression batch and queue it for processing. |
| **GET** | `/api/batches` | Retrieve a paginated list of all compression batches. |
| **GET** | `/api/batches/{uuid}` | Retrieve detailed status and progress of a specific batch by UUID. |

---

## Endpoints Detail

### 1. Create Batch

Creates a new compression batch and queues background processing for the provided URLs.

- **URL**: `/api/batches`
- **Method**: `POST`
- **Headers**:
  - `Content-Type: application/json`
  - `Accept: application/json`

#### Request Payload
| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `urls` | `array` | Yes | List of 1 to 5 file URLs. URLs must use the HTTPS scheme. |

#### Example Request Body
```json
{
  "urls": [
    "https://raw.githubusercontent.com/laravel/laravel/11.x/README.md",
    "https://example.com/sample-document.pdf"
  ]
}
```

#### Responses

##### HTTP 202 Accepted (Success)
The batch was validated and queued for background processing.
```json
{
  "data": {
    "uuid": "e454b5df-1e80-4df2-ac24-ca84742cb5f6",
    "status": "pending",
    "progress": 0,
    "files": [
      {
        "original_url": "https://raw.githubusercontent.com/laravel/laravel/11.x/README.md",
        "status": "pending",
        "error_message": null,
        "started_at": null,
        "finished_at": null,
        "file": null
      },
      {
        "original_url": "https://example.com/sample-document.pdf",
        "status": "pending",
        "error_message": null,
        "started_at": null,
        "finished_at": null,
        "file": null
      }
    ],
    "created_at": "2026-07-09T18:00:00Z",
    "updated_at": "2026-07-09T18:00:00Z",
    "finished_at": null
  }
}
```

##### HTTP 422 Unprocessable Entity (Validation Error)
Returned when payload constraints are violated (e.g. non-HTTPS, empty, or >5 URLs).
```json
{
  "message": "The urls field must have between 1 and 5 items.",
  "errors": {
    "urls": [
      "The urls field must have between 1 and 5 items."
    ]
  }
}
```

---

### 2. List Batches

Retrieves a paginated list of all batches in the system (sorted newest first).

- **URL**: `/api/batches`
- **Method**: `GET`
- **Headers**:
  - `Accept: application/json`

#### Response (HTTP 200 OK)
```json
{
  "data": [
    {
      "id": "e454b5df-1e80-4df2-ac24-ca84742cb5f6",
      "status": "completed",
      "progress": 100,
      "created_at": "2026-07-09T18:00:00Z",
      "finished_at": "2026-07-09T18:00:05Z"
    }
  ],
  "links": {
    "first": "http://localhost:8000/api/batches?page=1",
    "last": "http://localhost:8000/api/batches?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "http://localhost:8000/api/batches",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

---

### 3. Retrieve Batch Details

Gets the current progress, status, and processing logs for a specific batch by UUID.

- **URL**: `/api/batches/{uuid}`
- **Method**: `GET`
- **Headers**:
  - `Accept: application/json`

#### Responses

##### HTTP 200 OK (Success)
```json
{
  "data": {
    "uuid": "e454b5df-1e80-4df2-ac24-ca84742cb5f6",
    "status": "completed",
    "progress": 100,
    "files": [
      {
        "original_url": "https://raw.githubusercontent.com/laravel/laravel/11.x/README.md",
        "status": "completed",
        "error_message": null,
        "started_at": "2026-07-09T18:00:02Z",
        "finished_at": "2026-07-09T18:00:04Z",
        "file": {
          "original_filename": "README.md",
          "compressed_filename": "README_6689dca3f231e.zip",
          "mime_type": "text/plain",
          "original_size": 1480,
          "compressed_size": 750,
          "checksum": "8f86f78f86f78f86f78f86f78f86f78f86f78f86f78f86f78f86f78f86f78f86",
          "download_url": "http://localhost:8000/storage/compressions/README_6689dca3f231e.zip"
        }
      }
    ],
    "created_at": "2026-07-09T18:00:00Z",
    "updated_at": "2026-07-09T18:00:05Z",
    "finished_at": "2026-07-09T18:00:05Z"
  }
}
```

##### HTTP 404 Not Found
Returned when the requested UUID does not exist.
```json
{
  "message": "Record not found."
}
```
