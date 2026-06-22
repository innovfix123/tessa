<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\TaskAuthorization;
use App\Models\TaskAttachment;
use App\Models\TaskParticipant;
use App\Models\TessaTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskAttachmentController extends Controller
{
    use TaskAuthorization;
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'text/plain', 'text/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ];

    public function index(TessaTask $task, Request $request): JsonResponse
    {
        $this->authorizeTaskAccess($task, $request->user());

        $attachments = $task->attachments()
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'file_name' => $a->file_name,
                'file_size' => $a->file_size,
                'human_size' => $a->humanSize(),
                'mime_type' => $a->mime_type,
                'is_image' => $a->isImage(),
                'message_id' => $a->message_id,
                'user_name' => $a->user?->name ?? 'Unknown',
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

        return response()->json(['attachments' => $attachments]);
    }

    public function store(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeTaskAccess($task, $user);

        $request->validate([
            'file' => 'required|file|max:10240',
            'message_id' => 'nullable|exists:task_messages,id',
        ]);

        $file = $request->file('file');

        if (! in_array($file->getMimeType(), self::ALLOWED_MIMES)) {
            return response()->json(['error' => 'File type not allowed'], 422);
        }

        $path = $file->store('task-attachments/' . $task->id, 'local');

        $attachment = TaskAttachment::create([
            'task_id' => $task->id,
            'message_id' => $request->input('message_id'),
            'user_id' => $user->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'created_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'attachment' => [
                'id' => $attachment->id,
                'file_name' => $attachment->file_name,
                'file_size' => $attachment->file_size,
                'human_size' => $attachment->humanSize(),
                'mime_type' => $attachment->mime_type,
                'is_image' => $attachment->isImage(),
                'user_name' => $user->name,
                'created_at' => $attachment->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function download(TessaTask $task, TaskAttachment $attachment, Request $request): StreamedResponse|JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->authorizeTaskAccess($task, $request->user());

        if ($attachment->task_id !== $task->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if (! Storage::disk('local')->exists($attachment->file_path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $previewable = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

        if (in_array($attachment->mime_type, $previewable) && ! $request->has('download')) {
            return response()->file(Storage::disk('local')->path($attachment->file_path), [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => 'inline; filename="' . $attachment->file_name . '"',
            ]);
        }

        return Storage::disk('local')->download($attachment->file_path, $attachment->file_name);
    }

    public function destroy(TessaTask $task, TaskAttachment $attachment, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeTaskAccess($task, $user);

        if ($attachment->task_id !== $task->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // Only uploader or task assigner can delete
        if ($attachment->user_id !== $user->id && $task->assigned_by !== $user->id) {
            return response()->json(['error' => 'Not authorized to delete this file'], 403);
        }

        Storage::disk('local')->delete($attachment->file_path);
        $attachment->delete();

        return response()->json(['ok' => true]);
    }

}
