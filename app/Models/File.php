<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class File extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'original_filename',
        'compressed_filename',
        'mime_type',
        'original_size',
        'compressed_size',
        'storage_path',
        'checksum',
    ];

    /**
     * Get the batch files associated with this file.
     */
    public function batchFiles(): HasMany
    {
        return $this->hasMany(BatchFile::class);
    }
}
