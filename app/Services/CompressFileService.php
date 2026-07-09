<?php

namespace App\Services;

class CompressFileService
{
    /**
     * Compress a file to a temporary ZIP archive.
     *
     * @param string $filePath Path to the local file
     * @param string $filename Name the file should have inside the ZIP
     * @return string Path to the temporary ZIP archive
     * @throws \Exception
     */
    public function compress(string $filePath, string $filename): string
    {
        $zip = new \ZipArchive();
        $zipPath = tempnam(sys_get_temp_dir(), 'zip_');

        if ($zipPath === false) {
            throw new \Exception("Could not create temporary zip file path.");
        }

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Could not open ZipArchive to write.");
        }

        if (!$zip->addFile($filePath, $filename)) {
            $zip->close();
            @unlink($zipPath);
            throw new \Exception("Could not add file '{$filename}' to ZipArchive.");
        }

        $zip->close();

        return $zipPath;
    }
}
