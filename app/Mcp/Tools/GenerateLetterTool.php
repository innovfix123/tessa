<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\IssuedLetter;
use App\Models\Role;
use App\Models\User;
use App\Services\GoogleUserService;
use App\Services\LetterSalaryCalculator;
use App\Services\LetterTemplateService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Generate + issue an HR letter (offer / appointment / probation) exactly the
 * way the Tessa Letters composer does — Claude drives the same POST /letters
 * the portal uses, running as the signed-in user, so the PDF, share token and
 * audit attribution are identical to a letter Akshara makes by hand.
 *
 * The composer's field defaults live in the front-end (letters.js); the server
 * render() does NOT apply them, so we re-apply them here. For full-time
 * offer/appointment letters we compute the Annexure-I salary breakup from the
 * annual CTC via LetterSalaryCalculator — the caller only states the CTC.
 */
class GenerateLetterTool extends Tool
{
    /** Same write gate as LetterController::ALLOWED_ROLES. */
    public function allowedRoleSlugs(): ?array
    {
        return [
            Role::SLUG_CEO,
            Role::SLUG_COO,
            Role::SLUG_CFO,
            Role::SLUG_HR,
            Role::SLUG_HR_OPERATIONS,
            Role::SLUG_BUSINESS_ANALYST,
        ];
    }

    public function name(): string
    {
        return 'generate_letter';
    }

    public function description(): string
    {
        return 'Generate and issue an HR letter — offer / appointment / probation (candidate-facing) or relieving / '
            .'experience (for a departing employee) — exactly as the Tessa Letters '
            .'composer does: renders the official PDF, stores it, and returns a shareable link — all attributed to you. '
            .'employee_category drives the compensation: "fulltime" needs annual_ctc (the Annexure-I salary breakup is '
            .'computed automatically), "intern" needs stipend_monthly, "freelancer" needs hourly_rate. Probation letters '
            .'exist only for intern + fulltime and need probation_end_date. For a departing EMPLOYEE pass letter_type '
            .'relieving or experience: these ignore employee_category and instead need gender (sets he/she/his/her) and '
            .'last_working_date — here start_date is the date of joining; the experience certificate also takes '
            .'tenure_summary (duties) and conduct_summary (strengths), written for the role. ALWAYS write a role_overview and '
            .'responsibilities suited to role_title — if the user did not dictate them, compose professional content '
            .'yourself; the letter looks empty without them. Sensible defaults are applied for work_mode, working '
            .'days/hours, internship duration, notice period and probation duration when omitted. All dates are '
            .'YYYY-MM-DD; letter_date defaults to today (IST). It then drafts the covering email — with the PDF attached — '
            .'straight into your Gmail Drafts so you only review and hit Send (needs your Google account connected with the '
            .'Gmail create-drafts permission; otherwise it still returns the letter plus a ready-to-send email to copy). '
            .'Set draft_email=false to skip the Gmail draft.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'letter_type' => ['type' => 'string', 'enum' => ['offer', 'appointment', 'probation', 'relieving', 'experience'], 'description' => 'Which letter to issue. offer/appointment/probation = candidate-facing; relieving/experience = for a departing employee.'],
                'employee_category' => ['type' => 'string', 'enum' => ['fulltime', 'intern', 'freelancer'], 'description' => 'Engagement type. probation has no freelancer variant. Ignored for relieving/experience (pass "fulltime").'],
                'recipient_name' => ['type' => 'string', 'description' => 'Candidate full name.'],
                'recipient_email' => ['type' => 'string', 'description' => 'Candidate email — the letter is addressed here and the email draft will be sent here.'],
                'recipient_phone' => ['type' => 'string', 'description' => 'Optional, for WhatsApp e.g. +9198XXXXXXXX.'],
                'role_title' => ['type' => 'string', 'description' => 'Role / position, e.g. "Backend Engineer".'],
                'department' => ['type' => 'string', 'description' => 'Optional department.'],
                'start_date' => ['type' => 'string', 'description' => 'Joining / start date, YYYY-MM-DD.'],
                'letter_date' => ['type' => 'string', 'description' => 'Letter date YYYY-MM-DD. Defaults to today (IST).'],
                'work_mode' => ['type' => 'string', 'enum' => ['Work From Office', 'Work From Home', 'Hybrid'], 'description' => 'Defaults to Work From Office.'],
                'role_overview' => ['type' => 'string', 'description' => 'Short paragraph describing the role. Strongly recommended — write it for the role_title.'],
                'responsibilities' => ['type' => 'string', 'description' => 'Key responsibilities, ONE PER LINE (newline-separated). Strongly recommended.'],

                // Full-time (offer / appointment)
                'annual_ctc' => ['type' => 'number', 'description' => 'Annual CTC in ₹ (fulltime offer/appointment). Salary breakup is auto-computed.'],
                'notice_period' => ['type' => 'string', 'description' => 'Fulltime offer/appointment + fulltime probation. Defaults to "30 Days".'],

                // Intern (offer / appointment / probation)
                'stipend_monthly' => ['type' => 'number', 'description' => 'Monthly stipend in ₹ (intern offer/appointment and intern probation).'],
                'duration' => ['type' => 'string', 'description' => 'Internship duration (intern offer/appointment). Defaults to "3 Months".'],
                'position_designation' => ['type' => 'string', 'description' => 'Optional intern designation, e.g. "AI Intern".'],
                'ai_responsibilities' => ['type' => 'string', 'description' => 'Optional AI responsible-use clause for AI interns.'],
                'working_days' => ['type' => 'string', 'description' => 'Intern / probation. Defaults to "Monday to Friday".'],
                'working_hours' => ['type' => 'string', 'description' => 'Intern / probation. Defaults to "10:00 AM to 6:30 PM".'],

                // Freelancer (offer / appointment)
                'hourly_rate' => ['type' => 'number', 'description' => 'Hourly rate in ₹ (freelancer offer/appointment).'],
                'payment_cadence' => ['type' => 'string', 'enum' => ['weekly', 'bi-weekly', 'monthly'], 'description' => 'Freelancer. Defaults to "weekly".'],

                // Probation (intern / fulltime)
                'probation_end_date' => ['type' => 'string', 'description' => 'Probation end date YYYY-MM-DD (required for probation letters).'],
                'probation_duration' => ['type' => 'string', 'description' => 'Probation duration. Defaults to "15 Days" (intern) / "1 Month (30 Days)" (fulltime).'],
                'confirmation_review' => ['type' => 'string', 'description' => 'Optional confirmation-review note (probation).'],

                // Offboarding (relieving / experience) — for a departing employee
                'gender' => ['type' => 'string', 'enum' => ['Female', 'Male'], 'description' => 'Required for relieving/experience — sets he/she/his/her + Mr./Ms.'],
                'last_working_date' => ['type' => 'string', 'description' => 'Last working / relieving date YYYY-MM-DD (required for relieving/experience).'],
                'tenure_summary' => ['type' => 'string', 'description' => 'Experience certificate: the duties phrase completing "…was actively involved in ___". Write it for the role.'],
                'conduct_summary' => ['type' => 'string', 'description' => 'Experience certificate: the strengths phrase completing "…demonstrated ___ towards their responsibilities".'],

                'draft_email' => ['type' => 'boolean', 'description' => 'Also create a Gmail draft (PDF attached) in your mailbox for the recipient. Default true.'],
            ],
            'required' => ['letter_type', 'employee_category', 'recipient_name', 'recipient_email', 'role_title', 'start_date'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        $letterType = (string) ($args['letter_type'] ?? '');
        $category = (string) ($args['employee_category'] ?? '');
        $today = Carbon::today('Asia/Kolkata')->toDateString();

        if (in_array($letterType, ['relieving', 'experience'], true)) {
            // Offboarding letters are for a departing employee and don't vary by
            // engagement type — pin the category and assemble their own fields.
            $category = 'fulltime';
            $fields = $this->offboardingLetterFields($letterType, $args, $today);
        } else {
            // Mirror the variants() matrix: there is no freelancer probation letter.
            if ($letterType === 'probation' && $category === 'freelancer') {
                throw new ToolException('No freelancer probation letter exists. Use intern or fulltime for probation, or pick offer/appointment for a freelancer.', 422);
            }

            $this->guardCompensation($letterType, $category, $args);

            // Common fields + the defaults the composer pre-fills client-side
            // (render() does not apply variant 'default' values server-side).
            $fields = [
                'recipient_name' => $this->str($args, 'recipient_name'),
                'recipient_email' => $this->str($args, 'recipient_email'),
                'recipient_phone' => $this->str($args, 'recipient_phone') ?: null,
                'role_title' => $this->str($args, 'role_title'),
                'department' => $this->str($args, 'department') ?: null,
                'start_date' => $this->str($args, 'start_date'),
                'letter_date' => $this->str($args, 'letter_date') ?: $today,
                'work_mode' => $this->str($args, 'work_mode') ?: 'Work From Office',
                'role_overview' => $this->str($args, 'role_overview'),
                'responsibilities' => $this->str($args, 'responsibilities'),
            ];

            $fields += $this->categoryFields($letterType, $category, $args);
        }

        $payload = [
            'letter_type' => $letterType,
            'employee_category' => $category,
            'fields' => $fields,
        ];

        // Runs the exact portal endpoint as the signed-in user: RBAC, PDF
        // generation, share token and issued_by attribution are all identical.
        $result = ApiSubRequest::post('/letters', $payload, $user);
        $letter = is_array($result['letter'] ?? null) ? $result['letter'] : [];

        $subject = $this->emailSubject($letterType, $category, $fields);
        $body = $this->emailBody($letterType, $fields, $user);

        // Best-effort: drop a ready-to-send draft (PDF attached) into the user's
        // own Gmail. Never let a drafting hiccup fail the already-issued letter.
        $draft = ((bool) ($args['draft_email'] ?? true))
            ? $this->tryDraftEmail($user, $letter['id'] ?? null, $letterType, $fields, $subject, $body)
            : ['drafted' => false, 'reason' => 'Skipped — draft_email was false.'];

        return [
            'ok' => true,
            'letter' => $letter,
            'summary' => $this->variantLabel($letterType, $category)
                .' issued for '.$fields['recipient_name'].' — '.$fields['role_title'].'.',
            'email_draft' => $draft,
            'suggested_email' => [
                'to' => $fields['recipient_email'],
                'subject' => $subject,
                'body' => $body,
            ],
        ];
    }

    /**
     * Best-effort Gmail draft (PDF attached) in the acting user's mailbox.
     * Degrades gracefully with an actionable reason — the letter is already issued.
     */
    private function tryDraftEmail(User $user, ?int $letterId, string $letterType, array $fields, string $subject, string $body): array
    {
        if (! $letterId) {
            return ['drafted' => false, 'reason' => 'Letter id missing — could not attach the PDF.'];
        }
        if (! $user->google_access_token) {
            return [
                'drafted' => false,
                'reason' => 'Your Google account is not connected, so I could not draft the email. '
                    .'Connect it (Settings → Connect Google, allow the Gmail "create drafts" permission) and ask me to draft it. The letter is ready.',
            ];
        }

        try {
            $letter = IssuedLetter::find($letterId);
            $disk = Storage::disk('public');
            if (! $letter || ! $letter->pdf_path || ! $disk->exists($letter->pdf_path)) {
                return ['drafted' => false, 'reason' => 'The letter PDF could not be located to attach.'];
            }

            $filename = $this->attachmentName($letterType, $fields['recipient_name']);
            $draft = GoogleUserService::forUser($user)->createDraft(
                $fields['recipient_email'],
                $subject,
                $body,
                $disk->get($letter->pdf_path),
                $filename,
            );

            return [
                'drafted' => true,
                'draft_id' => $draft['id'] ?? null,
                'to' => $fields['recipient_email'],
                'attachment' => $filename,
                'open_in_gmail' => 'https://mail.google.com/mail/u/0/#drafts',
                'message' => 'Draft created in your Gmail with the PDF attached — open Drafts, review, and hit Send.',
            ];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $hint = (stripos($msg, 'scope') !== false || stripos($msg, 'insufficient') !== false)
                ? ' Reconnect Google (Disconnect + Connect Google) to grant the Gmail "create drafts" permission, then ask me to draft it.'
                : ' The letter is ready — you can send it manually using suggested_email below.';

            return ['drafted' => false, 'reason' => $msg.$hint];
        }
    }

    private function attachmentName(string $letterType, string $recipientName): string
    {
        $who = trim(str_replace(['"', '/', '\\', "\r", "\n"], ' ', $recipientName));

        return ucfirst($letterType).' Letter'.($who !== '' ? ' - '.$who : '').'.pdf';
    }

    /** Enforce the composer's category-specific required compensation fields. */
    private function guardCompensation(string $letterType, string $category, array $args): void
    {
        if (in_array($letterType, ['offer', 'appointment'], true)) {
            $need = match ($category) {
                'fulltime' => ['annual_ctc', 'annual CTC'],
                'intern' => ['stipend_monthly', 'monthly stipend'],
                'freelancer' => ['hourly_rate', 'hourly rate'],
                default => null,
            };
            if ($need && empty($args[$need[0]])) {
                throw new ToolException("{$need[0]} is required for a {$category} {$letterType} letter (the {$need[1]}).", 422);
            }
        }

        if ($letterType === 'probation') {
            if ($category === 'intern' && empty($args['stipend_monthly'])) {
                throw new ToolException('stipend_monthly is required for an intern probation letter (the monthly stipend).', 422);
            }
            if (empty($args['probation_end_date'])) {
                throw new ToolException('probation_end_date (YYYY-MM-DD) is required for a probation letter.', 422);
            }
        }
    }

    /** Assemble the category-specific fields + defaults the blade expects. */
    private function categoryFields(string $letterType, string $category, array $args): array
    {
        if ($letterType === 'probation') {
            $out = [
                'probation_end_date' => $this->str($args, 'probation_end_date'),
                'confirmation_review' => $this->str($args, 'confirmation_review'),
            ];
            if ($category === 'intern') {
                return $out + [
                    'stipend_monthly' => $this->money($args, 'stipend_monthly'),
                    'working_days' => $this->str($args, 'working_days') ?: 'Monday to Friday',
                    'working_hours' => $this->str($args, 'working_hours') ?: '10:00 AM to 6:30 PM',
                    'probation_duration' => $this->str($args, 'probation_duration') ?: '15 Days',
                ];
            }

            return $out + [
                'notice_period' => $this->str($args, 'notice_period') ?: '30 Days',
                'probation_duration' => $this->str($args, 'probation_duration') ?: '1 Month (30 Days)',
            ];
        }

        // offer / appointment
        if ($category === 'fulltime') {
            $ctc = $this->money($args, 'annual_ctc');

            // Compute the Annexure-I breakup so the caller only states the CTC —
            // mirrors what the /letters/preview-breakup endpoint returns.
            return ['notice_period' => $this->str($args, 'notice_period') ?: '30 Days']
                + app(LetterSalaryCalculator::class)->breakup((float) $ctc, 'fulltime');
        }

        if ($category === 'intern') {
            return [
                'stipend_monthly' => $this->money($args, 'stipend_monthly'),
                'duration' => $this->str($args, 'duration') ?: '3 Months',
                'working_days' => $this->str($args, 'working_days') ?: 'Monday to Friday',
                'working_hours' => $this->str($args, 'working_hours') ?: '10:00 AM to 6:30 PM',
                'position_designation' => $this->str($args, 'position_designation'),
                'ai_responsibilities' => $this->str($args, 'ai_responsibilities'),
            ];
        }

        // freelancer
        return [
            'hourly_rate' => $this->money($args, 'hourly_rate'),
            'payment_cadence' => $this->str($args, 'payment_cadence') ?: 'weekly',
        ];
    }

    /** Assemble + guard the fields for a relieving / experience letter. */
    private function offboardingLetterFields(string $letterType, array $args, string $today): array
    {
        if (empty($args['gender'])) {
            throw new ToolException('gender (Male or Female) is required for a relieving/experience letter — it sets the pronouns.', 422);
        }
        if (empty($args['last_working_date'])) {
            throw new ToolException('last_working_date (YYYY-MM-DD) is required for a relieving/experience letter.', 422);
        }

        $fields = [
            'recipient_name' => $this->str($args, 'recipient_name'),
            'recipient_email' => $this->str($args, 'recipient_email'),
            'recipient_phone' => $this->str($args, 'recipient_phone') ?: null,
            'gender' => $this->str($args, 'gender'),
            'role_title' => $this->str($args, 'role_title'),
            'department' => $this->str($args, 'department') ?: null,
            'start_date' => $this->str($args, 'start_date'),
            'last_working_date' => $this->str($args, 'last_working_date'),
            'letter_date' => $this->str($args, 'letter_date') ?: $today,
        ];

        if ($letterType === 'experience') {
            // Mirror the composer's defaults so the certificate is never sparse
            // when the caller omits the prose.
            $fields['tenure_summary'] = $this->str($args, 'tenure_summary')
                ?: 'fulfilling the core responsibilities of the role, collaborating with internal teams, and supporting day-to-day operations to a high standard';
            $fields['conduct_summary'] = $this->str($args, 'conduct_summary')
                ?: 'strong professional skills, attention to detail, and a proactive attitude';
        }

        return $fields;
    }

    private function variantLabel(string $letterType, string $category): string
    {
        $variant = app(LetterTemplateService::class)->variant($letterType, $category);

        return $variant['label'] ?? ucfirst($letterType).' Letter';
    }

    private function emailSubject(string $letterType, string $category, array $fields): string
    {
        $variant = app(LetterTemplateService::class)->variant($letterType, $category);
        $format = $variant['subject_format'] ?? 'Your :role_title letter from InnovFix';

        return str_replace(':role_title', $fields['role_title'], $format);
    }

    private function emailBody(string $letterType, array $fields, User $user): string
    {
        $first = trim(explode(' ', trim($fields['recipient_name']))[0] ?? $fields['recipient_name']);
        $role = $fields['role_title'];

        $lead = match ($letterType) {
            'offer' => "We are delighted to offer you the position of {$role} at InnovFix. Please find your official offer letter attached.",
            'appointment' => "Please find attached your appointment letter for the position of {$role} at InnovFix.",
            'probation' => "Please find attached your probation letter for the position of {$role} at InnovFix.",
            'relieving' => "Please find attached your relieving letter from InnovFix. Thank you for your contributions during your time with us.",
            'experience' => "Please find attached your experience certificate from InnovFix. Thank you for your contributions during your time with us.",
            default => "Please find your letter attached.",
        };

        $close = match ($letterType) {
            'offer' => "Kindly review the attached letter and reply to this email confirming your acceptance. We look forward to welcoming you on board.",
            'relieving', 'experience' => "It was a pleasure having you with us. Do reach out if you need anything further, and we wish you the very best for the future.",
            default => "Kindly review the attached letter and reach out if you have any questions.",
        };

        return "Dear {$first},\n\n{$lead}\n\n{$close}\n\nWarm regards,\n{$user->name}\nInnovFix";
    }

    private function str(array $args, string $key): string
    {
        $v = $args[$key] ?? '';

        return is_string($v) ? trim($v) : (string) $v;
    }

    private function money(array $args, string $key): int
    {
        return (int) round((float) ($args[$key] ?? 0));
    }
}
