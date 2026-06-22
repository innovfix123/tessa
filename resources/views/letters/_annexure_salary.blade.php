@php
    /** @var array $fields */
    /** @var string|null $sigB64 */
    /** @var string|null $bgB64 */
    $fmt = static function ($v) {
        if ($v === null || $v === '' || (float) $v === 0.0) return '-';
        return number_format((float) $v, 2);
    };
    $letterDateDisplay = $fields['letter_date_display'] ?? date('F j, Y');
@endphp

{{-- Annexure Page 1: Salary Breakup --}}
<div class="letter-page">
    @if (!empty($bgB64))
        <img class="letter-bg" src="{{ $bgB64 }}" alt="">
    @endif

    <div class="body-pad">
        <div class="date-right" style="margin-bottom:14pt;">Date: {{ $letterDateDisplay }}</div>
        <div class="annexure-title">Salary Structure Annexure - I</div>
        <div class="annexure-subtitle">A. Salary Breakup</div>

        <table class="annexure">
            <thead>
                <tr>
                    <th style="width:36%;">Component</th>
                    <th style="width:32%;">Monthly (₹)</th>
                    <th style="width:32%;">Annual (₹)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="label">CTC</td>
                    <td>{{ $fmt(!empty($fields['annual_ctc']) ? ((float) $fields['annual_ctc']) / 12 : null) }}</td>
                    <td>{{ $fmt($fields['annual_ctc'] ?? null) }}</td>
                </tr>
                <tr><td colspan="3" style="height:6px; border-left:1px solid #b8b8b8; border-right:1px solid #b8b8b8;"></td></tr>
                <tr>
                    <td class="label">Basic Salary</td>
                    <td>{{ $fmt($fields['basic_monthly'] ?? null) }}</td>
                    <td>{{ $fmt($fields['basic_annual'] ?? null) }}</td>
                </tr>
                <tr>
                    <td class="label">HRA</td>
                    <td>{{ $fmt($fields['hra_monthly'] ?? null) }}</td>
                    <td>{{ $fmt($fields['hra_annual'] ?? null) }}</td>
                </tr>
                <tr>
                    <td class="label">Other Allowance</td>
                    <td>{{ $fmt($fields['other_monthly'] ?? null) }}</td>
                    <td>{{ $fmt($fields['other_annual'] ?? null) }}</td>
                </tr>
                <tr class="total-row">
                    <td class="label"><strong>Gross Salary</strong></td>
                    <td><strong>{{ $fmt($fields['gross_monthly'] ?? null) }}</strong></td>
                    <td><strong>{{ $fmt($fields['gross_annual'] ?? null) }}</strong></td>
                </tr>
                <tr class="section-row">
                    <td colspan="3">Employers Contributions</td>
                </tr>
                <tr>
                    <td class="label">Employer PF</td>
                    <td>{{ $fmt($fields['employer_pf_monthly'] ?? null) }}</td>
                    <td>{{ $fmt($fields['employer_pf_annual'] ?? null) }}</td>
                </tr>
                <tr>
                    <td class="label">Employer ESI</td>
                    <td>{{ $fmt($fields['employer_esi_monthly'] ?? null) }}</td>
                    <td>{{ $fmt($fields['employer_esi_annual'] ?? null) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="signature-row">
        <table>
            <tr>
                <td style="width:60%;">
                    <div class="auth-block">
                        <div class="auth-name">Authorized Signatory</div>
                        <div>JAYA PRASAD S</div>
                        <div>Director</div>
                        <div>For INNOVFIX PRIVATE LIMITED</div>
                        <div>+91 7418 676 356</div>
                        <div>jp@innovfix.in</div>
                    </div>
                </td>
                <td style="width:40%;" class="sig-right">
                    @if (!empty($sigB64))
                        <img src="{{ $sigB64 }}" alt="For Innovfix Pvt. Ltd. — Director">
                    @else
                        <div style="font-style:italic; font-weight:700; font-size:13pt;">For Innovfix Pvt. Ltd.</div>
                        <div style="margin-top:24px; font-weight:700;">Director</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>
</div>

{{-- Annexure Page 2: Salary Deductions --}}
<div class="letter-page">
    @if (!empty($bgB64))
        <img class="letter-bg" src="{{ $bgB64 }}" alt="">
    @endif

    <div class="body-pad">
        <div class="date-right" style="margin-bottom:14pt;">Date: {{ $letterDateDisplay }}</div>
        <div class="annexure-title">Salary Structure Annexure - I</div>
        <div class="annexure-subtitle">B. SALARY DEDUCTIONS</div>

        <table class="annexure">
            <thead>
                <tr>
                    <th colspan="3">Deductions</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="label" style="width:36%;">Employee PF</td>
                    <td style="width:32%;">{{ $fmt($fields['employee_pf_monthly'] ?? null) }}</td>
                    <td style="width:32%;">{{ $fmt($fields['employee_pf_annual'] ?? null) }}</td>
                </tr>
                <tr>
                    <td class="label">Employee ESI</td>
                    <td>{{ $fmt($fields['employee_esi_monthly'] ?? null) }}</td>
                    <td>{{ $fmt($fields['employee_esi_annual'] ?? null) }}</td>
                </tr>
                <tr>
                    <td class="label">Professional Tax</td>
                    <td>{{ $fmt($fields['professional_tax_monthly'] ?? null) }}</td>
                    <td>{{ $fmt($fields['professional_tax_annual'] ?? null) }}</td>
                </tr>
                <tr>
                    <td class="label">TDS</td>
                    <td>{{ $fmt($fields['tds_monthly'] ?? null) }}</td>
                    <td>{{ $fmt($fields['tds_annual'] ?? null) }}</td>
                </tr>
                <tr class="total-row">
                    <td class="label">Total Deductions</td>
                    <td>{{ $fmt($fields['total_deductions_monthly'] ?? null) }}</td>
                    <td>{{ $fmt($fields['total_deductions_annual'] ?? null) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="net-line">
            <strong>Net Salary :</strong> ₹ {{ $fields['net_monthly'] ?? '-' }} Per Month
        </div>
    </div>

    <div class="signature-row">
        <table>
            <tr>
                <td style="width:60%;">
                    <div class="auth-block">
                        <div class="auth-name">Authorized Signatory</div>
                        <div>JAYA PRASAD S</div>
                        <div>Director</div>
                        <div>For INNOVFIX PRIVATE LIMITED</div>
                        <div>+91 7418 676 356</div>
                        <div>jp@innovfix.in</div>
                    </div>
                </td>
                <td style="width:40%;" class="sig-right">
                    @if (!empty($sigB64))
                        <img src="{{ $sigB64 }}" alt="For Innovfix Pvt. Ltd. — Director">
                    @else
                        <div style="font-style:italic; font-weight:700; font-size:13pt;">For Innovfix Pvt. Ltd.</div>
                        <div style="margin-top:24px; font-weight:700;">Director</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>
</div>
