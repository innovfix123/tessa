<?php

namespace App\Http\Controllers\Api\Traits;

use App\Models\TaskParticipant;
use App\Models\TessaTask;

trait TaskAuthorization
{
    /**
     * Authorize access for assigned_to, assigned_by, or any thread participant.
     */
    protected function authorizeTaskAccess(TessaTask $task, $user): void
    {
        if ($task->assigned_to !== $user->id
            && $task->assigned_by !== $user->id
            && ! TaskParticipant::where('task_id', $task->id)->where('user_id', $user->id)->exists()
        ) {
            abort(403, 'Forbidden');
        }
    }

    /**
     * Authorize access for assigned_to or assigned_by only (no participants).
     */
    protected function authorizeTaskOwner(TessaTask $task, $user): void
    {
        if ($task->assigned_to !== $user->id && $task->assigned_by !== $user->id) {
            abort(403, 'Forbidden');
        }
    }

    /**
     * Check if user can access the task (returns bool instead of aborting).
     */
    protected function canAccessTask(TessaTask $task, int $userId): bool
    {
        return $task->assigned_by === $userId
            || $task->assigned_to === $userId
            || TaskParticipant::where('task_id', $task->id)->where('user_id', $userId)->exists();
    }
}
