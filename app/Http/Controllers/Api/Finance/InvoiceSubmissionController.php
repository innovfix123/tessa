<?php

namespace App\Http\Controllers\Api\Finance;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Models\BankTransaction;
use App\Models\InvoiceSubmission;
use App\Models\Role;
use App\Services\ActivityLogService;
use App\Services\TessaAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class InvoiceSubmissionController extends Controller
{
    private const SUBMITTER_ROLES = [
        Role::SLUG_CEO,
        Role::SLUG_CFO,
        Role::SLUG_COO,
        Role::SLUG_CMO,
        Role::SLUG_TECH_LEAD,
        Role::SLUG_ACCOUNTANT,
    ];

    private const REVIEWER_ROLES = [
        Role::SLUG_ACCOUNTANT,
        Role::SLUG_CFO,
        Role::SLUG_CEO,
    ];

    // Users granted full reviewer parity despite their role not being a
    // REVIEWER_ROLE. Mirror of $invoiceExtraUserIds in DashboardController —
    // keep in sync. Bhuvan #59 assists Shoyab on invoice reconciliation.
    private const EXTRA_REVIEWER_USER_IDS = [59];

    private function isReviewer($user): bool
    {
        return in_array($user->role, self::REVIEWER_ROLES, true)
            || in_array($user->id, self::EXTRA_REVIEWER_USER_IDS, true);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user->role;

        $isReviewer = $this->isReviewer($user);

        $base = InvoiceSubmission::query();
        if (! $isReviewer) {
            $base->where('user_id', $user->id);
        }

        // Apply per-request filters on top of the base scope.
        $query = (clone $base)->with(['user:id,name']);

        if ($isReviewer && $request->filled('uploaded_by')) {
            $query->where('user_id', (int) $request->input('uploaded_by'));
        } elseif ($isReviewer && $request->filled('user_id')) {
            // Backward compat with the old param name.
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('vendor')) {
            $query->where('vendor_name', $request->input('vendor'));
        }

        // From/To accept both date (YYYY-MM-DD) and datetime-local (YYYY-MM-DDTHH:MM).
        // When time is provided, filter on upload datetime; pure dates filter on invoice_date for backward compat.
        $from = $request->input('from');
        $to = $request->input('to');
        $hasTimeFrom = $from && str_contains($from, 'T');
        $hasTimeTo = $to && str_contains($to, 'T');
        if ($from) {
            if ($hasTimeFrom) {
                $query->where('created_at', '>=', str_replace('T', ' ', $from));
            } else {
                $query->where('invoice_date', '>=', $from);
            }
        }
        if ($to) {
            if ($hasTimeTo) {
                $query->where('created_at', '<=', str_replace('T', ' ', $to));
            } else {
                $query->where('invoice_date', '<=', $to);
            }
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            if ($term !== '') {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('vendor_name', 'like', $like)
                        ->orWhere('service', 'like', $like)
                        ->orWhere('category', 'like', $like)
                        ->orWhere('notes', 'like', $like)
                        ->orWhereHas('user', function ($qu) use ($like) {
                            $qu->where('name', 'like', $like);
                        });
                });
            }
        }

        // Sorting — whitelist columns to avoid injection / unexpected sorts.
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['created_at', 'invoice_date', 'amount', 'vendor_name'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }
        $items = $query->orderBy($sortBy, $sortDir)->orderByDesc('id')->limit(500)->get();

        $submissions = $items->map(function ($s) {
            return [
                'id' => $s->id,
                'userId' => $s->user_id,
                'userName' => $s->user?->name ?? '',
                'vendorName' => $s->vendor_name,
                'service' => $s->service,
                'amount' => $s->amount,
                'currency' => $s->currency ?: 'INR',
                'invoiceDate' => $s->invoice_date->format('Y-m-d'),
                'fileName' => $s->file_name,
                'filePath' => $s->file_path ? asset('storage/' . $s->file_path) : null,
                'invoiceNumber' => ($s->category && $s->category !== 'general') ? $s->category : null,
                'notes' => $s->notes,
                'createdAt' => $s->created_at->toISOString(),
            ];
        });

        // Distinct option lists for the filter dropdowns. Computed off the base scope (not the filtered
        // query) so the dropdowns always reflect the full universe of values the user can see.
        $vendors = (clone $base)->whereNotNull('vendor_name')->where('vendor_name', '!=', '')
            ->distinct()->orderBy('vendor_name')->limit(500)->pluck('vendor_name')->values();

        $uploaders = collect();
        if ($isReviewer) {
            $uploaders = (clone $base)->with('user:id,name')
                ->select('user_id')->distinct()->get()
                ->map(fn ($r) => ['id' => $r->user_id, 'name' => $r->user?->name ?? ''])
                ->filter(fn ($r) => $r['name'] !== '')
                ->sortBy('name')
                ->values();
        }

        return response()->json([
            'ok' => true,
            'submissions' => $submissions,
            'isReviewer' => $isReviewer,
            'canDownloadAll' => $isReviewer,
            'vendors' => $vendors,
            'uploaders' => $uploaders,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user->role;
        $action = $request->input('action', 'submit');

        if ($action === 'submit') {
            return $this->handleSubmit($request, $user, $role);
        }

        if ($action === 'delete') {
            return $this->handleDelete($request, $user, $role);
        }

        if ($action === 'review') {
            return $this->handleReview($request, $user, $role);
        }

        if ($action === 'upload_statement') {
            return $this->handleUploadStatement($request, $user, $role);
        }

        if ($action === 'run_matching') {
            return $this->handleRunMatching($request, $user, $role);
        }

        if ($action === 'manual_match') {
            return $this->handleManualMatch($request, $user, $role);
        }

        if ($action === 'search_transactions') {
            return $this->handleSearchTransactions($request, $user, $role);
        }

        return response()->json(['error' => 'Unknown action'], 400);
    }

    /**
     * Download all invoices as a ZIP (reviewers only — JP, Ayush, Shoyab).
     */
    public function downloadAll(Request $request)
    {
        $user = $request->user();
        if (! $this->isReviewer($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $query = InvoiceSubmission::whereNotNull('file_path');

        // Support downloading specific IDs (select & download)
        if ($request->filled('ids')) {
            $ids = array_map('intval', explode(',', $request->query('ids')));
            $query->whereIn('id', $ids);
        } else {
            // Optional date range — only constrain when explicitly provided, otherwise
            // return all invoices that have a file (no current-month default, which would
            // wrongly come back empty on the 1st of the month).
            if ($request->filled('from')) {
                $query->where('invoice_date', '>=', $request->query('from'));
            }
            if ($request->filled('to')) {
                $query->where('invoice_date', '<=', $request->query('to'));
            }
        }

        $submissions = $query->orderBy('invoice_date')->get();

        if ($submissions->isEmpty()) {
            return response()->json(['error' => 'No invoices found'], 404);
        }

        $zipName = 'Invoices-' . DateHelper::now()->format('Y-m-d') . '.zip';
        $zipPath = storage_path('app/temp/' . $zipName);

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'Failed to create zip'], 500);
        }

        $usedNames = [];
        foreach ($submissions as $s) {
            $fullPath = Storage::disk('public')->path($s->file_path);
            if (! file_exists($fullPath)) {
                continue;
            }
            $entryName = $s->file_name ?: basename($s->file_path);
            // Avoid silently overwriting entries that share the same file name.
            if (isset($usedNames[$entryName])) {
                $usedNames[$entryName]++;
                $suffix = ' (' . $usedNames[$entryName] . ')';
                $dot = strrpos($entryName, '.');
                $entryName = $dot === false
                    ? $entryName . $suffix
                    : substr($entryName, 0, $dot) . $suffix . substr($entryName, $dot);
            } else {
                $usedNames[$entryName] = 1;
            }
            $zip->addFile($fullPath, $entryName);
        }

        $zip->close();

        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
    }

    /**
     * Get reconciliation dashboard data (bank transactions + match status).
     */
    public function reconciliation(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user->role;

        if (! $this->isReviewer($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $month = $request->input('month', DateHelper::now()->format('Y-m'));

        // Bank transactions for the month
        $transactions = BankTransaction::where('statement_month', $month)
            ->with('invoiceSubmission:id,vendor_name,amount,invoice_date,user_id,matched_transaction_id')
            ->orderBy('transaction_date')
            ->get()
            ->map(fn ($tx) => [
                'id' => $tx->id,
                'date' => $tx->transaction_date->format('Y-m-d'),
                'description' => $tx->description,
                'reference' => $tx->reference_number,
                'amount' => $tx->amount,
                'type' => $tx->type,
                'balance' => $tx->balance,
                'matchStatus' => $tx->match_status,
                'matchedInvoice' => $tx->invoiceSubmission ? [
                    'id' => $tx->invoiceSubmission->id,
                    'vendorName' => $tx->invoiceSubmission->vendor_name,
                    'amount' => $tx->invoiceSubmission->amount,
                    'userId' => $tx->invoiceSubmission->user_id,
                ] : null,
            ]);

        // Unmatched invoices (no bank transaction linked)
        $unmatchedInvoices = InvoiceSubmission::whereNull('matched_transaction_id')
            ->whereMonth('invoice_date', '=', substr($month, 5, 2))
            ->whereYear('invoice_date', '=', substr($month, 0, 4))
            ->with('user:id,name')
            ->get()
            ->map(fn ($inv) => [
                'id' => $inv->id,
                'vendorName' => $inv->vendor_name,
                'amount' => $inv->amount,
                'invoiceDate' => $inv->invoice_date->format('Y-m-d'),
                'userName' => $inv->user?->name ?? '',
                'verificationStatus' => $inv->verification_status,
            ]);

        $matched = $transactions->where('matchStatus', 'matched')->count();
        $unmatched = $transactions->where('matchStatus', 'unmatched')->count();

        return response()->json([
            'ok' => true,
            'month' => $month,
            'transactions' => $transactions,
            'unmatchedInvoices' => $unmatchedInvoices,
            'stats' => [
                'totalTransactions' => $transactions->count(),
                'matched' => $matched,
                'unmatchedTransactions' => $unmatched,
                'unmatchedInvoices' => $unmatchedInvoices->count(),
            ],
        ]);
    }

    private const INVOICE_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    private const ZIP_MAX_FILES = 50;
    private const ZIP_MAX_BYTES = 50 * 1024 * 1024; // 50 MB total uncompressed

    private function handleSubmit(Request $request, $user, string $role): JsonResponse
    {
        // Accept three shapes (in order of preference):
        //   1. files[]: array of files (multi-upload)
        //   2. file: single file — either an invoice OR a .zip bundle of invoices
        //   3. legacy single 'file' (back-compat)
        $uploaded = [];
        if ($request->hasFile('files')) {
            $uploaded = (array) $request->file('files');
        } elseif ($request->hasFile('file')) {
            $uploaded = [$request->file('file')];
        }
        if (empty($uploaded)) {
            return response()->json(['error' => 'No file provided'], 422);
        }

        $results = [];
        $errors = [];

        foreach ($uploaded as $file) {
            if (! $file || ! $file->isValid()) {
                $errors[] = ['name' => $file ? $file->getClientOriginalName() : '?', 'error' => 'Upload failed'];
                continue;
            }
            $ext = strtolower($file->getClientOriginalExtension());
            $name = $file->getClientOriginalName();

            if ($ext === 'zip') {
                $this->processZipUpload($file, $user, $results, $errors);
                continue;
            }

            if (! in_array($ext, self::INVOICE_EXTS, true)) {
                $errors[] = ['name' => $name, 'error' => 'Unsupported file type'];
                continue;
            }
            if ($file->getSize() > 10 * 1024 * 1024) {
                $errors[] = ['name' => $name, 'error' => 'File exceeds 10MB'];
                continue;
            }

            try {
                $stored = $file->store('invoices', 'public');
                $abs = Storage::disk('public')->path($stored);
                $result = $this->processInvoiceFile($abs, $name, $ext, $user, $stored);
                $results[] = $result;
            } catch (\Throwable $e) {
                Log::warning('Invoice processing failed', ['name' => $name, 'error' => $e->getMessage()]);
                $errors[] = ['name' => $name, 'error' => 'Processing failed'];
            }
        }

        // Single-file legacy back-compat: when exactly one file uploaded (and not via files[]),
        // return the original flat shape so older callers keep working.
        if (count($results) === 1 && empty($errors) && $request->hasFile('file') && ! $request->hasFile('files')) {
            return response()->json(array_merge(['ok' => true], $results[0]));
        }

        return response()->json([
            'ok' => true,
            'created' => count($results),
            'failed' => count($errors),
            'results' => $results,
            'errors' => $errors,
        ]);
    }

    private function processZipUpload($file, $user, array &$results, array &$errors): void
    {
        $zipName = $file->getClientOriginalName();
        if (! class_exists(\ZipArchive::class)) {
            $errors[] = ['name' => $zipName, 'error' => 'ZIP support unavailable on server'];
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'invzip_');
        $file->move(dirname($tmp), basename($tmp));

        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            $errors[] = ['name' => $zipName, 'error' => 'Could not open ZIP'];
            return;
        }

        $extractDir = sys_get_temp_dir() . '/' . 'invzip_x_' . bin2hex(random_bytes(6));
        mkdir($extractDir, 0700, true);

        $totalBytes = 0;
        $extractedCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if (! $entry) continue;
            $entryName = $entry['name'];
            // Skip directories, hidden files, and __MACOSX cruft.
            if (str_ends_with($entryName, '/') || str_starts_with(basename($entryName), '.') || str_contains($entryName, '__MACOSX')) {
                continue;
            }
            $entryExt = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
            if (! in_array($entryExt, self::INVOICE_EXTS, true)) {
                continue;
            }
            if ($entry['size'] > 10 * 1024 * 1024) {
                $errors[] = ['name' => basename($entryName), 'error' => 'File exceeds 10MB inside ZIP'];
                continue;
            }
            $totalBytes += $entry['size'];
            if ($totalBytes > self::ZIP_MAX_BYTES) {
                $errors[] = ['name' => basename($entryName), 'error' => 'ZIP exceeds 50MB total'];
                break;
            }
            if ($extractedCount >= self::ZIP_MAX_FILES) {
                $errors[] = ['name' => basename($entryName), 'error' => 'ZIP exceeds 50 files limit'];
                break;
            }

            $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($entryName));
            $extractPath = $extractDir . '/' . $safeBase;
            $stream = $zip->getStream($entryName);
            if (! $stream) continue;
            $out = fopen($extractPath, 'wb');
            if (! $out) { fclose($stream); continue; }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            try {
                $diskPath = 'invoices/' . uniqid('inv_', true) . '.' . $entryExt;
                Storage::disk('public')->put($diskPath, file_get_contents($extractPath));
                $abs = Storage::disk('public')->path($diskPath);
                $result = $this->processInvoiceFile($abs, basename($entryName), $entryExt, $user, $diskPath);
                $results[] = $result;
                $extractedCount++;
            } catch (\Throwable $e) {
                Log::warning('Invoice (from ZIP) processing failed', ['name' => $entryName, 'error' => $e->getMessage()]);
                $errors[] = ['name' => basename($entryName), 'error' => 'Processing failed'];
            }
            @unlink($extractPath);
        }

        $zip->close();
        @unlink($tmp);
        @rmdir($extractDir);
    }

    private function processInvoiceFile(string $fullPath, string $originalName, string $ext, $user, string $storedRelPath): array
    {
        $vendorName = pathinfo($originalName, PATHINFO_FILENAME);
        $service = null;
        $amount = 0;
        $currency = 'INR';
        $invoiceDate = DateHelper::now()->format('Y-m-d');
        $invoiceNumber = '';

        try {
            $ai = new TessaAIService();
            if ($ext === 'pdf') {
                $output = [];
                exec('pdftotext ' . escapeshellarg($fullPath) . ' - 2>/dev/null', $output, $exitCode);
                $textContent = ($exitCode === 0 && ! empty($output)) ? implode("\n", $output) : file_get_contents($fullPath);
                $extracted = $ai->extractInvoiceDetails($textContent);
            } else {
                $imageData = base64_encode(file_get_contents($fullPath));
                $mimeType = match ($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };
                $extracted = $ai->extractInvoiceDetailsFromImage($imageData, $mimeType);
            }

            if (! empty($extracted['vendor'])) $vendorName = $extracted['vendor'];
            if (! empty($extracted['service'])) $service = $extracted['service'];
            if (! empty($extracted['amount'])) $amount = (float) $extracted['amount'];
            if (! empty($extracted['date'])) $invoiceDate = $extracted['date'];
            if (! empty($extracted['currency'])) $currency = $extracted['currency'];
            if (! empty($extracted['invoice_number'])) $invoiceNumber = $extracted['invoice_number'];
        } catch (\Throwable $e) {
            Log::warning('Invoice AI extraction failed, using defaults', ['error' => $e->getMessage()]);
        }

        $submission = InvoiceSubmission::create([
            'user_id' => $user->id,
            'vendor_name' => $vendorName,
            'service' => $service,
            'amount' => $amount > 0 ? $amount : 0,
            'currency' => strtoupper($currency) ?: 'INR',
            'invoice_date' => $invoiceDate,
            'category' => $invoiceNumber ?: 'general',
            'file_path' => $storedRelPath,
            'file_name' => $originalName,
        ]);

        // Rename file to: VendorName-CurrencyAmount-Date-InvoiceNumber.ext
        $safeVendor = preg_replace('/[^a-zA-Z0-9]/', '_', $vendorName);
        $safeVendor = substr(trim($safeVendor, '_'), 0, 50);
        $currSymbol = match (strtoupper($currency)) {
            'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', default => 'INR',
        };
        $safeAmount = $currSymbol . str_replace('.', '_', (string) ($amount > 0 ? $amount : 0));
        $safeDate = str_replace('-', '', $invoiceDate);
        $safeInvNo = $invoiceNumber ? preg_replace('/[^a-zA-Z0-9\-]/', '_', $invoiceNumber) : 'INV' . $submission->id;
        $newFileName = "{$safeVendor}-{$safeAmount}-{$safeDate}-{$safeInvNo}.{$ext}";
        $newPath = 'invoices/' . $newFileName;

        if (Storage::disk('public')->exists($storedRelPath)) {
            Storage::disk('public')->move($storedRelPath, $newPath);
            $submission->update(['file_path' => $newPath, 'file_name' => $newFileName]);
        }

        ActivityLogService::log(
            $user->id,
            'invoice_submitted',
            "{$user->name} submitted invoice: {$vendorName} ₹{$amount}",
            'invoice_submission',
            $submission->id
        );

        return [
            'id' => $submission->id,
            'vendorName' => $vendorName,
            'service' => $service,
            'amount' => $amount,
            'currency' => strtoupper($currency) ?: 'INR',
            'invoiceDate' => $invoiceDate,
            'invoiceNumber' => $invoiceNumber ?: null,
            'fileName' => $newFileName,
        ];
    }

    private function handleDelete(Request $request, $user, string $role): JsonResponse
    {
        $ids = $request->input('ids', []);
        if (! is_array($ids) || empty($ids)) {
            return response()->json(['error' => 'ids array is required'], 422);
        }

        $query = InvoiceSubmission::whereIn('id', $ids);

        // Non-reviewers can only delete their own
        if (! $this->isReviewer($user)) {
            $query->where('user_id', $user->id);
        }

        $submissions = $query->get();
        $deleted = 0;

        foreach ($submissions as $s) {
            if ($s->file_path) {
                Storage::disk('public')->delete($s->file_path);
            }
            $s->delete();
            $deleted++;
        }

        ActivityLogService::log(
            $user->id,
            'invoice_deleted',
            "{$user->name} deleted {$deleted} invoice(s)",
        );

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    private function handleReview(Request $request, $user, string $role): JsonResponse
    {
        if (! $this->isReviewer($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'id' => 'required|integer|exists:invoice_submissions,id',
            'status' => 'required|string|in:reviewed,approved,rejected',
            'review_notes' => 'nullable|string|max:1000',
        ]);

        $submission = InvoiceSubmission::findOrFail($request->input('id'));
        $submission->update([
            'status' => $request->input('status'),
            'reviewed_by' => $user->id,
            'review_notes' => $request->input('review_notes'),
            'reviewed_at' => now(),
        ]);

        ActivityLogService::log(
            $user->id,
            'invoice_' . $request->input('status'),
            $user->name . ' ' . $request->input('status') . ' invoice #' . $submission->id . ' from ' . $submission->vendor_name,
            'invoice_submission',
            $submission->id
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Upload and parse a bank statement using AI.
     */
    private function handleUploadStatement(Request $request, $user, string $role): JsonResponse
    {
        if (! $this->isReviewer($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'file' => 'required|file|max:20480|mimes:pdf,csv,txt,xls,xlsx',
            'bank_name' => 'nullable|string|max:100',
            'month' => 'nullable|string|max:7',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        Log::info('Bank statement upload started', [
            'user' => $user->name,
            'originalName' => $file->getClientOriginalName(),
            'extension' => $ext,
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        $filePath = $file->store('bank_statements', 'public');

        if (! $filePath) {
            Log::error('Bank statement file store failed', [
                'originalName' => $file->getClientOriginalName(),
                'extension' => $ext,
                'mimeType' => $file->getMimeType(),
            ]);
            return response()->json(['error' => 'Failed to save uploaded file.'], 500);
        }

        $fullPath = Storage::disk('public')->path($filePath);

        Log::info('Bank statement file stored', [
            'filePath' => $filePath,
            'fullPath' => $fullPath,
            'exists' => file_exists($fullPath),
        ]);

        // Parse the file
        $parsed = [];

        try {
            if ($ext === 'xls' || $ext === 'xlsx') {
                // Direct spreadsheet parsing — no AI needed, instant
                Log::info('Parsing XLS/XLSX directly', ['fullPath' => $fullPath]);
                $parsed = $this->parseSpreadsheetDirectly($fullPath);
                Log::info('XLS/XLSX direct parse complete', ['transactions' => count($parsed)]);
            } else {
                // For PDF/CSV/TXT, extract text and use AI
                $textContent = '';

                if ($ext === 'csv' || $ext === 'txt') {
                    $textContent = file_get_contents($fullPath);
                } elseif ($ext === 'pdf') {
                    $output = [];
                    $exitCode = 0;
                    exec('pdftotext ' . escapeshellarg($fullPath) . ' - 2>/dev/null', $output, $exitCode);
                    if ($exitCode === 0 && ! empty($output)) {
                        $textContent = implode("\n", $output);
                    } else {
                        $textContent = file_get_contents($fullPath);
                    }
                }

                if (trim($textContent) === '') {
                    return response()->json(['error' => 'Could not extract text from the file. Please try XLS, CSV, or TXT format.'], 422);
                }

                $ai = new TessaAIService();
                $parsed = $ai->parseBankStatement($textContent);
            }
        } catch (\Throwable $e) {
            Log::error('Bank statement parsing failed', [
                'extension' => $ext,
                'fullPath' => $fullPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to parse file: ' . $e->getMessage()], 500);
        }

        if (empty($parsed)) {
            return response()->json(['error' => 'Could not parse any transactions from this file. Please check the format.'], 422);
        }

        $statementMonth = $request->input('month', DateHelper::now()->format('Y-m'));
        $bankName = $request->input('bank_name', 'Unknown');
        $inserted = 0;

        foreach ($parsed as $tx) {
            if (empty($tx['date']) || ! isset($tx['amount'])) {
                continue;
            }

            BankTransaction::create([
                'uploaded_by' => $user->id,
                'transaction_date' => $tx['date'],
                'description' => $tx['description'] ?? '',
                'reference_number' => $tx['reference'] ?? null,
                'amount' => abs((float) $tx['amount']),
                'type' => ($tx['type'] ?? 'debit') === 'credit' ? 'credit' : 'debit',
                'balance' => isset($tx['balance']) ? (float) $tx['balance'] : null,
                'bank_name' => $bankName,
                'statement_month' => $statementMonth,
                'source_file' => $filePath,
            ]);
            $inserted++;
        }

        ActivityLogService::log(
            $user->id,
            'bank_statement_uploaded',
            $user->name . ' uploaded bank statement (' . $bankName . ', ' . $statementMonth . ') with ' . $inserted . ' transactions',
            'bank_transaction',
            0,
            ['file' => $file->getClientOriginalName(), 'count' => $inserted]
        );

        Log::info('Bank statement parsed', [
            'user' => $user->name,
            'bank' => $bankName,
            'month' => $statementMonth,
            'parsed' => count($parsed),
            'inserted' => $inserted,
        ]);

        return response()->json([
            'ok' => true,
            'transactionsCount' => $inserted,
            'message' => "Parsed {$inserted} transactions from statement.",
        ]);
    }

    /**
     * Run AI matching: try to match all unmatched invoices against unmatched transactions.
     */
    private function handleRunMatching(Request $request, $user, string $role): JsonResponse
    {
        if (! $this->isReviewer($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $unmatchedInvoices = InvoiceSubmission::whereNull('matched_transaction_id')
            ->where('verification_status', 'pending')
            ->get();

        $matched = 0;
        foreach ($unmatchedInvoices as $invoice) {
            if ($this->tryAutoMatch($invoice)) {
                $matched++;
            }
        }

        return response()->json([
            'ok' => true,
            'matched' => $matched,
            'total' => $unmatchedInvoices->count(),
            'message' => "Matched {$matched} of {$unmatchedInvoices->count()} invoices.",
        ]);
    }

    /**
     * Search unmatched bank transactions for manual matching.
     */
    private function handleSearchTransactions(Request $request, $user, string $role): JsonResponse
    {
        if (! $this->isReviewer($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $query = $request->input('query', '');
        $invoiceId = $request->input('invoice_id');

        $txQuery = BankTransaction::where('match_status', 'unmatched')->where('type', 'debit');

        if ($query !== '') {
            $txQuery->where(function ($q) use ($query) {
                $q->where('description', 'LIKE', '%' . $query . '%')
                  ->orWhere('amount', 'LIKE', '%' . $query . '%')
                  ->orWhere('reference_number', 'LIKE', '%' . $query . '%');
            });
        }

        $results = $txQuery->orderByDesc('transaction_date')->limit(20)->get()->map(fn ($tx) => [
            'id' => $tx->id,
            'date' => $tx->transaction_date->format('Y-m-d'),
            'description' => $tx->description,
            'amount' => $tx->amount,
            'reference' => $tx->reference_number,
            'balance' => $tx->balance,
        ]);

        return response()->json(['ok' => true, 'transactions' => $results]);
    }

    /**
     * Manually match an invoice to a bank transaction.
     */
    private function handleManualMatch(Request $request, $user, string $role): JsonResponse
    {
        if (! $this->isReviewer($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'invoice_id' => 'required|integer|exists:invoice_submissions,id',
            'transaction_id' => 'required|integer|exists:bank_transactions,id',
        ]);

        $invoice = InvoiceSubmission::findOrFail($request->input('invoice_id'));
        $tx = BankTransaction::findOrFail($request->input('transaction_id'));

        $invoice->update([
            'matched_transaction_id' => $tx->id,
            'match_confidence' => 100,
            'verification_status' => 'verified',
        ]);

        $tx->update(['match_status' => 'matched']);

        ActivityLogService::log(
            $user->id,
            'invoice_manual_match',
            $user->name . ' manually matched invoice #' . $invoice->id . ' (' . $invoice->vendor_name . ') to transaction #' . $tx->id,
            'invoice_submission',
            $invoice->id
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Parse XLS/XLSX spreadsheet directly by detecting columns — no AI needed.
     */
    private function parseSpreadsheetDirectly(string $fullPath): array
    {
        $spreadsheet = IOFactory::load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, false);

        // Find the header row by looking for common bank statement column names
        $headerRowIdx = null;
        $colMap = [];

        $dateAliases = ['date', 'txn date', 'transaction date', 'trans date', 'value date', 'value dt', 'posting date'];
        $descAliases = ['narration', 'description', 'particulars', 'details', 'transaction details', 'remarks', 'transaction description'];
        $refAliases = ['chq./ref.no.', 'chq/ref no', 'ref no', 'reference', 'reference no', 'ref.no.', 'cheque no', 'chq no', 'utr', 'transaction id'];
        $withdrawAliases = ['withdrawal amt.', 'withdrawal amt', 'withdrawal', 'debit', 'debit amt', 'debit amount', 'dr', 'withdrawals'];
        $depositAliases = ['deposit amt.', 'deposit amt', 'deposit', 'credit', 'credit amt', 'credit amount', 'cr', 'deposits'];
        $balanceAliases = ['closing balance', 'balance', 'running balance', 'available balance', 'bal'];

        foreach ($allRows as $idx => $row) {
            $matched = 0;
            $tempMap = [];
            foreach ($row as $colIdx => $cell) {
                $val = strtolower(trim((string) ($cell ?? '')));
                if (! isset($tempMap['date']) && in_array($val, $dateAliases, true)) { $tempMap['date'] = $colIdx; $matched++; }
                elseif (! isset($tempMap['description']) && in_array($val, $descAliases, true)) { $tempMap['description'] = $colIdx; $matched++; }
                elseif (! isset($tempMap['reference']) && in_array($val, $refAliases, true)) { $tempMap['reference'] = $colIdx; $matched++; }
                elseif (! isset($tempMap['withdrawal']) && in_array($val, $withdrawAliases, true)) { $tempMap['withdrawal'] = $colIdx; $matched++; }
                elseif (! isset($tempMap['deposit']) && in_array($val, $depositAliases, true)) { $tempMap['deposit'] = $colIdx; $matched++; }
                elseif (! isset($tempMap['balance']) && in_array($val, $balanceAliases, true)) { $tempMap['balance'] = $colIdx; $matched++; }
            }
            // Need at least date + one amount column
            if ($matched >= 3 && isset($tempMap['date'])) {
                $headerRowIdx = $idx;
                $colMap = $tempMap;
                break;
            }
        }

        if ($headerRowIdx === null) {
            Log::warning('parseSpreadsheetDirectly: could not find header row');
            return [];
        }

        Log::info('parseSpreadsheetDirectly: found header', [
            'headerRow' => $headerRowIdx,
            'columns' => $colMap,
        ]);

        $transactions = [];

        for ($i = $headerRowIdx + 1; $i < count($allRows); $i++) {
            $row = $allRows[$i];

            // Get date
            $dateRaw = trim((string) ($row[$colMap['date']] ?? ''));
            if (empty($dateRaw) || $dateRaw === '' || strpos($dateRaw, '***') !== false) {
                continue;
            }

            // Parse date (handle DD/MM/YY, DD/MM/YYYY, DD-MM-YYYY etc.)
            $date = $this->parseStatementDate($dateRaw);
            if (! $date) {
                continue;
            }

            // Get description
            $description = trim((string) ($row[$colMap['description'] ?? -1] ?? ''));
            $reference = isset($colMap['reference']) ? trim((string) ($row[$colMap['reference']] ?? '')) : null;

            // Get amounts
            $withdrawal = isset($colMap['withdrawal']) ? $this->parseAmount($row[$colMap['withdrawal']] ?? null) : 0;
            $deposit = isset($colMap['deposit']) ? $this->parseAmount($row[$colMap['deposit']] ?? null) : 0;
            $balance = isset($colMap['balance']) ? $this->parseAmount($row[$colMap['balance']] ?? null) : null;

            // Determine type and amount
            $amount = 0;
            $type = 'debit';
            if ($withdrawal > 0) {
                $amount = $withdrawal;
                $type = 'debit';
            } elseif ($deposit > 0) {
                $amount = $deposit;
                $type = 'credit';
            } else {
                continue; // Skip rows with no amount
            }

            $transactions[] = [
                'date' => $date,
                'description' => $description,
                'reference' => $reference ?: null,
                'amount' => $amount,
                'type' => $type,
                'balance' => $balance,
            ];
        }

        return $transactions;
    }

    private function parseStatementDate(string $dateRaw): ?string
    {
        // DD/MM/YY or DD/MM/YYYY
        if (preg_match('#^(\d{1,2})[/\-](\d{1,2})[/\-](\d{2,4})$#', $dateRaw, $m)) {
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year = $m[3];
            if (strlen($year) === 2) {
                $year = ((int) $year > 50 ? '19' : '20') . $year;
            }
            if (checkdate((int) $month, (int) $day, (int) $year)) {
                return "{$year}-{$month}-{$day}";
            }
        }
        // Try standard parse
        $ts = strtotime($dateRaw);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        return null;
    }

    private function parseAmount($value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }
        // Remove commas, spaces, currency symbols
        $clean = preg_replace('/[^\d.\-]/', '', (string) $value);
        return abs((float) $clean);
    }

    /**
     * Try to auto-match a single invoice against unmatched bank transactions.
     */
    private function tryAutoMatch(InvoiceSubmission $invoice): bool
    {
        $amount = (float) $invoice->amount;
        $invoiceDate = $invoice->invoice_date;

        // Pre-filter: amount within 10% range, date within 15 days, debit only, unmatched
        $amountLow = $amount * 0.90;
        $amountHigh = $amount * 1.10;
        $dateLow = $invoiceDate->copy()->subDays(15)->toDateString();
        $dateHigh = $invoiceDate->copy()->addDays(15)->toDateString();

        $candidates = BankTransaction::where('match_status', 'unmatched')
            ->where('type', 'debit')
            ->whereBetween('amount', [$amountLow, $amountHigh])
            ->whereBetween('transaction_date', [$dateLow, $dateHigh])
            ->limit(30)
            ->get();

        Log::info('tryAutoMatch: pre-filtered candidates', [
            'invoice_id' => $invoice->id,
            'vendor' => $invoice->vendor_name,
            'amount' => $amount,
            'date' => $invoiceDate->format('Y-m-d'),
            'candidates' => $candidates->count(),
        ]);

        if ($candidates->isEmpty()) {
            $invoice->update(['verification_status' => 'no_match']);
            return false;
        }

        // Check for exact amount + description match first (skip AI if obvious)
        foreach ($candidates as $tx) {
            $descLower = strtolower($tx->description);
            $vendorLower = strtolower($invoice->vendor_name);
            $vendorWords = preg_split('/[\s,.\-]+/', $vendorLower);
            $vendorWords = array_filter($vendorWords, fn ($w) => strlen($w) >= 3);

            $amountMatch = abs($tx->amount - $amount) < 0.01;
            $nameMatch = false;
            foreach ($vendorWords as $word) {
                if (strpos($descLower, $word) !== false) {
                    $nameMatch = true;
                    break;
                }
            }

            if ($amountMatch && $nameMatch) {
                $confidence = 95;
                $daysDiff = abs($invoiceDate->diffInDays($tx->transaction_date));
                if ($daysDiff === 0) $confidence = 98;
                elseif ($daysDiff > 3) $confidence = 90;

                Log::info('tryAutoMatch: direct match found', [
                    'invoice_id' => $invoice->id,
                    'transaction_id' => $tx->id,
                    'confidence' => $confidence,
                ]);

                $invoice->update([
                    'matched_transaction_id' => $tx->id,
                    'match_confidence' => $confidence,
                    'verification_status' => 'verified',
                ]);
                BankTransaction::where('id', $tx->id)->update(['match_status' => 'matched']);
                return true;
            }
        }

        // Fallback: use AI for fuzzy matching on the small candidate set
        $unmatchedTx = $candidates->map(fn ($tx) => [
            'id' => $tx->id,
            'transaction_date' => $tx->transaction_date->format('Y-m-d'),
            'amount' => $tx->amount,
            'description' => $tx->description,
            'type' => $tx->type,
        ])->toArray();

        try {
            $ai = new TessaAIService();
            $result = $ai->matchInvoiceToTransaction([
                'vendor_name' => $invoice->vendor_name,
                'amount' => $amount,
                'invoice_date' => $invoiceDate->format('Y-m-d'),
            ], $unmatchedTx);

            if ($result['transaction_id'] && $result['confidence'] >= 60) {
                $invoice->update([
                    'matched_transaction_id' => $result['transaction_id'],
                    'match_confidence' => $result['confidence'],
                    'verification_status' => $result['confidence'] >= 85 ? 'verified' : 'mismatch',
                ]);

                BankTransaction::where('id', $result['transaction_id'])
                    ->update(['match_status' => 'matched']);

                return true;
            } else {
                $invoice->update(['verification_status' => 'no_match']);
            }
        } catch (\Throwable $e) {
            Log::warning('Auto-match failed for invoice #' . $invoice->id, ['error' => $e->getMessage()]);
        }

        return false;
    }
}
