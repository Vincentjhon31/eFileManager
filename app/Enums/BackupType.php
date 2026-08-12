<?php

namespace App\Enums;

enum BackupType: string
{
    case Database = 'database';
    case Files = 'files';

    public function label(): string
    {
        return match ($this) {
            self::Database => 'Database',
            self::Files => 'Files',
        };
    }
}
