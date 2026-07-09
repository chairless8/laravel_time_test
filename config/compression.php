<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Max File Size Limit
    |--------------------------------------------------------------------------
    |
    | The maximum allowable size for a single downloaded file in bytes.
    | Default is 20 MB (20 * 1024 * 1024).
    |
    */
    'max_file_size' => (int) env('COMPRESSION_MAX_FILE_SIZE', 20971520),

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    |
    | The mime types supported by the application. Downloads of files
    | with other mime types will be rejected.
    |
    */
    'allowed_mime_types' => explode(',', env('COMPRESSION_ALLOWED_MIME_TYPES', 'text/plain,text/csv,application/pdf')),

    /*
    |--------------------------------------------------------------------------
    | HTTP Download Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for downloading remote files via HTTP client.
    |
    */
    'timeout' => (int) env('COMPRESSION_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Batch Processing Lock TTL
    |--------------------------------------------------------------------------
    |
    | Time to live in seconds for the batch processing cache lock.
    |
    */
    'lock_ttl' => (int) env('COMPRESSION_LOCK_TTL', 300),
];
