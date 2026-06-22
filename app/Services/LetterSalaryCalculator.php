<?php

namespace App\Services;

/**
 * Indian payroll salary breakup for offer/appointment letters.
 *
 * Rules (per Salary-Payroll-Formulas-April-2026.md, company-wide from April 2026):
 *   - Monthly CTC = monthly Gross package (CTC = Basic + HRA + Other + Employer PF + Employer ESI).
 *   - Basic = 50% of monthly CTC.
 *   - HRA = 50% of Basic (= 25% of CTC).
 *   - Other = balance (= CTC − Basic − HRA − Employer PF − Employer ESI).
 *   - Employer PF = 12% on MIN(Basic, ₹15,000). Full-time only.
 *   - Employer ESI = 3.25% of ESI wage base (closed form: (M-R)/1.0325 × 3.25%) when
 *     full-time AND earned gross would land ≤ ₹21,000. Disabled otherwise.
 *   - Employee PF = same as Employer PF.
 *   - Employee ESI = 0.75% of earned gross when full-time AND earned gross ≤ ₹21,000.
 *   - PT (Karnataka) = ₹200/month (₹300 in Feb) when full-time AND earned gross ≥ ₹25,000.
 *   - TDS = 0 at offer stage (HR/CA can override).
 *   - Interns/Freelancers: no PF, no ESI, no PT.
 *
 * Annexure annual = monthly × 12 (PT annual = 11×monthly + 300 for the Feb bump).
 */
class LetterSalaryCalculator
{
    public const PF_BASIC_CAP = 15000;
    public const ESI_GROSS_LIMIT = 21000;
    public const PT_GROSS_LIMIT = 25000;
    public const PT_MONTHLY_REGULAR = 200;
    public const PT_MONTHLY_FEB = 300;

    /**
     * Compute the full annexure breakup from an annual CTC.
     *
     * @param int|float $annualCtc Annual CTC in rupees.
     * @param string $category 'fulltime' | 'intern' | 'freelancer'
     * @return array<string, int> Annexure field values, monthly + annual.
     */
    public function breakup(int|float $annualCtc, string $category = 'fulltime'): array
    {
        $isFullTime = $category === 'fulltime';
        $monthlyCtc = (int) round(((float) $annualCtc) / 12);

        $basic = (int) round($monthlyCtc * 0.50);
        $hra = (int) round($basic * 0.50);

        $employerPf = $isFullTime
            ? (int) round(min($basic, self::PF_BASIC_CAP) * 0.12)
            : 0;

        // ESI: applies when full-time AND earned gross (M − Employer PF) ≤ ₹21,000.
        // Matches the reference offer-letter template (`Offer letter Temp.pdf`):
        // when gross > 21k we leave ESI off entirely (Other allowance absorbs the rest).
        $employerEsi = 0;
        if ($isFullTime && ($monthlyCtc - $employerPf) <= self::ESI_GROSS_LIMIT) {
            $employerEsi = (int) round(($monthlyCtc - $employerPf) * 0.0325);
        }

        $other = $monthlyCtc - $basic - $hra - $employerPf - $employerEsi;
        $gross = $basic + $hra + $other;

        $employeePf = $employerPf;
        $employeeEsi = ($isFullTime && $gross <= self::ESI_GROSS_LIMIT)
            ? (int) round($gross * 0.0075)
            : 0;
        $professionalTax = ($isFullTime && $gross >= self::PT_GROSS_LIMIT)
            ? self::PT_MONTHLY_REGULAR
            : 0;
        $tds = 0; // offer stage — HR/CA can override

        $totalDeductions = $employeePf + $employeeEsi + $professionalTax + $tds;
        $netMonthly = $gross - $totalDeductions;

        // Annual: ×12 for everything except PT (Feb is ₹300 instead of ₹200).
        $ptAnnual = $professionalTax > 0
            ? (11 * self::PT_MONTHLY_REGULAR + self::PT_MONTHLY_FEB)
            : 0;
        $totalDeductionsAnnual = ($employeePf * 12) + ($employeeEsi * 12) + $ptAnnual + ($tds * 12);

        return [
            'annual_ctc' => (int) round($annualCtc),
            'monthly_ctc' => $monthlyCtc,

            'basic_monthly' => $basic,
            'basic_annual' => $basic * 12,
            'hra_monthly' => $hra,
            'hra_annual' => $hra * 12,
            'other_monthly' => $other,
            'other_annual' => $other * 12,
            'gross_monthly' => $gross,
            'gross_annual' => $gross * 12,

            'employer_pf_monthly' => $employerPf,
            'employer_pf_annual' => $employerPf * 12,
            'employer_esi_monthly' => $employerEsi,
            'employer_esi_annual' => $employerEsi * 12,

            'employee_pf_monthly' => $employeePf,
            'employee_pf_annual' => $employeePf * 12,
            'employee_esi_monthly' => $employeeEsi,
            'employee_esi_annual' => $employeeEsi * 12,
            'professional_tax_monthly' => $professionalTax,
            'professional_tax_annual' => $ptAnnual,
            'tds_monthly' => $tds,
            'tds_annual' => $tds * 12,

            'total_deductions_monthly' => $totalDeductions,
            'total_deductions_annual' => $totalDeductionsAnnual,
            'net_monthly' => $netMonthly,
            'net_annual' => $netMonthly * 12,
        ];
    }
}
