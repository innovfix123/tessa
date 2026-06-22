<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Log;

class ActivityLogService
{
    public static function log(
        int $userId,
        string $action,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null
    ): void {
        try {
            ActivityLog::create([
                'user_id' => $userId,
                'action' => $action,
                'description' => mb_substr($description, 0, 500),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ActivityLogService::log failed', [
                'user_id' => $userId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
