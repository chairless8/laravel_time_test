<?php

namespace App\Enums;

enum FileStatus: string
{
    case Pending = 'pending';
    case Downloading = 'downloading';
    case Compressing = 'compressing';
    case Completed = 'completed';
    case Failed = 'failed';
}
