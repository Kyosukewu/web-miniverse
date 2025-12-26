<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncStatus: string
{
    case UPDATED = 'updated';      // 更新（新同步的文件）
    case SYNCED = 'synced';         // 已同步（已同步到 GCS）
    case PARSED = 'parsed';         // 已解析（已解析完成）
}

