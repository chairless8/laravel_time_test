<?php

namespace App\Services;

use App\Models\BatchFile;
use App\Models\File;
use Illuminate\Support\Facades\Storage;

class StoreCompressedFileService
{
    /**
     * Store the compressed file in Laravel Storage and persist File metadata.
     *
     * @param string $zipPath Local path to the temporary ZIP archive
     * @param string $originalFilename Original name of the file
     * @param string $mimeType Original MIME type
     * @param int $originalSize Original size of the file in bytes
     * @param string $checksum Original file checksum
     * @param BatchFile $batchFile The associated batch file model
     * @return File Created File metadata model
     * @throws \Exception
     */
    public function store(
        string $zipPath,
        string $originalFilename,
        string $mimeType,
        int $originalSize,
        string $checksum,
        BatchFile $batchFile
    ): File {
        $compressedFilename = pathinfo($originalFilename, PATHINFO_FILENAME) . '_' . uniqid() . '.zip';
        $storagePath = 'compressions/' . $compressedFilename;

        $stream = fopen($zipPath, 'r');
        if ($stream === false) {
            throw new \Exception("Could not open ZIP file stream for storage.");
        }

        try {
            $stored = Storage::put($storagePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (!$stored) {
                throw new \Exception("Storage disk put operation failed.");
            }

            $compressedSize = @filesize($zipPath);
            if ($compressedSize === false) {
                $compressedSize = 0;
            }

            $fileModel = File::create([
                'original_filename' => $originalFilename,
                'compressed_filename' => $compressedFilename,
                'mime_type' => $mimeType,
                'original_size' => $originalSize,
                'compressed_size' => $compressedSize,
                'storage_path' => $storagePath,
                'checksum' => $checksum,
            ]);

            $batchFile->update([
                'file_id' => $fileModel->id,
            ]);

            return $fileModel;
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            Storage::delete($storagePath);
            throw new \Exception("Storage persistence error: " . $e->getMessage(), 0, $e);
        }
    }
}
