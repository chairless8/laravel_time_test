<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DownloadFileService
{
    /**
     * Download a remote file to a temporary local path.
     *
     * @param string $url
     * @return string Temporary file path
     * @throws \Exception
     */
    public function download(string $url): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'download_');

        if ($tempPath === false) {
            throw new \Exception("Could not create temporary download file.");
        }

        try {
            $response = Http::timeout(config('compression.timeout'))
                ->sink($tempPath)
                ->get($url);

            if (!$response->successful()) {
                @unlink($tempPath);
                throw new \Exception("Failed to download file from URL: {$url}. Status code: " . $response->status());
            }

            return $tempPath;
        } catch (\Throwable $e) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw new \Exception("HTTP download error: " . $e->getMessage(), 0, $e);
        }
    }
}
