<?php

namespace App\Enums;

enum FileStatus: string
{
    case Pending = 'pending';
    case Downloading = 'downloading';
    case Downloaded = 'downloaded';
    case Compressing = 'compressing';
    case Stored = 'stored';
    case Completed = 'completed';
    case Failed = 'failed';
}
