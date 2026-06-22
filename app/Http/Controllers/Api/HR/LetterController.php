<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\IssuedLetter;
use App\Models\Role;
use App\Models\User;
use App\Services\LetterSalaryCalculator;
use App\Services\LetterTemplateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LetterController extends Controller
{
    private const ALLOWED_ROLES = [
        Role::SLUG_CEO,
        Role::SLUG_COO,
        Role::SLUG_CFO,
        Role::SLUG_HR,
        Role::SLUG_HR_OPERATIONS,
        Role::SLUG_BUSINESS_ANALYST,
    ];

    public function __construct(
        private LetterTemplateService $templates,
        private LetterSalaryCalculator $salary,
    ) {
    }

    public function previewBreakup(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'annual_ctc' => 'required|numeric|min:0',
            'employee_category' => 'required|in:freelancer,intern,fulltime',
        ]);

        return response()->json([
            'breakup' => $this->salary->breakup(
                (float) $data['annual_ctc'],
                $data['employee_category'],
            ),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Drafts have a null issued_at; sort everything by most-recent activity so
        // a freshly-saved draft surfaces at the top alongside recently-issued letters.
        $query = IssuedLetter::with(['issuedBy:id,name', 'recipient:id,name'])
            ->orderByRaw('COALESCE(issued_at, updated_at) DESC');
        if ($request->filled('letter_type')) {
            $query->where('letter_type', $request->input('letter_type'));
        }
        if ($request->filled('employee_category')) {
            $query->where('employee_category', $request->input('employee_category'));
        }
        if ($request->filled('search')) {
            $s = $request->input('search');
            $query->where(function ($q) use ($s) {
                $q->where('recipient_name', 'like', "%{$s}%")
                  ->orWhere('recipient_email', 'like', "%{$s}%")
                  ->orWhere('role_title', 'like', "%{$s}%");
            });
        }

        $letters = $query->limit(200)->get()->map(function (IssuedLetter $l) {
            return [
                'id' => $l->id,
                'letter_type' => $l->letter_type,
                'employee_category' => $l->employee_category,
                'status' => $l->status,
                'recipient_name' => $l->recipient_name,
                'recipient_email' => $l->recipient_email,
                'recipient_phone' => $l->recipient_phone,
                'role_title' => $l->role_title,
                'department' => $l->department,
                'start_date' => $l->start_date?->format('Y-m-d'),
                'issued_at' => $l->issued_at?->toIso8601String(),
                'updated_at' => $l->updated_at?->toIso8601String(),
                'issued_by' => $l->issuedBy?->name,
                'share_token' => $l->share_token,
                // Drafts carry no token; guard the route() helper or the whole list 500s.
                'download_url' => url('/api/letters/' . $l->id . '/download'),
                'share_url' => $l->share_token ? route('letters.share', ['token' => $l->share_token]) : null,
            ];
        });

        return response()->json(['letters' => $letters]);
    }

    public function templateConfig(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $variants = $this->templates->variants();
        $payload = [];
        foreach ($variants as $key => $v) {
            [$letterType, $category] = explode('.', $key);
            $payload[] = [
                'key' => $key,
                'letter_type' => $letterType,
                'employee_category' => $category,
                'label' => $v['label'],
                'fields' => $v['fields'],
            ];
        }

        return response()->json(['variants' => $payload]);
    }

    /**
     * Lightweight lookup that prefills the Letters composer for an existing
     * user (typically an on-probation hire). Powers both an in-UI employee
     * picker and the probation-ending notification's "Release Letter" deep
     * link. Keys are the composer field keys so letters.js can Object.assign
     * them directly.
     */
    public function prefill(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $u = User::with(['department:id,name', 'roleRelation:id,name'])->findOrFail($data['user_id']);

        $toDate = static function ($v): ?string {
            if (empty($v)) {
                return null;
            }
            try {
                return Carbon::parse($v)->toDateString();
            } catch (\Throwable $e) {
                return null;
            }
        };

        $isIntern = $u->employment_type === 'internship';
        $start = $toDate($u->probation_start_date)
            ?? $toDate($u->internship_start_date)
            ?? $toDate($u->joining_date);

        // Prefer the stored probation_end_date; otherwise derive it from the
        // start date (interns 15 days, everyone else 30) so the composer still
        // gets a sensible default.
        $probEnd = $toDate($u->probation_end_date);
        if (! $probEnd && $start) {
            $probEnd = Carbon::parse($start)->addDays($isIntern ? 15 : 30)->toDateString();
        }

        return response()->json([
            'prefill' => [
                'recipient_name' => $u->name,
                'recipient_email' => $u->email,
                'recipient_phone' => $u->personal_mobile,
                'role_title' => $u->designation ?: ($u->roleRelation?->name ?? ''),
                'department' => $u->department?->name,
                'start_date' => $start,
                'probation_end_date' => $probEnd,
                'probation_duration' => $isIntern ? '15 Days' : '1 Month (30 Days)',
                'suggested_category' => $isIntern ? 'intern' : 'fulltime',
            ],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'letter_type' => 'required|in:offer,appointment,probation,relieving,experience',
            'employee_category' => 'required|in:freelancer,intern,fulltime',
            'fields' => 'required|array',
            'body_override_html' => 'nullable|string',
        ]);

        $fields = $request->input('fields', []);

        $html = $this->templates->render(
            $data['letter_type'],
            $data['employee_category'],
            $fields,
            $data['body_override_html'] ?? null,
        );

        $bodyOnly = empty($data['body_override_html'])
            ? $this->templates->renderBodyOnly($data['letter_type'], $data['employee_category'], $fields)
            : null;

        return response()->json([
            'html' => $html,
            'body_html' => $bodyOnly,
        ]);
    }

    /**
     * Auto-save the in-progress letter as a draft. Upsert: with a draft id it
     * updates that row, otherwise it creates a new draft. No PDF or share token
     * is generated until the letter is finalized via store().
     */
    public function saveDraft(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'id' => 'sometimes|nullable|integer',
            'letter_type' => 'required|in:offer,appointment,probation,relieving,experience',
            'employee_category' => 'required|in:freelancer,intern,fulltime',
            'fields' => 'sometimes|array',
            'body_override_html' => 'nullable|string',
            'body_overridden' => 'sometimes|boolean',
        ]);

        // validate() strips nested keys not listed under fields.* — pull the full
        // partial payload back so every field the user has typed so far persists.
        $fields = $request->input('fields', []);

        $bodyOverride = $data['body_overridden'] ?? false;
        $bodyHtmlSanitized = null;
        if ($bodyOverride && !empty($data['body_override_html'])) {
            $bodyHtmlSanitized = $this->templates->sanitizeBodyHtml($data['body_override_html']);
        }

        $attributes = [
            'letter_type' => $data['letter_type'],
            'employee_category' => $data['employee_category'],
            'status' => IssuedLetter::STATUS_DRAFT,
            'recipient_name' => $fields['recipient_name'] ?? null,
            'recipient_email' => $fields['recipient_email'] ?? null,
            'recipient_phone' => $fields['recipient_phone'] ?? null,
            'role_title' => $fields['role_title'] ?? null,
            'department' => $fields['department'] ?? null,
            'start_date' => $fields['start_date'] ?? null,
            'letter_date' => $fields['letter_date'] ?? null,
            'payload' => $fields,
            'body_overridden' => $bodyOverride,
            'body_html' => $bodyHtmlSanitized,
        ];

        // The status-scoped lookup makes it impossible to mutate an issued letter
        // through this endpoint — a stale or foreign id just falls through to a
        // fresh draft instead of editing a finalized row.
        $draft = null;
        if (! empty($data['id'])) {
            $draft = IssuedLetter::where('id', $data['id'])
                ->where('status', IssuedLetter::STATUS_DRAFT)
                ->first();
        }

        if ($draft) {
            $draft->update($attributes);
        } else {
            $attributes['issued_by_user_id'] = $request->user()->id;
            $draft = IssuedLetter::create($attributes);
        }

        return response()->json([
            'ok' => true,
            'id' => $draft->id,
            'status' => IssuedLetter::STATUS_DRAFT,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'id' => 'sometimes|nullable|integer',
            'letter_type' => 'required|in:offer,appointment,probation,relieving,experience',
            'employee_category' => 'required|in:freelancer,intern,fulltime',
            'fields' => 'required|array',
            'fields.recipient_name' => 'required|string|max:200',
            'fields.recipient_email' => 'required|email|max:200',
            'fields.recipient_phone' => 'nullable|string|max:32',
            'fields.role_title' => 'required|string|max:200',
            'fields.position_designation' => 'nullable|string|max:200',
            'fields.department' => 'nullable|string|max:100',
            'fields.ai_responsibilities' => 'nullable|string|max:4000',
            'fields.start_date' => 'nullable|date',
            'fields.letter_date' => 'nullable|date',
            'body_override_html' => 'nullable|string',
            'body_overridden' => 'sometimes|boolean',
            'reissue' => 'sometimes|boolean',
        ]);

        // Releasing a resumed draft finalizes that same row in place. By default a
        // release retry against an already-issued row is idempotent (return it as-is
        // so we never mint a duplicate or regenerate the PDF). When `reissue` is set,
        // the client is intentionally editing an issued letter — update it in place,
        // regenerate the PDF, and keep its share token so links already sent still work.
        $reissue = $request->boolean('reissue');
        $existing = null;
        if (! empty($data['id'])) {
            $row = IssuedLetter::find($data['id']);
            if ($row && $row->status === IssuedLetter::STATUS_ISSUED) {
                if (! $reissue) {
                    return $this->letterResponse($row);
                }
                $existing = $row;
            } elseif ($row && $row->status === IssuedLetter::STATUS_DRAFT) {
                $existing = $row;
            }
        }

        // validate() strips nested keys not listed under fields.* — pull the full
        // payload back from the raw input so role_overview, stipend_monthly, etc.
        // actually reach the renderer.
        $fields = $request->input('fields', []);

        $bodyOverride = $data['body_overridden'] ?? false;
        $bodyHtmlSanitized = null;
        if ($bodyOverride && !empty($data['body_override_html'])) {
            $bodyHtmlSanitized = $this->templates->sanitizeBodyHtml($data['body_override_html']);
        }

        $html = $this->templates->render(
            $data['letter_type'],
            $data['employee_category'],
            $fields,
            $bodyOverride ? $bodyHtmlSanitized : null,
        );

        $safeName = Str::slug($fields['recipient_name'] ?? 'recipient');
        $filename = sprintf(
            '%s-%s-%s-%s.pdf',
            $data['letter_type'],
            $data['employee_category'],
            $safeName ?: 'recipient',
            Str::random(6),
        );

        $pdfPath = $this->templates->generatePdf($html, $filename);

        $recipientUserId = User::where('email', $fields['recipient_email'])->value('id');

        $attributes = [
            'letter_type' => $data['letter_type'],
            'employee_category' => $data['employee_category'],
            'status' => IssuedLetter::STATUS_ISSUED,
            'recipient_user_id' => $recipientUserId,
            'recipient_name' => $fields['recipient_name'],
            'recipient_email' => $fields['recipient_email'],
            'recipient_phone' => $fields['recipient_phone'] ?? null,
            'role_title' => $fields['role_title'],
            'department' => $fields['department'] ?? null,
            'start_date' => $fields['start_date'] ?? null,
            'letter_date' => $fields['letter_date'] ?? Carbon::now()->toDateString(),
            'payload' => $fields,
            'body_html' => $bodyHtmlSanitized,
            'body_overridden' => $bodyOverride,
            'pdf_path' => $pdfPath,
            'issued_at' => Carbon::now(),
        ];

        if ($existing) {
            // Update in place: keep the id + original author, and reuse the share
            // token so links already shared keep resolving (now to the new PDF).
            // Editing an already-issued letter preserves its original issue date.
            $wasIssued = $existing->status === IssuedLetter::STATUS_ISSUED;
            $oldPdf = $existing->pdf_path;
            if ($wasIssued) {
                unset($attributes['issued_at']);
            }
            $attributes['share_token'] = $existing->share_token ?: $this->templates->newShareToken();
            $existing->update($attributes);
            $letter = $existing;
            // Drop the superseded PDF so regenerated edits don't orphan files.
            if ($oldPdf && $oldPdf !== $pdfPath && Storage::disk('public')->exists($oldPdf)) {
                Storage::disk('public')->delete($oldPdf);
            }
        } else {
            $attributes['issued_by_user_id'] = $request->user()->id;
            $attributes['share_token'] = $this->templates->newShareToken();
            $letter = IssuedLetter::create($attributes);
        }

        return $this->letterResponse($letter);
    }

    private function letterResponse(IssuedLetter $letter): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'letter' => [
                'id' => $letter->id,
                'share_token' => $letter->share_token,
                'download_url' => url('/api/letters/' . $letter->id . '/download'),
                'share_url' => route('letters.share', ['token' => $letter->share_token]),
            ],
        ]);
    }

    /**
     * Full editing payload for a single letter — used to resume a draft.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $letter = IssuedLetter::findOrFail($id);

        return response()->json([
            'id' => $letter->id,
            'status' => $letter->status,
            'letter_type' => $letter->letter_type,
            'employee_category' => $letter->employee_category,
            'payload' => $letter->payload ?? [],
            'body_html' => $letter->body_html,
            'body_overridden' => (bool) $letter->body_overridden,
        ]);
    }

    /**
     * Hard-delete a draft or issued letter. Removes the PDF file too; deleting the
     * row is what disables the public share link (publicShare 404s once it is gone).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $letter = IssuedLetter::findOrFail($id);

        $disk = Storage::disk('public');
        if (! empty($letter->pdf_path) && $disk->exists($letter->pdf_path)) {
            $disk->delete($letter->pdf_path);
        }

        $letter->delete();

        return response()->json(['ok' => true]);
    }

    public function download(Request $request, int $id): StreamedResponse|Response
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $letter = IssuedLetter::findOrFail($id);
        $disk = Storage::disk('public');
        if (! $disk->exists($letter->pdf_path)) {
            return response()->json(['error' => 'PDF missing'], 404);
        }

        $filename = sprintf(
            '%s-letter-%s.pdf',
            $letter->letter_type,
            Str::slug($letter->recipient_name),
        );

        return $disk->download($letter->pdf_path, $filename);
    }

    public function publicShare(string $token): Response|StreamedResponse
    {
        $letter = IssuedLetter::where('share_token', $token)->first();
        if (! $letter) {
            abort(404);
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($letter->pdf_path)) {
            abort(404);
        }
        $filename = sprintf(
            '%s-letter-%s.pdf',
            $letter->letter_type,
            Str::slug($letter->recipient_name),
        );

        return $disk->response($letter->pdf_path, $filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    private function isAuthorized(Request $request): bool
    {
        $user = $request->user();
        return $user !== null && in_array($user->role, self::ALLOWED_ROLES, true);
    }
}
