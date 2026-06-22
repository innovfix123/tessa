<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class LetterTemplateService
{
    public const LETTER_TYPES = ['offer', 'appointment', 'probation'];
    public const CATEGORIES = ['freelancer', 'intern', 'fulltime'];

    public function variants(): array
    {
        $variants = [
            'offer.freelancer' => [
                'label' => 'Offer Letter — Freelancer',
                'subject_format' => 'Offer for Freelance :role_title',
                'view' => 'letters.offer.freelancer',
                'fields' => $this->commonFields() + $this->freelancerFields(),
            ],
            'offer.intern' => [
                'label' => 'Offer Letter — Intern',
                'subject_format' => 'Offer of :role_title Intern',
                'view' => 'letters.offer.intern',
                'fields' => $this->commonFields() + $this->internFields(),
            ],
            'offer.fulltime' => [
                'label' => 'Offer Letter — Full-time',
                'subject_format' => 'Offer for :role_title',
                'view' => 'letters.offer.fulltime',
                'fields' => $this->commonFields() + $this->fulltimeFields(),
            ],
            'appointment.freelancer' => [
                'label' => 'Appointment Letter — Freelancer',
                'subject_format' => 'Appointment as Freelance :role_title',
                'view' => 'letters.appointment.freelancer',
                'fields' => $this->commonFields() + $this->freelancerFields(),
            ],
            'appointment.intern' => [
                'label' => 'Appointment Letter — Intern',
                'subject_format' => 'Appointment as :role_title Intern',
                'view' => 'letters.appointment.intern',
                'fields' => $this->commonFields() + $this->internFields(),
            ],
            'appointment.fulltime' => [
                'label' => 'Appointment Letter — Full-time',
                'subject_format' => 'Appointment as :role_title',
                'view' => 'letters.appointment.fulltime',
                'fields' => $this->commonFields() + $this->fulltimeFields(),
            ],
            // Probation letters are issued when a new hire's probation STARTS.
            // No freelancer variant — freelancers have no probation in Tessa.
            'probation.intern' => [
                'label' => 'Probation Letter — Intern',
                'subject_format' => 'Probation Period — :role_title Intern',
                'view' => 'letters.probation.intern',
                'fields' => $this->commonFields() + $this->internProbationFields(),
            ],
            'probation.fulltime' => [
                'label' => 'Probation Letter — Full-time',
                'subject_format' => 'Probation Period — :role_title',
                'view' => 'letters.probation.fulltime',
                'fields' => $this->commonFields() + $this->fulltimeProbationFields(),
            ],
        ];

        // Relieving + Experience letters are for departing employees and don't
        // vary by engagement type — register them for every category (same body)
        // so whatever category the composer/MCP sends resolves. The UI hides the
        // category picker for these and defaults to full-time.
        foreach (self::CATEGORIES as $cat) {
            $variants['relieving.'.$cat] = [
                'label' => 'Relieving Letter'.($cat === 'fulltime' ? '' : ' — '.ucfirst($cat)),
                'subject_format' => 'Relieving Letter — :role_title',
                'view' => 'letters.relieving.default',
                'fields' => $this->offboardingFields(),
            ];
            $variants['experience.'.$cat] = [
                'label' => 'Experience Certificate'.($cat === 'fulltime' ? '' : ' — '.ucfirst($cat)),
                'subject_format' => 'Experience Certificate — :role_title',
                'view' => 'letters.experience.default',
                'fields' => $this->offboardingFields() + $this->experienceExtraFields(),
            ];
        }

        return $variants;
    }

    public function variantKey(string $letterType, string $category): string
    {
        return $letterType . '.' . $category;
    }

    public function variant(string $letterType, string $category): ?array
    {
        return $this->variants()[$this->variantKey($letterType, $category)] ?? null;
    }

    /**
     * Render the letter HTML (full document) for either preview or PDF.
     */
    public function render(string $letterType, string $category, array $fields, ?string $overrideBodyHtml = null): string
    {
        $variant = $this->variant($letterType, $category);
        if ($variant === null) {
            abort(422, 'Unknown letter variant: ' . $letterType . '.' . $category);
        }

        $fields = $this->normalizeFields($fields, $category);
        $fields['letter_subject'] = $this->buildSubject($variant['subject_format'], $fields);

        if ($overrideBodyHtml !== null && $overrideBodyHtml !== '') {
            $bodyHtml = $this->sanitizeBodyHtml($overrideBodyHtml);
        } else {
            $bodyHtml = View::make($variant['view'], compact('fields'))->render();
        }

        return View::make('letters._layout', [
            'fields' => $fields,
            'bodyHtml' => $bodyHtml,
            'letterType' => $letterType,
            'category' => $category,
            'showAnnexure' => $letterType === 'offer' && $category === 'fulltime',
        ])->render();
    }

    /**
     * Extract just the body section so the Quill editor can seed itself.
     */
    public function renderBodyOnly(string $letterType, string $category, array $fields): string
    {
        $variant = $this->variant($letterType, $category);
        if ($variant === null) {
            abort(422, 'Unknown letter variant: ' . $letterType . '.' . $category);
        }
        $fields = $this->normalizeFields($fields, $category);
        $fields['letter_subject'] = $this->buildSubject($variant['subject_format'], $fields);

        return View::make($variant['view'], compact('fields'))->render();
    }

    public function generatePdf(string $html, string $filename): string
    {
        $pdf = Pdf::loadHTML($html)->setPaper('a4');

        $relativePath = 'letters/' . date('Y/m') . '/' . $filename;
        $disk = Storage::disk('public');
        $disk->put($relativePath, $pdf->output());

        return $relativePath;
    }

    public function newShareToken(): string
    {
        do {
            $token = Str::random(40);
        } while (\App\Models\IssuedLetter::where('share_token', $token)->exists());

        return $token;
    }

    /**
     * Indian-system number to words for INR amounts.
     * 272736 → "Two Lakh Seventy Two Thousand Seven Hundred Thirty Six"
     */
    public function numberToIndianWords(int $amount): string
    {
        if ($amount === 0) {
            return 'Zero';
        }

        $words = [
            0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
            5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
            10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
            14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen',
            17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
            20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty',
            60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety',
        ];

        $twoDigit = function (int $n) use ($words): string {
            if ($n < 20) return $words[$n];
            $tens = intdiv($n, 10) * 10;
            $ones = $n % 10;
            return $words[$tens] . ($ones ? ' ' . $words[$ones] : '');
        };

        $threeDigit = function (int $n) use ($words, $twoDigit): string {
            $hundreds = intdiv($n, 100);
            $rest = $n % 100;
            $out = '';
            if ($hundreds) {
                $out .= $words[$hundreds] . ' Hundred';
                if ($rest) $out .= ' ';
            }
            if ($rest) $out .= $twoDigit($rest);
            return $out;
        };

        $crore = intdiv($amount, 10000000);
        $lakh = intdiv($amount % 10000000, 100000);
        $thousand = intdiv($amount % 100000, 1000);
        $rest = $amount % 1000;

        $parts = [];
        if ($crore) $parts[] = $twoDigit($crore) . ' Crore';
        if ($lakh) $parts[] = $twoDigit($lakh) . ' Lakh';
        if ($thousand) $parts[] = $twoDigit($thousand) . ' Thousand';
        if ($rest) $parts[] = $threeDigit($rest);

        return trim(implode(' ', $parts));
    }

    /**
     * Strip everything Quill-generated that's not safe for Dompdf rendering.
     */
    public function sanitizeBodyHtml(string $html): string
    {
        // Strip script/style/iframe/object/embed completely (including content).
        $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        // Allow a small whitelist of formatting tags.
        $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><span><div><blockquote><a>';
        $html = strip_tags($html, $allowed);
        // Strip event handlers and javascript: URLs.
        $html = preg_replace('# on\w+="[^"]*"#i', '', $html) ?? '';
        $html = preg_replace("# on\w+='[^']*'#i", '', $html) ?? '';
        $html = preg_replace('#javascript:#i', '', $html) ?? '';
        return $html;
    }

    private function commonFields(): array
    {
        return [
            'recipient_name' => ['label' => 'Recipient Name', 'type' => 'text', 'required' => true],
            'recipient_email' => ['label' => 'Recipient Email', 'type' => 'email', 'required' => true],
            'recipient_phone' => ['label' => 'Recipient Phone (for WhatsApp)', 'type' => 'tel', 'required' => false, 'placeholder' => '+919876543210'],
            'role_title' => ['label' => 'Role / Position', 'type' => 'text', 'required' => true],
            'department' => ['label' => 'Department', 'type' => 'text', 'required' => false],
            'start_date' => ['label' => 'Start / Joining Date', 'type' => 'date', 'required' => true],
            'letter_date' => ['label' => 'Letter Date', 'type' => 'date', 'required' => true, 'default_today' => true],
            'work_mode' => ['label' => 'Work Mode', 'type' => 'select', 'required' => true, 'options' => ['Work From Office', 'Work From Home', 'Hybrid'], 'default' => 'Work From Office'],
            'role_overview' => ['label' => 'Role Overview', 'type' => 'textarea', 'required' => true, 'rows' => 4],
            'responsibilities' => ['label' => 'Key Responsibilities (one per line)', 'type' => 'textarea', 'required' => true, 'rows' => 6],
        ];
    }

    private function freelancerFields(): array
    {
        return [
            'hourly_rate' => ['label' => 'Hourly Rate (₹)', 'type' => 'number', 'required' => true],
            'hourly_rate_words' => ['label' => 'Hourly Rate in words', 'type' => 'text', 'required' => true, 'auto_from' => 'hourly_rate'],
            'payment_cadence' => ['label' => 'Payment Cadence', 'type' => 'select', 'required' => true, 'options' => ['weekly', 'bi-weekly', 'monthly'], 'default' => 'weekly'],
        ];
    }

    private function internFields(): array
    {
        return [
            // Optional designation that relabels the role (e.g. "AI Intern",
            // "AI Research Intern"). Blank = a regular internship; the letter
            // body is then byte-for-byte unchanged.
            'position_designation' => ['label' => 'Position Designation (optional — e.g. AI Intern)', 'type' => 'text', 'required' => false, 'placeholder' => 'AI Intern'],
            'duration' => ['label' => 'Duration', 'type' => 'text', 'required' => true, 'default' => '3 Months'],
            'stipend_monthly' => ['label' => 'Monthly Stipend (₹)', 'type' => 'number', 'required' => true],
            'working_days' => ['label' => 'Working Days', 'type' => 'text', 'required' => true, 'default' => 'Monday to Friday'],
            'working_hours' => ['label' => 'Working Hours', 'type' => 'text', 'required' => true, 'default' => '10:00 AM to 6:30 PM'],
            // Editable AI-duties / responsible-use clause. Rendered only when
            // non-empty; the form auto-seeds a sensible default once a Position
            // Designation is entered.
            'ai_responsibilities' => ['label' => 'AI-Related Responsibilities (auto-filled when a Position Designation is set; editable)', 'type' => 'textarea', 'required' => false, 'rows' => 6],
        ];
    }

    private function fulltimeFields(): array
    {
        return [
            'annual_ctc' => ['label' => 'Annual CTC (₹)', 'type' => 'number', 'required' => true],
            'ctc_in_words' => ['label' => 'CTC in words', 'type' => 'text', 'required' => true, 'auto_from' => 'annual_ctc'],
            'notice_period' => ['label' => 'Notice Period', 'type' => 'text', 'required' => true, 'default' => '30 Days'],
            'basic_monthly' => ['label' => 'Basic Salary (monthly)', 'type' => 'number', 'required' => true, 'group' => 'annexure'],
            'basic_annual' => ['label' => 'Basic Salary (annual)', 'type' => 'number', 'required' => true, 'group' => 'annexure'],
            'hra_monthly' => ['label' => 'HRA (monthly)', 'type' => 'number', 'required' => true, 'group' => 'annexure'],
            'hra_annual' => ['label' => 'HRA (annual)', 'type' => 'number', 'required' => true, 'group' => 'annexure'],
            'other_monthly' => ['label' => 'Other Allowance (monthly)', 'type' => 'number', 'required' => true, 'group' => 'annexure'],
            'other_annual' => ['label' => 'Other Allowance (annual)', 'type' => 'number', 'required' => true, 'group' => 'annexure'],
            'gross_monthly' => ['label' => 'Gross Salary (monthly)', 'type' => 'number', 'required' => true, 'group' => 'annexure'],
            'gross_annual' => ['label' => 'Gross Salary (annual)', 'type' => 'number', 'required' => true, 'group' => 'annexure'],
            'employer_pf_monthly' => ['label' => 'Employer PF (monthly)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'employer_pf_annual' => ['label' => 'Employer PF (annual)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'employer_esi_monthly' => ['label' => 'Employer ESI (monthly)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'employer_esi_annual' => ['label' => 'Employer ESI (annual)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'employee_pf_monthly' => ['label' => 'Employee PF (monthly)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'employee_pf_annual' => ['label' => 'Employee PF (annual)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'employee_esi_monthly' => ['label' => 'Employee ESI (monthly)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'employee_esi_annual' => ['label' => 'Employee ESI (annual)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'professional_tax_monthly' => ['label' => 'Professional Tax (monthly)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'professional_tax_annual' => ['label' => 'Professional Tax (annual)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'tds_monthly' => ['label' => 'TDS (monthly)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'tds_annual' => ['label' => 'TDS (annual)', 'type' => 'number', 'required' => false, 'group' => 'annexure'],
            'total_deductions_monthly' => ['label' => 'Total Deductions (monthly)', 'type' => 'number', 'required' => true, 'group' => 'annexure'],
            'total_deductions_annual' => ['label' => 'Total Deductions (annual)', 'type' => 'number', 'required' => true, 'group' => 'annexure'],
            'net_monthly' => ['label' => 'Net Salary (₹ per month)', 'type' => 'number', 'required' => true, 'group' => 'annexure'],
        ];
    }

    /**
     * Probation-letter fields for interns (15-day probation). Reuses the
     * intern stipend/working-day shape; adds the probation window + an
     * optional confirmation-review clause. No salary annexure.
     */
    private function internProbationFields(): array
    {
        return [
            'stipend_monthly' => ['label' => 'Monthly Stipend (₹)', 'type' => 'number', 'required' => true],
            'working_days' => ['label' => 'Working Days', 'type' => 'text', 'required' => true, 'default' => 'Monday to Friday'],
            'working_hours' => ['label' => 'Working Hours', 'type' => 'text', 'required' => true, 'default' => '10:00 AM to 6:30 PM'],
            'probation_duration' => ['label' => 'Probation Duration', 'type' => 'text', 'required' => true, 'default' => '15 Days'],
            'probation_end_date' => ['label' => 'Probation End Date', 'type' => 'date', 'required' => true],
            'confirmation_review' => ['label' => 'Confirmation Review Note (optional)', 'type' => 'textarea', 'required' => false, 'rows' => 3],
        ];
    }

    /**
     * Probation-letter fields for full-time / experienced hires (30-day
     * probation). References the appointment letter for salary, so no
     * annexure here — only the probation window + notice period.
     */
    private function fulltimeProbationFields(): array
    {
        return [
            'notice_period' => ['label' => 'Notice Period', 'type' => 'text', 'required' => true, 'default' => '30 Days'],
            'probation_duration' => ['label' => 'Probation Duration', 'type' => 'text', 'required' => true, 'default' => '1 Month (30 Days)'],
            'probation_end_date' => ['label' => 'Probation End Date', 'type' => 'date', 'required' => true],
            'confirmation_review' => ['label' => 'Confirmation Review Note (optional)', 'type' => 'textarea', 'required' => false, 'rows' => 3],
        ];
    }

    /**
     * Fields shared by the offboarding letters (relieving + experience). These
     * describe a departing EMPLOYEE, not a candidate — no salary / work-mode, but
     * a gender (drives he/she/his/her + Mr./Ms.) and a last-working date.
     */
    private function offboardingFields(): array
    {
        return [
            'recipient_name' => ['label' => 'Employee Name', 'type' => 'text', 'required' => true],
            'recipient_email' => ['label' => 'Employee Email', 'type' => 'email', 'required' => true],
            'recipient_phone' => ['label' => 'Employee Phone (for WhatsApp)', 'type' => 'tel', 'required' => false, 'placeholder' => '+919876543210'],
            'gender' => ['label' => 'Gender (sets pronouns)', 'type' => 'select', 'required' => true, 'options' => ['Female', 'Male']],
            'role_title' => ['label' => 'Designation / Role', 'type' => 'text', 'required' => true],
            'department' => ['label' => 'Department', 'type' => 'text', 'required' => false],
            'start_date' => ['label' => 'Date of Joining', 'type' => 'date', 'required' => true],
            'last_working_date' => ['label' => 'Last Working / Relieving Date', 'type' => 'date', 'required' => true],
            'letter_date' => ['label' => 'Letter Date', 'type' => 'date', 'required' => true, 'default_today' => true],
        ];
    }

    /**
     * Experience-certificate-only paragraphs. Each is a phrase that slots into a
     * fixed sentence in the blade (pronouns stay in the template) so it reads
     * grammatically for any gender. Defaults are generic; HR/Claude tailor them.
     */
    private function experienceExtraFields(): array
    {
        return [
            'tenure_summary' => ['label' => 'Duties — completes "…was actively involved in ___"', 'type' => 'textarea', 'required' => true, 'rows' => 4, 'default' => 'fulfilling the core responsibilities of the role, collaborating with internal teams, and supporting day-to-day operations to a high standard'],
            'conduct_summary' => ['label' => 'Strengths — completes "…demonstrated ___ towards their responsibilities"', 'type' => 'textarea', 'required' => true, 'rows' => 3, 'default' => 'strong professional skills, attention to detail, and a proactive attitude'],
        ];
    }

    private function normalizeFields(array $fields, string $category): array
    {
        $fields = array_map(static function ($v) {
            if (is_string($v)) return trim($v);
            return $v;
        }, $fields);

        // Parse responsibilities textarea into bullet array.
        if (!empty($fields['responsibilities']) && is_string($fields['responsibilities'])) {
            $lines = preg_split('/\r?\n/', $fields['responsibilities']);
            $fields['responsibilities_list'] = array_values(array_filter(array_map('trim', $lines), 'strlen'));
        } else {
            $fields['responsibilities_list'] = [];
        }

        // Format dates for display.
        foreach (['start_date', 'letter_date', 'probation_end_date', 'last_working_date'] as $key) {
            if (!empty($fields[$key])) {
                $ts = strtotime((string) $fields[$key]);
                if ($ts !== false) {
                    $fields[$key . '_display'] = date('F j, Y', $ts);
                }
            }
        }

        if ($category === 'fulltime' && !empty($fields['annual_ctc']) && empty($fields['ctc_in_words'])) {
            $fields['ctc_in_words'] = $this->numberToIndianWords((int) $fields['annual_ctc']);
        }
        if ($category === 'freelancer' && !empty($fields['hourly_rate']) && empty($fields['hourly_rate_words'])) {
            $fields['hourly_rate_words'] = $this->numberToIndianWords((int) $fields['hourly_rate']);
        }

        // Offboarding letters: derive a gendered pronoun set so the blades stay
        // grammatical. Unknown/blank gender falls back to singular "they".
        [$salutation, $subject, $object, $possessive] = match (strtolower((string) ($fields['gender'] ?? ''))) {
            'male' => ['Mr.', 'he', 'him', 'his'],
            'female' => ['Ms.', 'she', 'her', 'her'],
            default => ['', 'they', 'them', 'their'],
        };
        $fields['salutation'] = $salutation;
        $fields['pronoun_subject'] = $subject;
        $fields['pronoun_object'] = $object;
        $fields['pronoun_possessive'] = $possessive;

        return $fields;
    }

    private function buildSubject(string $format, array $fields): string
    {
        $role = (string) ($fields['role_title'] ?? '');
        return str_replace(':role_title', $role, $format);
    }
}
