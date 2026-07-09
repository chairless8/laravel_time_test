<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use Illuminate\Support\Facades\Storage;

class BatchFileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'original_url' => $this->original_url,
            'status' => $this->status->value,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'file' => $this->file ? [
                'original_filename' => $this->file->original_filename,
                'compressed_filename' => $this->file->compressed_filename,
                'mime_type' => $this->file->mime_type,
                'original_size' => $this->file->original_size,
                'compressed_size' => $this->file->compressed_size,
                'checksum' => $this->file->checksum,
                'download_url' => $this->file->storage_path ? Storage::url($this->file->storage_path) : null,
            ] : null,
        ];
    }
}
