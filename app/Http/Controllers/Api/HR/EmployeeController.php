<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\SalaryRevision;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\GoogleDriveService;
use App\Services\ProvisioningService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    private const ALLOWED_ROLES = [
        Role::SLUG_CEO,
        Role::SLUG_COO,
        Role::SLUG_CFO,
        Role::SLUG_HR,
        Role::SLUG_HR_OPERATIONS,
        Role::SLUG_BUSINESS_ANALYST,
    ];

    private const SALARY_ROLES = [
        Role::SLUG_CEO,
        Role::SLUG_CFO,
    ];

    // Finance team granted READ-ONLY access to the Team section — view + download
    // employee details, documents, and salary for payroll (PF/ESIC/PT, salary
    // bank-account & insurance setup) WITHOUT HR/CFO management rights. Ayush
    // (#4, CFO) already has full access via his role and is listed only to make
    // the finance-team scope explicit; Shoyab (#32) is the new read-only grant.
    private const FINANCE_VIEW_USER_IDS = [4, 32];

    // Only JP (id=1) and Meghana (id=45) can edit DOJ from their My Profile
    // page. Everyone else's DOJ stays read-only there — DOJ changes still flow
    // through Team → Edit Details for any HR-allowed role.
    private const DOJ_SELF_EDIT_USER_IDS = [1, 45];

    /** Per-request cache of the total project count (for the "All projects" collapse). */
    private ?int $projectTotal = null;

    /** Comma-joined project names, or "All projects" when assigned to every project. */
    private function projectsLabel(User $u): string
    {
        $names = $u->projects->pluck('name');
        if ($names->isNotEmpty()) {
            $this->projectTotal ??= \Illuminate\Support\Facades\DB::table('projects')->count();
            $base = $names->count() >= $this->projectTotal ? 'All projects' : $names->join(', ');
        } else {
            $base = '';
        }

        // Profile-only descriptors (work areas that aren't formal/agile projects),
        // appended after the real assignments. See config/profile_extra_projects.php.
        $extras = config('profile_extra_projects', []);
        $extra = $extras[$u->id] ?? [];
        if ($extra) {
            $parts = array_merge($base === '' ? [] : [$base], (array) $extra);

            return implode(', ', $parts);
        }

        return $base;
    }

    public const DOC_FIELDS = [
        'aadhar_front_path' => 'Aadhar Front',
        'aadhar_back_path' => 'Aadhar Back',
        'pan_path' => 'PAN Card',
        'passport_photo_path' => 'Photo',
        'tenth_marksheet_path' => '10th Marksheet',
        'twelfth_marksheet_path' => '12th Marksheet',
        'degree_certificate_path' => 'Degree',
        'pg_certificate_path' => 'PG Certificate',
        'prev_offer_letter_path' => 'Prev Offer Letter',
        'experience_letters_path' => 'Experience Letters',
        'salary_slips_path' => 'Salary Slips',
        'signed_offer_letter_path' => 'Offer Letter',
        'nda_path' => 'NDA',
        'form_11_path' => 'Form 11',
        'college_id_path' => 'College ID',
        'resume_path' => 'Resume',
        'esic_intern_decl_path' => 'ESIC Intern Declaration',
        'insurance_policy_path' => 'Insurance Policy',
    ];

    private const KEY_DOCS = [
        'aadhar_front_path',
        'pan_path',
        'passport_photo_path',
        'signed_offer_letter_path',
        'nda_path',
    ];

    // Feature 6: only these ID documents auto-sync to the employee's Drive folder
    // (Aadhaar front+back, PAN, Photo). All other DOC_FIELDS stay in Tessa only.
    private const DRIVE_SYNC_FIELDS = [
        'aadhar_front_path',
        'aadhar_back_path',
        'pan_path',
        'passport_photo_path',
    ];

    // Feature 7: documents each employment type must download → print → fill & sign →
    // scan → upload. Interns also file the ESIC declaration. Keyed by joined_as category;
    // freelancers have none. Surfaced in a dedicated "Required Documents" section, which
    // bypasses the category visibility exclusions below (e.g. Form 11 is hidden from the
    // intern grid but is required here).
    private const REQUIRED_DOCS_BY_TYPE = [
        'intern' => ['form_11_path', 'nda_path', 'esic_intern_decl_path'],
        'fresher' => ['form_11_path', 'nda_path'],
        'experienced' => ['form_11_path', 'nda_path'],
    ];

    // Blank templates to fill. Files live under public/downloads/; the download link
    // only shows once the file is actually present (so no broken links before HR adds them).
    private const REQUIRED_DOC_TEMPLATES = [
        'form_11_path' => 'form-11-template.pdf',
        'nda_path' => 'nda-template.pdf',
        'esic_intern_decl_path' => 'esic-declaration-template.pdf',
    ];

    // Docs that don't apply to a given category. PG cert is handled separately
    // via the master's-qualification override so it doesn't appear here.
    private const DOC_EXCLUDE_BY_CATEGORY = [
        'intern' => [
            'pg_certificate_path',
            'experience_letters_path',
            'prev_offer_letter_path',
            'salary_slips_path',
            'insurance_policy_path',
            'form_11_path',
        ],
        'fresher' => [
            'experience_letters_path',
            'prev_offer_letter_path',
            'salary_slips_path',
            'college_id_path',
            'esic_intern_decl_path',
        ],
        'experienced' => [
            'esic_intern_decl_path',
            'college_id_path',
        ],
    ];

    private const ACTIVE_STATUSES = ['active', 'probation', 'intern'];

    /**
     * Returns the list of DOC_FIELDS keys that should be visible for $user,
     * given their joined_as category and whether their qualification implies
     * a master's degree (which forces pg_certificate_path back into view).
     * If joined_as is null (legacy), no filtering is applied.
     */
    private function visibleDocFields(User $user): array
    {
        $category = $user->joined_as;
        $exclude = $category ? (self::DOC_EXCLUDE_BY_CATEGORY[$category] ?? []) : [];

        // Master's qualification: PG cert is mandatory regardless of category.
        // Otherwise PG cert is hidden — it would just be noise for non-PG holders.
        // Only meaningful with a category; legacy null-category users keep the
        // original "show everything" behaviour.
        if ($category) {
            if ($this->hasMastersQualification($user->qualification)) {
                $exclude = array_values(array_diff($exclude, ['pg_certificate_path']));
            } elseif (! in_array('pg_certificate_path', $exclude, true)) {
                $exclude[] = 'pg_certificate_path';
            }
        }

        // Pre-cutoff joiners submitted NDA / ESIC offline — strip them so they never
        // surface in My Profile or the HR Team grid, and drop out of the KEY_DOCS
        // completeness score. Form 11 is the exception: it is company-wide regardless
        // of join date, so keep it visible for everyone form11Applies() covers.
        if (! $this->requiredDocsSelfServe($user)) {
            $strip = array_unique(array_merge(...array_values(self::REQUIRED_DOCS_BY_TYPE)));
            if ($this->form11Applies($user)) {
                $strip = array_diff($strip, ['form_11_path']);
            }
            $exclude = array_merge($exclude, $strip);
        }

        return array_values(array_diff(array_keys(self::DOC_FIELDS), array_unique($exclude)));
    }

    /**
     * DOC_FIELDS keys the user must upload, by joined_as category (Feature 7).
     * Freelancers have none; falls back to the intern set by employment_type and
     * otherwise to the full-time set when joined_as isn't set yet.
     */
    private function requiredDocFields(User $user): array
    {
        if ($user->employment_type === 'freelancer') {
            return [];
        }

        $fields = [];

        // Form 11 (EPF declaration) is company-wide for all active non-freelancer,
        // non-exempt employees, regardless of joining date.
        if ($this->form11Applies($user)) {
            $fields[] = 'form_11_path';
        }

        // NDA / ESIC stay self-serve only for on/after-cutoff joiners.
        if ($this->requiredDocsSelfServe($user)) {
            $category = $user->joined_as;
            if ($category && isset(self::REQUIRED_DOCS_BY_TYPE[$category])) {
                $set = self::REQUIRED_DOCS_BY_TYPE[$category];
            } elseif ($user->employment_type === 'internship' || $user->employee_status === 'intern') {
                $set = self::REQUIRED_DOCS_BY_TYPE['intern'];
            } else {
                $set = self::REQUIRED_DOCS_BY_TYPE['experienced'];
            }
            foreach ($set as $f) {
                if (! in_array($f, $fields, true)) {
                    $fields[] = $f;
                }
            }
        }

        return $fields;
    }

    /**
     * Whether $user fills the NDA / Form 11 / ESIC "Required Documents" in the portal.
     * Only employees who joined on/after config('required_docs.self_serve_from') do —
     * everyone earlier (or with no joining date) submitted them offline. Manual
     * force/exempt id lists in the same config override the date. See config/required_docs.php.
     */
    private function requiredDocsSelfServe(User $user): bool
    {
        $cfg = config('required_docs');
        if (in_array((int) $user->id, array_map('intval', $cfg['force_user_ids'] ?? []), true)) {
            return true;
        }
        if (in_array((int) $user->id, array_map('intval', $cfg['exempt_user_ids'] ?? []), true)) {
            return false;
        }
        $cutoff = $cfg['self_serve_from'] ?? null;

        // NULL joining_date → treated as a pre-cutoff/legacy employee (exempt).
        return $cutoff && $user->joining_date !== null
            && $user->joining_date->gte(\Carbon\Carbon::parse($cutoff));
    }

    /**
     * Whether the company-wide Form 11 (EPF declaration) requirement applies to
     * $user. Unlike requiredDocsSelfServe(), this is NOT gated by joining date:
     * every active, non-freelancer employee must file Form 11 — except the
     * exempt_user_ids (leadership) in config/required_docs.php.
     */
    private function form11Applies(User $user): bool
    {
        if ($user->employment_type === 'freelancer') {
            return false;
        }
        if (! in_array($user->employee_status, self::ACTIVE_STATUSES, true)) {
            return false;
        }
        $exempt = array_map('intval', config('required_docs.exempt_user_ids', []));

        return ! in_array((int) $user->id, $exempt, true);
    }

    private function hasMastersQualification(?string $qualification): bool
    {
        if (! $qualification) {
            return false;
        }

        return (bool) preg_match(
            '/\b(?:mba|m\.?sc|m\.?tech|m\.?com|m\.?phil|m\.?e|m\.?a|m\.?s|pgdm|pgd|p\.?g|postgrad|post[\s\-]graduate|master\'?s?)\b/i',
            $qualification
        );
    }

    /** Who can open the Team section (view + download): management roles or the read-only finance allowlist. */
    private function canViewTeam($user): bool
    {
        return in_array($user->role, self::ALLOWED_ROLES, true)
            || in_array($user->id, self::FINANCE_VIEW_USER_IDS, true);
    }

    /** Add/edit employees, documents, salary — management roles only (the finance allowlist is read-only). */
    private function canManageTeam($user): bool
    {
        return in_array($user->role, self::ALLOWED_ROLES, true);
    }

    /** Salary figures/history visibility: CEO/CFO or the finance allowlist. */
    private function canSeeSalary($user): bool
    {
        return in_array($user->role, self::SALARY_ROLES, true)
            || in_array($user->id, self::FINANCE_VIEW_USER_IDS, true);
    }

    /** Who can open the Employee Documents (HR Records) browser — same lock as the tab (config/hr_records). */
    private function canViewHrRecords($user): bool
    {
        return in_array($user->role, config('hr_records.viewer_roles', []), true)
            || in_array($user->id, config('hr_records.viewer_ids', []), true);
    }

    /**
     * List a Google Drive folder's children for the in-portal Employee Documents browser
     * (replaces the old cross-origin embeddedfolderview iframe). Read through the HR-writer
     * account so it works for any authorised viewer. Restricted to the master HR folder +
     * known per-employee subfolders, so the writer's wider Drive can't be browsed. The
     * frontend renders restyled tiles, navigates in-page, and previews files inline — so
     * nothing opens a new Google tab.
     */
    public function driveFolder(Request $request, GoogleDriveService $drive): JsonResponse
    {
        $user = $request->user();
        if (! $this->canViewHrRecords($user)) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 403);
        }

        $master = (string) config('services.google.service_account.drive_folder_id');
        $folder = (string) $request->query('folder', '');

        // Containment: only the master HR folder or a descendant of it may be listed (the writer
        // has full Drive scope — never list a folder outside the HR tree). Unknown / outside → root.
        if ($folder === '' || ($folder !== $master && ! $drive->isWithinMaster($folder))) {
            $folder = $master;
        }

        $res = $drive->listFolder($folder);

        $files = array_map(function ($f) {
            $isFolder = ($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder';

            return [
                'id' => $f['id'] ?? null,
                'name' => $f['name'] ?? '(untitled)',
                'mimeType' => $f['mimeType'] ?? '',
                'is_folder' => $isFolder,
                'iconLink' => $f['iconLink'] ?? null,
                'modifiedTime' => $f['modifiedTime'] ?? null,
                'size' => $f['size'] ?? null,
            ];
        }, $res['files'] ?? []);

        // Folders first, then files; each group alphabetical.
        usort($files, function ($a, $b) {
            if ($a['is_folder'] !== $b['is_folder']) {
                return $a['is_folder'] ? -1 : 1;
            }

            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return response()->json([
            'ok' => $res['ok'] ?? false,
            'reason' => $res['reason'] ?? null,
            'folder' => $folder,
            'is_root' => $folder === $master,
            'files' => $files,
        ]);
    }

    /**
     * Move a Google Drive file or folder to Trash (recoverable ~30 days) from the Employee
     * Documents browser. Same lock as the browser (canViewHrRecords). Hard-bounded to the
     * master HR tree — the root folder itself can never be deleted, nor anything outside it.
     */
    public function trashDriveItem(Request $request, GoogleDriveService $drive): JsonResponse
    {
        $user = $request->user();
        if (! $this->canViewHrRecords($user)) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 403);
        }

        $id = trim((string) $request->input('id', ''));
        $master = (string) config('services.google.service_account.drive_folder_id');
        if ($id === '' || $id === $master) {
            return response()->json(['ok' => false, 'error' => 'That item cannot be deleted.'], 422);
        }
        if (! $drive->isWithinMaster($id)) {
            return response()->json(['ok' => false, 'error' => 'This item is outside Employee Documents and cannot be deleted here.'], 422);
        }

        if (! $drive->trashItem($id)) {
            return response()->json(['ok' => false, 'error' => 'Could not delete. Try again, or check the Google connection.'], 500);
        }

        // If we trashed an employee's cached Drive folder, forget the id so the next upload
        // re-provisions a fresh folder instead of writing into the trashed one. (users has
        // timestamps disabled, so a query-builder update() won't touch updated_at.)
        User::where('google_drive_folder_id', $id)->update(['google_drive_folder_id' => null]);

        ActivityLogService::log($user->id, 'employee_drive_trash', "{$user->name} moved a Drive item to Trash ({$id})");

        return response()->json(['ok' => true]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->canViewTeam($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $canSeeSalary = $this->canSeeSalary($user);

        $query = User::with(['roleRelation', 'reportingManager:id,name', 'projects:id,name', 'department', 'designationRelation'])
            ->where('id', '!=', 33) // exclude admin
            ->orderBy('name');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }
        if ($request->filled('employment_type')) {
            $query->where('employment_type', $request->input('employment_type'));
        }
        if ($request->filled('role_id')) {
            $query->where('role_id', $request->input('role_id'));
        }
        if ($request->filled('employee_status')) {
            if ($request->input('employee_status') === 'freelancer') {
                $query->where('employment_type', 'freelancer');
            } else {
                $query->where('employee_status', $request->input('employee_status'));
            }
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->input('department_id'));
        }

        // By default show only active employees, unless 'show_all' is passed or
        // the user picked an explicit employee_status (e.g. Terminated, Resigned)
        // — those statuses imply is_active=false and would otherwise be filtered out.
        if (! $request->boolean('show_all') && ! $request->filled('employee_status')) {
            $query->where('is_active', true);
        }

        $users = $query->get();

        $employees = [];
        $statsFullTime = 0;
        $statsInternship = 0;
        $statsFreelancer = 0;
        $statsDocsComplete = 0;
        $statsDocsPending = 0;
        $statsOnProbation = 0;
        $statsInterns = 0;
        $statsNoticePeriod = 0;
        $statsExited = 0;

        $now = Carbon::now('Asia/Kolkata');
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();
        $joinedThisMonth = 0;
        $exitedThisMonth = 0;
        $probationEndingSoon = 0;

        // Users who opted out of birthday surfaces — suppress the directory's
        // "Birthday today" badge for them (their DOB stays on record).
        $bdayExcluded = config('birthday_exclusions.user_ids', []);

        foreach ($users as $u) {
            // Skip users without any HR data (not synced)
            if (! $u->personal_mobile && ! $u->designation && ! $u->personal_email && ! $u->employee_status) {
                continue;
            }

            $visible = $this->visibleDocFields($u);
            $docs = [];
            $docsComplete = 0;
            // Scope to the docs actually visible for this user — a pre-cutoff joiner
            // has the NDA stripped, so it must not count against their completeness.
            $docsTotal = count(array_intersect(self::KEY_DOCS, $visible));

            foreach (self::DOC_FIELDS as $field => $label) {
                if (! in_array($field, $visible, true)) {
                    continue;
                }
                $path = $u->$field;
                $docs[$field] = [
                    'label' => $label,
                    'path' => $path ? asset('storage/' . $path) : null,
                    'uploaded' => (bool) $path, // '0' sentinel from a failed upload is falsy → not "uploaded" (matches the path line above)
                ];
            }

            foreach (array_intersect(self::KEY_DOCS, $visible) as $kd) {
                if ($u->$kd) {
                    $docsComplete++;
                }
            }

            $isComplete = $docsComplete === $docsTotal;
            if ($isComplete) {
                $statsDocsComplete++;
            } else {
                $statsDocsPending++;
            }

            if ($u->employment_type === 'full_time') {
                $statsFullTime++;
            } elseif ($u->employment_type === 'internship') {
                $statsInternship++;
            } elseif ($u->employment_type === 'freelancer') {
                $statsFreelancer++;
            }

            // Status-based stats
            $status = $u->employee_status ?? 'active';
            if ($status === 'probation') {
                $statsOnProbation++;
                if ($u->probation_end_date && $u->probation_end_date->lte($now->copy()->addDays(30))) {
                    $probationEndingSoon++;
                }
            } elseif ($status === 'intern') {
                $statsInterns++;
            } elseif ($status === 'notice_period') {
                $statsNoticePeriod++;
            } elseif (in_array($status, ['resigned', 'terminated', 'absconding', 'exited'], true)) {
                $statsExited++;
            }

            if ($u->joining_date && $u->joining_date >= $monthStart && $u->joining_date <= $monthEnd) {
                $joinedThisMonth++;
            }
            if ($u->exit_date && $u->exit_date >= $monthStart && $u->exit_date <= $monthEnd) {
                $exitedThisMonth++;
            }

            // Filter by doc status if requested
            if ($request->input('doc_status') === 'complete' && ! $isComplete) {
                continue;
            }
            if ($request->input('doc_status') === 'incomplete' && $isComplete) {
                continue;
            }

            $emp = [
                'id' => $u->id,
                'name' => $u->name,
                'parent_name' => $u->parent_name,
                'email' => $u->email,
                'role' => $u->roleRelation?->name ?? '',
                'designation' => $u->designationRelation?->title ?? $u->designation,
                'gender' => $u->gender,
                'blood_group' => $u->blood_group,
                'marital_status' => $u->marital_status,
                'qualification' => $u->qualification,
                'current_address' => $u->current_address,
                'permanent_address' => $u->permanent_address,
                'nominee' => [
                    'name' => $u->nominee_name,
                    'age' => $u->nominee_age,
                    'dob' => $u->nominee_dob?->format('Y-m-d'),
                    'relation' => $u->nominee_relation,
                ],
                'pf' => [
                    'applicable' => (bool) $u->pf_applicable,
                    'uan' => $u->pf_uan,
                ],
                'insurance' => [
                    'applicable' => (bool) $u->insurance_applicable,
                    'number' => $u->insurance_number,
                ],
                'employment_type' => $u->employment_type,
                'employee_status' => $status,
                'personal_mobile' => $u->personal_mobile,
                'personal_email' => $u->personal_email,
                'emergency_contact_name' => $u->emergency_contact_name,
                'emergency_contact_number' => $u->emergency_contact_number,
                'experienced' => (bool) $u->experienced,
                'joined_as' => $u->joined_as,
                'joining_date' => $u->joining_date?->format('Y-m-d'),
                // Year-stripped: the directory is visible to the whole team,
                // so age/birth-year must never leave the server here.
                'date_of_birth' => $u->date_of_birth?->format('Y-m-d'),
                'birthday' => in_array($u->id, $bdayExcluded, true) ? null : $u->date_of_birth?->format('m-d'),
                'birthday_label' => $u->date_of_birth?->format('j M'),
                'hourly_rate' => $u->hourly_rate,
                'reporting_manager' => $u->reportingManager?->name,
                'projects' => $this->projectsLabel($u),
                'department' => $u->department?->name,
                'department_id' => $u->department_id,
                'designation_id' => $u->designation_id,
                'documents' => $docs,
                'google_drive_folder_id' => $u->google_drive_folder_id,
                'docs_complete' => $docsComplete,
                'docs_total' => $docsTotal,
                'bank' => [
                    'account_holder_name' => $u->bank_account_holder_name,
                    'account_number' => $u->bank_account_number,
                    'ifsc_code' => $u->bank_ifsc_code,
                    'passbook_path' => $u->bank_passbook_path ? asset('storage/' . $u->bank_passbook_path) : null,
                    'has_passbook' => (bool) $u->bank_passbook_path,
                ],
                'is_active' => (bool) $u->is_active,
                // Lifecycle fields
                'probation_start_date' => $u->probation_start_date?->format('Y-m-d'),
                'probation_end_date' => $u->probation_end_date?->format('Y-m-d'),
                'confirmed_date' => $u->confirmed_date?->format('Y-m-d'),
                'internship_start_date' => $u->internship_start_date?->format('Y-m-d'),
                'internship_end_date' => $u->internship_end_date?->format('Y-m-d'),
                'stipend_amount' => $u->stipend_amount,
                'intern_conversion_status' => $u->intern_conversion_status,
                'exit_date' => $u->exit_date?->format('Y-m-d'),
                'exit_reason' => $u->exit_reason,
                'last_working_date' => $u->last_working_date?->format('Y-m-d'),
                'resignation_date' => $u->resignation_date?->format('Y-m-d'),
                'notice_period_days' => $u->notice_period_days,
            ];

            // Only include salary data for authorized roles
            if ($canSeeSalary) {
                $emp['monthly_salary'] = $u->monthly_salary;
                $emp['annual_ctc'] = $u->annual_ctc;
            }

            // Onboarding checklist for probation/intern members
            if (in_array($status, ['probation', 'intern'], true)) {
                $emp['onboarding'] = [
                    'email' => (bool) $u->email,
                    'mobile' => (bool) $u->personal_mobile,
                    'personal_email' => (bool) $u->personal_email,
                    'emergency_contact' => (bool) $u->emergency_contact_name,
                    'docs' => $isComplete,
                ];
            }

            $employees[] = $emp;
        }

        $roles = Role::orderBy('name')->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug]);
        $departments = Department::where('is_active', true)->orderBy('name')->get()->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'slug' => $d->slug]);
        $designations = Designation::where('is_active', true)->orderBy('title')->get()->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'department_id' => $d->department_id]);

        // Designation stays free-text, but the member forms surface existing
        // values plus a standardized "AI Intern" as one-click <datalist>
        // suggestions — keeps the data consistent and matches the "AI Intern"
        // offer/appointment letters.
        $designationSuggestions = User::query()
            ->whereNotNull('designation')
            ->where('designation', '!=', '')
            ->distinct()
            ->orderBy('designation')
            ->pluck('designation')
            ->reject(fn ($d) => trim((string) $d) === 'AI Intern')
            ->values()
            ->all();
        array_unshift($designationSuggestions, 'AI Intern');

        return response()->json([
            'ok' => true,
            'employees' => $employees,
            'roles' => $roles,
            'departments' => $departments,
            'designations' => $designations,
            'designation_suggestions' => $designationSuggestions,
            'can_see_salary' => $canSeeSalary,
            'can_manage' => $this->canManageTeam($user),
            'stats' => [
                'total' => count($employees),
                'full_time' => $statsFullTime,
                'internship' => $statsInternship,
                'freelancer' => $statsFreelancer,
                'docs_complete' => $statsDocsComplete,
                'docs_pending' => $statsDocsPending,
                'on_probation' => $statsOnProbation,
                'interns' => $statsInterns,
                'notice_period' => $statsNoticePeriod,
                'exited' => $statsExited,
                'joined_this_month' => $joinedThisMonth,
                'exited_this_month' => $exitedThisMonth,
                'probation_ending_soon' => $probationEndingSoon,
            ],
        ]);
    }

    /**
     * Field map used by the Teams "Download Data" export.
     * Each entry: [label, callable($user) => value].
     * Keys are stable identifiers used by the frontend.
     */
    private function exportFieldMap(bool $canSeeSalary): array
    {
        $map = [
            'name' => ['Name', fn ($u) => $u->name],
            'email' => ['Office Email', fn ($u) => $u->email],
            'personal_email' => ['Personal Email', fn ($u) => $u->personal_email],
            'personal_mobile' => ['Phone Number', fn ($u) => $u->personal_mobile],
            'gender' => ['Gender', fn ($u) => $u->gender],
            'blood_group' => ['Blood Group', fn ($u) => $u->blood_group],
            'marital_status' => ['Marital Status', fn ($u) => $u->marital_status],
            'qualification' => ['Qualification', fn ($u) => $u->qualification],
            'current_address' => ['Current Address', fn ($u) => $u->current_address],
            'permanent_address' => ['Permanent Address', fn ($u) => $u->permanent_address],
            'nominee_name' => ['Nominee Name', fn ($u) => $u->nominee_name],
            'nominee_age' => ['Nominee Age', fn ($u) => $u->nominee_age],
            'nominee_dob' => ['Nominee DOB', fn ($u) => $u->nominee_dob?->format('Y-m-d')],
            'nominee_relation' => ['Nominee Relation', fn ($u) => $u->nominee_relation],
            'pf_applicable' => ['PF Applicable', fn ($u) => $u->pf_applicable ? 'Yes' : 'No'],
            'pf_uan' => ['PF UAN', fn ($u) => $u->pf_uan],
            'insurance_applicable' => ['Insurance', fn ($u) => $u->insurance_applicable ? 'Yes' : 'No'],
            'insurance_number' => ['Insurance Number', fn ($u) => $u->insurance_number],
            'emergency_contact_name' => ['Emergency Contact Name', fn ($u) => $u->emergency_contact_name],
            'emergency_contact_number' => ['Emergency Contact Number', fn ($u) => $u->emergency_contact_number],
            'role' => ['Role', fn ($u) => $u->roleRelation?->name],
            'designation' => ['Designation', fn ($u) => $u->designationRelation?->title ?? $u->designation],
            'department' => ['Department', fn ($u) => $u->department?->name],
            'employment_type' => ['Employment Type', fn ($u) => $u->employment_type],
            'employee_status' => ['Employee Status', fn ($u) => $u->employee_status],
            'joined_as' => ['Joined As', fn ($u) => $u->joined_as],
            'reporting_manager' => ['Reporting Manager', fn ($u) => $u->reportingManager?->name],
            'joining_date' => ['Joining Date', fn ($u) => $u->joining_date?->format('Y-m-d')],
            'probation_end_date' => ['Probation End Date', fn ($u) => $u->probation_end_date?->format('Y-m-d')],
            'confirmed_date' => ['Confirmed Date', fn ($u) => $u->confirmed_date?->format('Y-m-d')],
            'internship_start_date' => ['Internship Start', fn ($u) => $u->internship_start_date?->format('Y-m-d')],
            'internship_end_date' => ['Internship End', fn ($u) => $u->internship_end_date?->format('Y-m-d')],
            'exit_date' => ['Exit Date', fn ($u) => $u->exit_date?->format('Y-m-d')],
            'bank_account_holder_name' => ['Bank Account Holder', fn ($u) => $u->bank_account_holder_name],
            'bank_account_number' => ['Bank Account Number', fn ($u) => $u->bank_account_number],
            'bank_ifsc_code' => ['Bank IFSC', fn ($u) => $u->bank_ifsc_code],
        ];

        if ($canSeeSalary) {
            $map['monthly_salary'] = ['Monthly Salary', fn ($u) => $u->monthly_salary];
            $map['annual_ctc'] = ['Annual CTC', fn ($u) => $u->annual_ctc];
            $map['stipend_amount'] = ['Stipend', fn ($u) => $u->stipend_amount];
        }

        return $map;
    }

    /** GET /api/employees/export-fields — return the list of fields the user is allowed to export. */
    public function exportFields(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->canViewTeam($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $canSeeSalary = $this->canSeeSalary($user);
        $map = $this->exportFieldMap($canSeeSalary);

        $fields = [];
        foreach ($map as $key => [$label, $_]) {
            $fields[] = ['key' => $key, 'label' => $label];
        }

        return response()->json(['ok' => true, 'fields' => $fields]);
    }

    /** POST /api/employees/export — return an XLSX with the chosen columns for all currently-filtered employees. */
    public function export(Request $request)
    {
        $user = $request->user();
        if (! $this->canViewTeam($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $canSeeSalary = $this->canSeeSalary($user);
        $map = $this->exportFieldMap($canSeeSalary);

        $selected = (array) $request->input('fields', []);
        $selected = array_values(array_filter($selected, fn ($k) => isset($map[$k])));
        if (empty($selected)) {
            return response()->json(['error' => 'Select at least one field to download.'], 422);
        }

        // Mirror the filtering behaviour of index() so the export matches what the user sees.
        $query = User::with(['roleRelation', 'reportingManager:id,name', 'department', 'designationRelation'])
            ->where('id', '!=', 33)
            ->orderBy('name');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }
        if ($request->filled('employment_type')) {
            $query->where('employment_type', $request->input('employment_type'));
        }
        if ($request->filled('role_id')) {
            $query->where('role_id', $request->input('role_id'));
        }
        if ($request->filled('employee_status')) {
            if ($request->input('employee_status') === 'freelancer') {
                $query->where('employment_type', 'freelancer');
            } else {
                $query->where('employee_status', $request->input('employee_status'));
            }
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->input('department_id'));
        }
        if (! $request->boolean('show_all') && ! $request->filled('employee_status')) {
            $query->where('is_active', true);
        }

        $users = $query->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Employees');

        $colCount = count($selected);

        // Identifier columns whose values must stay text. Long numeric strings
        // (bank account, UAN, insurance, phone) would otherwise be auto-typed as
        // numbers and Excel renders them in scientific notation (e.g. 6.18122E+13)
        // or drops leading zeros.
        $textKeys = [
            'personal_mobile', 'emergency_contact_number', 'pf_uan',
            'insurance_number', 'bank_account_number', 'bank_ifsc_code',
        ];

        // Header row
        foreach ($selected as $i => $key) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $map[$key][0]);
        }
        $lastCol = Coordinate::stringFromColumnIndex($colCount);
        $headerRange = 'A1:' . $lastCol . '1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
        $sheet->getStyle($headerRange)->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Data rows
        $row = 2;
        foreach ($users as $u) {
            if (! $u->personal_mobile && ! $u->designation && ! $u->personal_email && ! $u->employee_status) {
                continue; // skip unsynced rows, same rule as index()
            }
            foreach ($selected as $i => $key) {
                $value = ($map[$key][1])($u);
                $col = Coordinate::stringFromColumnIndex($i + 1);
                if (in_array($key, $textKeys, true)) {
                    $sheet->setCellValueExplicit($col . $row, (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($col . $row, $value);
                }
            }
            $row++;
        }

        // Belt-and-suspenders: also pin those identifier columns to text format
        // so the digits are never reformatted when the file is opened.
        $lastDataRow = $row - 1;
        if ($lastDataRow >= 2) {
            foreach ($selected as $i => $key) {
                if (in_array($key, $textKeys, true)) {
                    $col = Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->getStyle($col . '2:' . $col . $lastDataRow)
                        ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                }
            }
        }

        for ($c = 1; $c <= $colCount; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $filename = 'employees-' . now('Asia/Kolkata')->format('Ymd-His') . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /** POST /api/employees/export-documents — XLSX with employee name + hyperlinks to each uploaded document. */
    public function exportDocuments(Request $request)
    {
        $user = $request->user();
        if (! $this->canViewTeam($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // The doc list HR can pick from is DOC_FIELDS + the bank passbook (lives
        // on a separate column but is still an uploaded file we expose).
        $docMap = self::DOC_FIELDS + ['bank_passbook_path' => 'Bank Passbook'];

        $selected = (array) $request->input('fields', []);
        $selected = array_values(array_filter($selected, fn ($k) => isset($docMap[$k])));
        if (empty($selected)) {
            // Default to every doc type so a one-click export still works.
            $selected = array_keys($docMap);
        }

        // Mirror index() filters so the workbook matches what the user sees.
        $query = User::orderBy('name')->where('id', '!=', 33);
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }
        if ($request->filled('employment_type')) {
            $query->where('employment_type', $request->input('employment_type'));
        }
        if ($request->filled('role_id')) {
            $query->where('role_id', $request->input('role_id'));
        }
        if ($request->filled('employee_status')) {
            if ($request->input('employee_status') === 'freelancer') {
                $query->where('employment_type', 'freelancer');
            } else {
                $query->where('employee_status', $request->input('employee_status'));
            }
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->input('department_id'));
        }
        if (! $request->boolean('show_all') && ! $request->filled('employee_status')) {
            $query->where('is_active', true);
        }

        $users = $query->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Documents');

        // Header row: Name | <doc labels>
        $sheet->setCellValue('A1', 'Employee Name');
        foreach ($selected as $i => $field) {
            $col = Coordinate::stringFromColumnIndex($i + 2);
            $sheet->setCellValue($col . '1', $docMap[$field]);
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($selected) + 1);
        $headerRange = 'A1:' . $lastCol . '1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
        $sheet->getStyle($headerRange)->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $row = 2;
        foreach ($users as $u) {
            // Match the unsynced-row skip rule from index()/export() so the
            // workbook doesn't fill up with placeholder users.
            if (! $u->personal_mobile && ! $u->designation && ! $u->personal_email && ! $u->employee_status) {
                continue;
            }

            $sheet->setCellValue('A' . $row, $u->name);

            foreach ($selected as $i => $field) {
                $col = Coordinate::stringFromColumnIndex($i + 2);
                $path = $u->$field ?? null;
                if ($path) {
                    $url = asset('storage/' . $path);
                    $sheet->setCellValue($col . $row, $url);
                    $sheet->getCell($col . $row)->getHyperlink()->setUrl($url);
                    $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('1D4ED8');
                    $sheet->getStyle($col . $row)->getFont()->setUnderline(true);
                } else {
                    $sheet->setCellValue($col . $row, '—');
                }
            }
            $row++;
        }

        $colCount = count($selected) + 1;
        for ($c = 1; $c <= $colCount; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
        $sheet->freezePane('B2');

        $filename = 'employee-documents-' . now('Asia/Kolkata')->format('Ymd-His') . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /** GET /api/employees/export-document-fields — list of selectable document types. */
    public function exportDocumentFields(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->canViewTeam($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $docMap = self::DOC_FIELDS + ['bank_passbook_path' => 'Bank Passbook'];
        $fields = [];
        foreach ($docMap as $key => $label) {
            $fields[] = ['key' => $key, 'label' => $label];
        }

        return response()->json(['ok' => true, 'fields' => $fields]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $action = $request->input('action', 'update');

        if ($action === 'create') {
            return $this->handleCreate($request, $user);
        }
        if ($action === 'update') {
            return $this->handleUpdate($request, $user);
        }
        if ($action === 'change_status') {
            return $this->handleStatusChange($request, $user);
        }
        if ($action === 'update_salary') {
            return $this->handleSalaryUpdate($request, $user);
        }
        if ($action === 'promote') {
            return $this->handlePromote($request, $user);
        }
        if ($action === 'upload_doc') {
            return $this->handleUploadDoc($request, $user);
        }
        if ($action === 'delete_doc') {
            return $this->handleDeleteDoc($request, $user);
        }

        return response()->json(['error' => 'Unknown action'], 422);
    }

    // ── Create new team member ──

    private function handleCreate(Request $request, $authUser): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'parent_name' => 'nullable|string|max:150',
            'email' => 'required|email|unique:users,email',
            'role_id' => 'required|exists:roles,id',
            'reporting_manager_id' => 'nullable|exists:users,id',
            'date_of_birth' => 'nullable|date|before:today',
            'candidate_id' => 'nullable|integer|exists:candidates,id',
        ]);

        $employmentType = $request->input('employment_type', 'full_time');
        $joiningDate = $request->input('joining_date', now()->toDateString());

        $data = [
            'name' => $request->input('name'),
            'parent_name' => $request->input('parent_name'),
            'email' => $request->input('email'),
            'password_hash' => password_hash($request->input('password', '12345678'), PASSWORD_BCRYPT),
            'role_id' => $request->input('role_id'),
            'reporting_manager_id' => $request->input('reporting_manager_id'),
            'is_active' => true,
            'employment_type' => $employmentType,
            'joining_date' => $joiningDate,
            'designation' => $request->input('designation'),
            'department_id' => $request->input('department_id'),
            'designation_id' => $request->input('designation_id'),
            'gender' => $request->input('gender'),
            'date_of_birth' => $request->input('date_of_birth'),
            'personal_mobile' => $request->input('personal_mobile'),
            'personal_email' => $request->input('personal_email'),
            'emergency_contact_name' => $request->input('emergency_contact_name'),
            'emergency_contact_number' => $request->input('emergency_contact_number'),
            'experienced' => $request->input('experienced'),
            'hourly_rate' => $request->input('hourly_rate'),
            'notice_period_days' => $request->input('notice_period_days', 15),
        ];

        // Set employee status based on employment type
        if ($employmentType === 'internship') {
            $data['employee_status'] = 'intern';
            $data['internship_start_date'] = $request->input('internship_start_date', $joiningDate);
            $data['internship_end_date'] = $request->input('internship_end_date');
            $data['stipend_amount'] = $request->input('stipend_amount');
            $data['intern_conversion_status'] = 'pending';
            // 15-day probation window for interns (status stays 'intern'; only the
            // probation_* columns are set) so the probation-ending alert fires for
            // them too. Additive — does not touch internship_* / conversion logic.
            $internStart = $request->input('internship_start_date', $joiningDate);
            $data['probation_start_date'] = $internStart;
            $data['probation_end_date'] = $request->input('probation_end_date', Carbon::parse($internStart)->addDays(15)->toDateString());
        } elseif ($employmentType === 'freelancer') {
            $data['employee_status'] = 'active';
            $data['hourly_rate'] = $request->input('hourly_rate');
        } else {
            $data['employee_status'] = 'probation';
            $data['probation_start_date'] = $joiningDate;
            $data['probation_end_date'] = $request->input('probation_end_date', Carbon::parse($joiningDate)->addDays(30)->toDateString());
            $data['monthly_salary'] = $request->input('monthly_salary');
            $data['annual_ctc'] = $request->input('annual_ctc');
        }

        $newUser = User::create($data);

        ActivityLogService::log(
            $authUser->id,
            'employee_create',
            "{$authUser->name} added new team member: {$newUser->name} ({$newUser->email})",
        );

        // Feature 8: celebrate the new joiner company-wide. Never let it block the hire.
        try {
            Announcement::announceNewJoiner($newUser, $authUser->id);
        } catch (\Throwable $e) {
            report($e);
        }

        // Provision their Employee Records entry (Drive folder + HR-sheet row) so they
        // appear immediately, before any document upload. Deferred + best-effort.
        app(\App\Services\HrGoogleSync::class)->provisionNewHire($newUser->id);

        // Hiring pipeline (candidate mode): this came from the "Add to Team" CTA
        // after the candidate accepted. Link the candidate, walk it to onboarding,
        // open the provisioning ticket and ping Fida + Yuvanesh. Best-effort — a
        // hiccup here must never undo the created account.
        $candidateId = (int) $request->input('candidate_id');
        if ($candidateId) {
            try {
                app(ProvisioningService::class)->linkHiredCandidate($newUser, $candidateId, $authUser);
            } catch (\Throwable $e) {
                report($e);
            }

            return response()->json([
                'ok' => true,
                'id' => $newUser->id,
                'message' => "Account created for {$newUser->email}. Fida + Yuvanesh have been notified to set up the accounts.",
            ]);
        }

        return response()->json(['ok' => true, 'id' => $newUser->id, 'message' => 'Team member added successfully']);
    }

    // ── Update existing employee details ──

    private function handleUpdate(Request $request, $authUser): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $target = User::find($id);
        if (! $target) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $fillable = [
            'role_id', 'reporting_manager_id',
            'personal_mobile', 'personal_email', 'designation', 'gender', 'date_of_birth',
            'emergency_contact_name', 'emergency_contact_number',
            'employment_type', 'joining_date', 'hourly_rate', 'experienced', 'joined_as',
            'department_id', 'designation_id', 'notice_period_days',
            'probation_start_date', 'probation_end_date',
            // Personal/nominee/PF/insurance/bank fields exposed on My Profile
            // need to be editable from the Team page too so HR can fill them
            // in on the employee's behalf when something's missing.
            'blood_group', 'marital_status', 'qualification', 'parent_name',
            'current_address', 'permanent_address',
            'nominee_name', 'nominee_age', 'nominee_dob', 'nominee_relation',
            'pf_applicable', 'pf_uan',
            'insurance_applicable', 'insurance_number',
            'bank_account_holder_name', 'bank_account_number', 'bank_ifsc_code',
        ];

        $data = $request->only($fillable);

        // Guard date fields against malformed input (e.g. an <input type=date>
        // that produced a 2-digit year like "0026-05-18"). Reject anything
        // that isn't a real date with a sane 4-digit year so the corruption
        // can't be re-saved; empty string clears the value.
        foreach (['joining_date', 'probation_start_date', 'probation_end_date'] as $df) {
            if (! array_key_exists($df, $data)) {
                continue;
            }
            $raw = $data[$df];
            if ($raw === null || $raw === '') {
                $data[$df] = null;
                continue;
            }
            try {
                $parsed = Carbon::parse($raw);
            } catch (\Throwable $e) {
                return response()->json(['error' => "Invalid date for " . str_replace('_', ' ', $df) . "."], 422);
            }
            if ((int) $parsed->format('Y') < 2000) {
                return response()->json(['error' => "The " . str_replace('_', ' ', $df) . " has an invalid year. Please re-enter it."], 422);
            }
            $data[$df] = $parsed->toDateString();
        }

        // Booleans arrive as strings ("0"/"1") via the JSON body; cast so
        // toggle "off" actually unsets pf_uan / insurance_number below.
        if (array_key_exists('pf_applicable', $data)) {
            $data['pf_applicable'] = (bool) $data['pf_applicable'];
            if (! $data['pf_applicable']) {
                $data['pf_uan'] = null;
            }
        }
        if (array_key_exists('insurance_applicable', $data)) {
            $data['insurance_applicable'] = (bool) $data['insurance_applicable'];
            if (! $data['insurance_applicable']) {
                $data['insurance_number'] = null;
            }
        }
        if (! empty($data['bank_ifsc_code'])) {
            $data['bank_ifsc_code'] = strtoupper($data['bank_ifsc_code']);
        }

        $target->update($data);

        ActivityLogService::log(
            $authUser->id,
            'employee_update',
            "{$authUser->name} updated employee details for {$target->name}",
        );

        return response()->json(['ok' => true]);
    }

    // ── Change employee status (lifecycle transitions) ──

    private function handleStatusChange(Request $request, $authUser): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $target = User::find($id);
        if (! $target) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $newStatus = $request->input('new_status');
        $validStatuses = ['active', 'probation', 'notice_period', 'resigned', 'terminated', 'absconding', 'intern', 'freelancer', 'exited'];
        if (! in_array($newStatus, $validStatuses, true)) {
            return response()->json(['error' => 'Invalid status'], 422);
        }

        $oldStatus = $target->employee_status;

        if ($newStatus === 'freelancer') {
            $target->employment_type = 'freelancer';
            $target->employee_status = 'active';
        } else {
            $target->employee_status = $newStatus;
        }

        // Handle transition-specific fields
        switch ($newStatus) {
            case 'active':
                // Confirming from probation
                if ($oldStatus === 'probation') {
                    $target->confirmed_date = now();
                }
                // Converting intern to full-time
                if ($oldStatus === 'intern') {
                    $target->intern_conversion_status = 'converted';
                    $target->intern_conversion_date = now();
                    $target->employment_type = 'full_time';
                    if ($request->filled('monthly_salary')) {
                        $target->monthly_salary = $request->input('monthly_salary');
                    }
                    if ($request->filled('annual_ctc')) {
                        $target->annual_ctc = $request->input('annual_ctc');
                    }
                }
                break;

            case 'probation':
                // Intern converting to probation first
                if ($oldStatus === 'intern') {
                    $target->intern_conversion_status = 'converted';
                    $target->intern_conversion_date = now();
                    $target->employment_type = 'full_time';
                    $target->probation_start_date = now();
                    $target->probation_end_date = $request->input('probation_end_date');
                }
                break;

            case 'notice_period':
                $target->resignation_date = $request->input('resignation_date', now()->toDateString());
                break;

            case 'resigned':
                $target->exit_date = $request->input('exit_date', now()->toDateString());
                $target->exit_reason = $request->input('exit_reason');
                $target->last_working_date = $request->input('last_working_date', $request->input('exit_date', now()->toDateString()));
                if (! $target->resignation_date) {
                    $target->resignation_date = $target->exit_date;
                }
                break;

            case 'terminated':
            case 'absconding':
            case 'exited':
                // 'exited' is the umbrella "person has left, don't care to draw
                // a line between resigned/terminated/absconding" bucket — same
                // exit-tracking fields apply.
                $target->exit_date = $request->input('exit_date', now()->toDateString());
                $target->exit_reason = $request->input('exit_reason');
                $target->last_working_date = $request->input('last_working_date', $target->exit_date);
                break;
        }

        // If intern not converting, mark as not_converted
        if ($oldStatus === 'intern' && in_array($newStatus, ['resigned', 'terminated', 'absconding', 'exited'], true)) {
            $target->intern_conversion_status = 'not_converted';
        }

        // Sync is_active
        $target->syncIsActive();
        $target->save();

        ActivityLogService::log(
            $authUser->id,
            'employee_status_change',
            "{$authUser->name} changed status of {$target->name} from {$oldStatus} to {$newStatus}",
        );

        return response()->json(['ok' => true, 'message' => "Status changed to {$newStatus}"]);
    }

    // ── Salary revision ──

    private function handleSalaryUpdate(Request $request, $authUser): JsonResponse
    {
        // Only CEO and CFO can update salary
        if (! in_array($authUser->role, self::SALARY_ROLES, true)) {
            return response()->json(['error' => 'Unauthorized — salary changes restricted to CEO/CFO'], 403);
        }

        $id = (int) $request->input('id', 0);
        $target = User::find($id);
        if (! $target) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $request->validate([
            'effective_date' => 'required|date',
        ]);

        // Create revision record
        SalaryRevision::create([
            'user_id' => $target->id,
            'effective_date' => $request->input('effective_date'),
            'previous_monthly_salary' => $target->monthly_salary,
            'new_monthly_salary' => $request->input('monthly_salary'),
            'previous_annual_ctc' => $target->annual_ctc,
            'new_annual_ctc' => $request->input('annual_ctc'),
            'revision_reason' => $request->input('revision_reason'),
            'revised_by' => $authUser->id,
        ]);

        // Update user salary
        $target->update([
            'monthly_salary' => $request->input('monthly_salary'),
            'annual_ctc' => $request->input('annual_ctc'),
        ]);

        ActivityLogService::log(
            $authUser->id,
            'employee_salary_update',
            "{$authUser->name} revised salary for {$target->name}",
        );

        return response()->json(['ok' => true, 'message' => 'Salary updated']);
    }

    // ── Get salary history for an employee ──

    public function salaryHistory(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (! $this->canSeeSalary($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $revisions = SalaryRevision::where('user_id', $userId)
            ->orderByDesc('effective_date')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'effective_date' => $r->effective_date->format('Y-m-d'),
                'previous_monthly_salary' => $r->previous_monthly_salary,
                'new_monthly_salary' => $r->new_monthly_salary,
                'previous_annual_ctc' => $r->previous_annual_ctc,
                'new_annual_ctc' => $r->new_annual_ctc,
                'revision_reason' => $r->revision_reason,
                'revised_by' => $r->revisedBy?->name,
                'created_at' => $r->created_at?->format('Y-m-d'),
            ]);

        return response()->json(['ok' => true, 'revisions' => $revisions]);
    }

    // ── Promotion / Increment ──

    private function handlePromote(Request $request, $authUser): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $target = User::find($id);
        if (! $target) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $request->validate([
            'effective_date' => 'required|date',
            'promotion_type' => 'required|in:promotion,role_change,department_transfer,increment',
        ]);

        $promotionData = [
            'user_id' => $target->id,
            'effective_date' => $request->input('effective_date'),
            'old_designation' => $target->designation,
            'new_designation' => $request->input('new_designation', $target->designation),
            'old_role_id' => $target->role_id,
            'new_role_id' => $request->input('new_role_id', $target->role_id),
            'old_department_id' => $target->department_id,
            'new_department_id' => $request->input('new_department_id', $target->department_id),
            'promotion_type' => $request->input('promotion_type'),
            'notes' => $request->input('notes'),
            'promoted_by' => $authUser->id,
        ];

        // If salary is changing, create a salary revision too
        $salaryRevisionId = null;
        $newMonthlySalary = $request->input('monthly_salary');
        $newAnnualCtc = $request->input('annual_ctc');
        if ($newMonthlySalary || $newAnnualCtc) {
            $revision = SalaryRevision::create([
                'user_id' => $target->id,
                'effective_date' => $request->input('effective_date'),
                'previous_monthly_salary' => $target->monthly_salary,
                'new_monthly_salary' => $newMonthlySalary,
                'previous_annual_ctc' => $target->annual_ctc,
                'new_annual_ctc' => $newAnnualCtc,
                'revision_reason' => ucfirst($request->input('promotion_type', 'promotion')),
                'revised_by' => $authUser->id,
            ]);
            $salaryRevisionId = $revision->id;
        }

        $promotionData['salary_revision_id'] = $salaryRevisionId;
        Promotion::create($promotionData);

        // Update user fields
        $updates = [];
        if ($request->filled('new_designation')) {
            $updates['designation'] = $request->input('new_designation');
        }
        if ($request->filled('new_role_id')) {
            $updates['role_id'] = $request->input('new_role_id');
        }
        if ($request->filled('new_department_id')) {
            $updates['department_id'] = $request->input('new_department_id');
        }
        if ($newMonthlySalary) {
            $updates['monthly_salary'] = $newMonthlySalary;
        }
        if ($newAnnualCtc) {
            $updates['annual_ctc'] = $newAnnualCtc;
        }
        if (! empty($updates)) {
            $target->update($updates);
        }

        ActivityLogService::log(
            $authUser->id,
            'employee_promotion',
            "{$authUser->name} processed {$request->input('promotion_type')} for {$target->name}",
        );

        return response()->json(['ok' => true, 'message' => ucfirst($request->input('promotion_type')) . ' recorded successfully']);
    }

    // ── Promotion history ──

    public function promotionHistory(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (! $this->canViewTeam($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $promotions = Promotion::where('user_id', $userId)
            ->with(['oldRole:id,name', 'newRole:id,name', 'promotedBy:id,name', 'salaryRevision'])
            ->orderByDesc('effective_date')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'effective_date' => $p->effective_date->format('Y-m-d'),
                'promotion_type' => $p->promotion_type,
                'old_designation' => $p->old_designation,
                'new_designation' => $p->new_designation,
                'old_role' => $p->oldRole?->name,
                'new_role' => $p->newRole?->name,
                'salary_change' => $p->salaryRevision ? [
                    'old' => $p->salaryRevision->previous_monthly_salary,
                    'new' => $p->salaryRevision->new_monthly_salary,
                ] : null,
                'notes' => $p->notes,
                'promoted_by' => $p->promotedBy?->name,
            ]);

        return response()->json(['ok' => true, 'promotions' => $promotions]);
    }

    // ── Document upload ──

    private function handleUploadDoc(Request $request, $authUser): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $field = $request->input('field', '');
        $target = User::find($id);

        if (! $target || ! array_key_exists($field, self::DOC_FIELDS)) {
            return response()->json(['error' => 'Invalid request'], 422);
        }

        $request->validate(['file' => 'required|file|max:10240']);

        $file = $request->file('file');
        $path = $file->store('employee-documents/' . $id, 'public');

        $target->update([$field => $path]);
        $this->deferDriveUpload($target, $field, $path);

        ActivityLogService::log(
            $authUser->id,
            'employee_doc_upload',
            "{$authUser->name} uploaded " . self::DOC_FIELDS[$field] . " for {$target->name}",
        );

        return response()->json([
            'ok' => true,
            'path' => asset('storage/' . $path),
        ]);
    }

    // ── Document delete ──

    private function handleDeleteDoc(Request $request, $authUser): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $field = $request->input('field', '');
        $target = User::find($id);

        if (! $target || ! array_key_exists($field, self::DOC_FIELDS)) {
            return response()->json(['error' => 'Invalid request'], 422);
        }

        $path = $target->$field;
        if ($path) {
            Storage::disk('public')->delete($path);
        }

        $target->update([$field => null]);

        ActivityLogService::log(
            $authUser->id,
            'employee_doc_delete',
            "{$authUser->name} deleted " . self::DOC_FIELDS[$field] . " for {$target->name}",
        );

        return response()->json(['ok' => true]);
    }

    // ── Self-service Profile ──

    public function profile(Request $request): JsonResponse
    {
        $u = $request->user();

        $visible = $this->visibleDocFields($u);
        $required = $this->requiredDocFields($u);
        $docs = [];
        $docsComplete = 0;
        foreach (self::DOC_FIELDS as $field => $label) {
            if (! in_array($field, $visible, true)) {
                continue;
            }
            // Required docs render in their own section (Feature 7) — skip here to avoid duplication.
            if (in_array($field, $required, true)) {
                continue;
            }
            $path = $u->$field;
            $docs[$field] = [
                'label' => $label,
                'path' => $path ? asset('storage/' . $path) : null,
                'uploaded' => (bool) $path, // '0' sentinel from a failed upload is falsy → not "uploaded" (matches the path line above)
            ];
        }

        // Feature 7: required documents by employment type, each with an optional blank
        // template to download → print → fill → scan → upload. Bypasses the visibility
        // exclusions above (e.g. Form 11 is hidden from the intern grid but required here).
        $requiredDocs = [];
        foreach ($required as $field) {
            $path = $u->$field;
            $tpl = self::REQUIRED_DOC_TEMPLATES[$field] ?? null;
            $requiredDocs[] = [
                'field' => $field,
                'label' => self::DOC_FIELDS[$field] ?? $field,
                'uploaded' => (bool) $path,
                'path' => $path ? asset('storage/' . $path) : null,
                // The NDA template is generated live, auto-filled from this employee's
                // profile (name, S/O / D/O parent, address, today's date) — see NdaController.
                // Every other required doc is a static blank PDF under public/downloads/.
                'template_url' => $field === 'nda_path'
                    ? url('/api/documents/nda')
                    : (($tpl && file_exists(public_path('downloads/' . $tpl))) ? asset('downloads/' . $tpl) : null),
            ];
        }
        foreach (array_intersect(self::KEY_DOCS, $visible) as $kd) {
            if ($u->$kd) {
                $docsComplete++;
            }
        }

        $isIntern = $u->employment_type === 'internship' || $u->employee_status === 'intern';

        return response()->json([
            'ok' => true,
            'profile' => [
                'id' => $u->id,
                'name' => $u->name,
                'parent_name' => $u->parent_name,
                'email' => $u->email,
                'profile_photo' => $u->profile_photo_url,
                'role' => $u->roleRelation?->name ?? '',
                'designation' => $u->designationRelation?->title ?? $u->designation,
                'gender' => $u->gender,
                'blood_group' => $u->blood_group,
                'marital_status' => $u->marital_status,
                // Owner sees their own full DOB (incl. year). Team-visible
                // surfaces (index()) only ever get month/day — see below.
                'date_of_birth' => $u->date_of_birth?->format('Y-m-d'),
                'qualification' => $u->qualification,
                'current_address' => $u->current_address,
                'permanent_address' => $u->permanent_address,
                'nominee' => [
                    'name' => $u->nominee_name,
                    'age' => $u->nominee_age,
                    'dob' => $u->nominee_dob?->format('Y-m-d'),
                    'relation' => $u->nominee_relation,
                ],
                'pf' => [
                    'applicable' => (bool) $u->pf_applicable,
                    'uan' => $u->pf_uan,
                    'editable' => ! $isIntern,
                    'is_intern' => $isIntern,
                ],
                'insurance' => [
                    'applicable' => (bool) $u->insurance_applicable,
                    'number' => $u->insurance_number,
                ],
                'employment_type' => $u->employment_type,
                'employee_status' => $u->employee_status,
                'personal_mobile' => $u->personal_mobile,
                'personal_email' => $u->personal_email,
                'emergency_contact_name' => $u->emergency_contact_name,
                'emergency_contact_number' => $u->emergency_contact_number,
                'bank' => [
                    'account_holder_name' => $u->bank_account_holder_name,
                    'account_number' => $u->bank_account_number,
                    'ifsc_code' => $u->bank_ifsc_code,
                    'passbook_path' => $u->bank_passbook_path ? asset('storage/' . $u->bank_passbook_path) : null,
                    'has_passbook' => (bool) $u->bank_passbook_path,
                ],
                'experienced' => (bool) $u->experienced,
                'joined_as' => $u->joined_as,
                // Format to Y-m-d so the profile page shows "2025-12-01" rather
                // than the raw Carbon ISO timestamp ("2025-12-01T00:00:00.000000Z").
                'joining_date' => $u->joining_date?->format('Y-m-d'),
                'reporting_manager' => $u->reportingManager?->name,
                'projects' => $this->projectsLabel($u),
                'department' => $u->department?->name,
                'documents' => $docs,
                'required_docs' => $requiredDocs,
                'docs_complete' => $docsComplete,
                'docs_total' => count(array_intersect(self::KEY_DOCS, $visible)),
                'can_edit_docs' => in_array($u->role, self::ALLOWED_ROLES),
                'can_edit_doj' => in_array($u->id, self::DOJ_SELF_EDIT_USER_IDS, true),
                'meow_sound_enabled' => (bool) $u->meow_sound_enabled,
            ],
        ]);
    }

    /**
     * Self-service avatar upload. Saves to the dedicated profile_photo_path
     * (never touches the official HR passport photo) on the public disk,
     * replacing any previous one. Image only, <= 4 MB.
     */
    public function uploadProfilePhoto(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:4096',
        ]);

        $old = $user->profile_photo_path;

        $path = $request->file('photo')->store('profile-photos/' . $user->id, 'public');
        if (! $path) {
            return response()->json(['error' => 'Upload failed. Please try again.'], 500);
        }

        $user->update(['profile_photo_path' => $path]);

        // Drop the previous photo only after the new one is persisted.
        if ($old && $old !== $path && $old !== '0' && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }

        ActivityLogService::log($user->id, 'profile_photo_update', "{$user->name} updated their profile photo");

        return response()->json([
            'ok' => true,
            'profile_photo' => $user->fresh()->profile_photo_url,
        ]);
    }

    /**
     * Remove the self-uploaded avatar. Display falls back to the HR passport
     * photo (if any) or the initials avatar. Never deletes the passport photo.
     */
    public function removeProfilePhoto(Request $request): JsonResponse
    {
        $user = $request->user();

        $old = $user->profile_photo_path;
        if ($old && $old !== '0' && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }
        $user->update(['profile_photo_path' => null]);

        ActivityLogService::log($user->id, 'profile_photo_remove', "{$user->name} removed their profile photo");

        return response()->json([
            'ok' => true,
            'profile_photo' => $user->fresh()->profile_photo_url,
        ]);
    }

    /**
     * Feature 5: push the employee's master-record fields to the HR Google Sheet
     * AFTER the response is sent (non-blocking, via a terminating callback). No-op
     * when the service account isn't configured. Re-fetches the user inside the
     * callback so the just-saved values are picked up.
     */
    private function deferSheetSync(User $user): void
    {
        $svc = app(\App\Services\GoogleSheetsService::class);
        if (! $svc->isConfigured()) {
            return;
        }
        $id = $user->id;
        app()->terminating(function () use ($id, $svc) {
            $u = User::find($id);
            if ($u) {
                $svc->upsertEmployeeRow($u);
            }
        });
    }

    /**
     * Feature 6: upload a just-stored document to the employee's Drive folder AFTER
     * the response is sent (non-blocking). No-op when the service account isn't
     * configured. $storedPath is the public-disk relative path.
     */
    private function deferDriveUpload(User $target, string $field, string $storedPath): void
    {
        // Feature 6: only the ID documents (Aadhaar/PAN/Photo) sync to Drive.
        if (! in_array($field, self::DRIVE_SYNC_FIELDS, true)) {
            return;
        }
        $svc = app(\App\Services\GoogleDriveService::class);
        if (! $svc->isConfigured()) {
            return;
        }
        $userId = $target->id;
        $label = self::DOC_FIELDS[$field] ?? $field;
        $abs = Storage::disk('public')->path($storedPath);
        app()->terminating(function () use ($svc, $userId, $abs, $label) {
            $user = User::find($userId);
            if ($user) {
                $svc->uploadDocument($user, $abs, $label);
            }
        });
    }

    public function profileStore(Request $request): JsonResponse
    {
        $user = $request->user();
        $action = $request->input('action', 'update');

        if ($action === 'update') {
            $user->update($request->only([
                'personal_mobile', 'personal_email',
                'emergency_contact_name', 'emergency_contact_number',
            ]));

            return response()->json(['ok' => true]);
        }

        if ($action === 'update_doj') {
            // Self-edit of joining_date is restricted to the IDs in
            // DOJ_SELF_EDIT_USER_IDS. Everyone else still has DOJ managed
            // for them via Team → Edit Details.
            if (! in_array($user->id, self::DOJ_SELF_EDIT_USER_IDS, true)) {
                return response()->json(['error' => 'Not allowed to edit Joining Date.'], 403);
            }

            $request->validate(['joining_date' => 'required|date']);

            $user->update(['joining_date' => $request->input('joining_date')]);
            ActivityLogService::log($user->id, 'doj_self_update', "{$user->name} updated their joining date to {$request->input('joining_date')}");

            return response()->json(['ok' => true]);
        }

        if ($action === 'update_personal_info') {
            $request->validate([
                'blood_group' => 'nullable|string|max:5',
                'marital_status' => 'nullable|in:unmarried,married,divorced',
                'qualification' => 'nullable|string|max:150',
                'parent_name' => 'nullable|string|max:150',
                'gender' => 'nullable|in:male,female,other',
                'current_address' => 'nullable|string|max:1000',
                'permanent_address' => 'nullable|string|max:1000',
                'personal_mobile' => 'nullable|string|max:20',
                'personal_email' => 'nullable|email|max:255',
                'emergency_contact_name' => 'nullable|string|max:255',
                'emergency_contact_number' => 'nullable|string|max:20',
                'nominee_name' => 'nullable|string|max:150',
                'nominee_age' => 'nullable|integer|min:0|max:120',
                'nominee_dob' => 'nullable|date',
                'nominee_relation' => 'nullable|string|max:50',
                'date_of_birth' => 'nullable|date|before:today',
            ]);

            // Only update fields actually present in the request so the two save
            // buttons (Personal Details vs Nominee Details) don't null each other's data.
            $payload = $request->only([
                'blood_group', 'marital_status', 'qualification',
                'parent_name', 'gender',
                'current_address', 'permanent_address',
                'personal_mobile', 'personal_email',
                'emergency_contact_name', 'emergency_contact_number',
                'nominee_name', 'nominee_age', 'nominee_dob', 'nominee_relation',
                'date_of_birth',
            ]);
            if (! empty($payload)) {
                $user->update($payload);
            }

            ActivityLogService::log($user->id, 'personal_info_update', "{$user->name} updated personal details");
            $this->deferSheetSync($user);

            return response()->json(['ok' => true]);
        }

        if ($action === 'update_pf') {
            $isIntern = $user->employment_type === 'internship' || $user->employee_status === 'intern';
            if ($isIntern) {
                return response()->json(['error' => 'PF is not applicable for interns.'], 422);
            }

            $request->validate([
                'pf_applicable' => 'required|boolean',
                'pf_uan' => 'nullable|string|max:30|regex:/^\d{8,15}$/',
            ], [
                'pf_uan.regex' => 'PF UAN must be 8–15 digits.',
            ]);

            $applicable = (bool) $request->input('pf_applicable');
            $user->update([
                'pf_applicable' => $applicable,
                'pf_uan' => $applicable ? $request->input('pf_uan') : null,
            ]);

            ActivityLogService::log($user->id, 'pf_update', "{$user->name} updated PF details");

            return response()->json(['ok' => true]);
        }

        if ($action === 'update_insurance') {
            $request->validate([
                'insurance_applicable' => 'required|boolean',
                'insurance_number' => 'nullable|string|max:60',
            ]);

            $applicable = (bool) $request->input('insurance_applicable');
            $user->update([
                'insurance_applicable' => $applicable,
                'insurance_number' => $applicable ? $request->input('insurance_number') : null,
            ]);

            ActivityLogService::log($user->id, 'insurance_update', "{$user->name} updated insurance details");

            return response()->json(['ok' => true]);
        }

        if ($action === 'update_bank') {
            $request->validate([
                'bank_account_holder_name' => 'nullable|string|max:150',
                'bank_account_number' => 'nullable|string|max:30',
                'bank_ifsc_code' => 'nullable|string|max:20|regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/',
            ], [
                'bank_ifsc_code.regex' => 'IFSC code must be 11 characters, e.g. HDFC0001234.',
            ]);

            $user->update([
                'bank_account_holder_name' => $request->input('bank_account_holder_name'),
                'bank_account_number' => $request->input('bank_account_number'),
                'bank_ifsc_code' => $request->input('bank_ifsc_code') ? strtoupper($request->input('bank_ifsc_code')) : null,
            ]);

            ActivityLogService::log($user->id, 'bank_details_update', "{$user->name} updated bank details");
            $this->deferSheetSync($user);

            return response()->json(['ok' => true]);
        }

        if ($action === 'upload_bank_passbook') {
            $request->validate(['file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240']);

            if ($user->bank_passbook_path) {
                Storage::disk('public')->delete($user->bank_passbook_path);
            }

            $path = $request->file('file')->store('employee-documents/' . $user->id, 'public');
            $user->update(['bank_passbook_path' => $path]);

            ActivityLogService::log($user->id, 'bank_passbook_upload', "{$user->name} uploaded bank passbook");

            return response()->json(['ok' => true, 'path' => asset('storage/' . $path)]);
        }

        if ($action === 'delete_bank_passbook') {
            if ($user->bank_passbook_path) {
                Storage::disk('public')->delete($user->bank_passbook_path);
            }
            $user->update(['bank_passbook_path' => null]);

            ActivityLogService::log($user->id, 'bank_passbook_delete', "{$user->name} deleted bank passbook");

            return response()->json(['ok' => true]);
        }

        if ($action === 'update_preferences') {
            $user->update([
                'meow_sound_enabled' => (bool) $request->input('meow_sound_enabled', false),
            ]);

            return response()->json(['ok' => true]);
        }

        if ($action === 'update_joined_as') {
            $request->validate([
                'joined_as' => 'required|in:intern,fresher,experienced',
            ]);
            $value = $request->input('joined_as');
            $user->update(['joined_as' => $value]);
            ActivityLogService::log($user->id, 'joined_as_update', "{$user->name} set 'joined as' to {$value}");

            return response()->json(['ok' => true]);
        }

        $isPrivileged = in_array($user->role, self::ALLOWED_ROLES);

        if ($action === 'upload_doc') {
            $field = $request->input('field', '');
            if (! array_key_exists($field, self::DOC_FIELDS)) {
                return response()->json(['error' => 'Invalid field'], 422);
            }
            if ($user->$field && ! $isPrivileged) {
                return response()->json(['error' => 'Document already uploaded. Contact HR to make changes.'], 403);
            }
            $request->validate(['file' => 'required|file|max:10240']);
            $file = $request->file('file');
            $path = $file->store('employee-documents/' . $user->id, 'public');
            $user->update([$field => $path]);
            $this->deferDriveUpload($user, $field, $path);

            return response()->json(['ok' => true, 'path' => asset('storage/' . $path)]);
        }

        if ($action === 'delete_doc') {
            $field = $request->input('field', '');
            if (! array_key_exists($field, self::DOC_FIELDS)) {
                return response()->json(['error' => 'Invalid field'], 422);
            }
            if (! $isPrivileged) {
                return response()->json(['error' => 'Document frozen. Contact HR to make changes.'], 403);
            }
            if ($user->$field) {
                Storage::disk('public')->delete($user->$field);
            }
            $user->update([$field => null]);

            return response()->json(['ok' => true]);
        }

        return response()->json(['error' => 'Unknown action'], 422);
    }
}
