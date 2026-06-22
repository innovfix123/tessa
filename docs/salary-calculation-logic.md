# Salary Calculation Logic, Slabs & Rules

This documents the Indian-payroll salary-breakup engine used in two places:

1. **Offer / Appointment letters** — the Annexure-I auto-fill (HR/CEO/CFO).
2. **Salary Tool** — a standalone CTC ⇄ breakup calculator on Shoyab's portal (Finance).

**Both use the exact same engine**, so the numbers can never drift:
`app/Services/LetterSalaryCalculator.php`. The rules below are the source of truth
(mirrors `Salary-Payroll-Formulas-April-2026.md`, company-wide from April 2026).

> ⚠️ Both the letter subsystem and the Salary Tool source files are **untracked in git**.

---

## 1. Core model

Monthly **CTC = the monthly gross package** = everything the company spends:

```
CTC = Basic + HRA + Other + Employer PF + Employer ESI
```

Equivalently: `Gross (Basic+HRA+Other) = CTC − Employer PF − Employer ESI`.

`Net take-home = Gross − (Employee PF + Employee ESI + Professional Tax + TDS)`.

---

## 2. Slabs & constants

| Constant | Value | Meaning |
|---|---|---|
| `PF_BASIC_CAP` | ₹15,000 | PF is 12% of **min(Basic, 15,000)** |
| `ESI_GROSS_LIMIT` | ₹21,000 | ESI applies only when the relevant gross ≤ 21,000 |
| `PT_GROSS_LIMIT` | ₹25,000 | Professional Tax applies only when gross ≥ 25,000 |
| `PT_MONTHLY_REGULAR` | ₹200 | PT per month (Karnataka) |
| `PT_MONTHLY_FEB` | ₹300 | PT in February (annual = 11×200 + 300 = ₹2,500) |
| PF rate | 12% | both Employer and Employee |
| Employer ESI rate | 3.25% | of ESI wage base |
| Employee ESI rate | 0.75% | of gross |

---

## 3. Forward calculation (CTC → breakup)

Given **annual CTC** and a **category** (`fulltime` | `intern` | `freelancer`):

```
monthlyCtc   = round(annualCtc / 12)

basic        = round(monthlyCtc × 0.50)          # Basic = 50% of CTC
hra          = round(basic × 0.50)               # HRA   = 50% of Basic (= 25% of CTC)

# Employer PF — full-time only
employerPf   = fulltime ? round(min(basic, 15000) × 0.12) : 0

# Employer ESI — full-time AND (monthlyCtc − employerPf) ≤ 21,000
employerEsi  = (fulltime && (monthlyCtc − employerPf) ≤ 21000)
                 ? round((monthlyCtc − employerPf) × 0.0325) : 0

other        = monthlyCtc − basic − hra − employerPf − employerEsi
gross        = basic + hra + other               # = monthlyCtc − employerPf − employerEsi

# Deductions
employeePf   = employerPf
employeeEsi  = (fulltime && gross ≤ 21000) ? round(gross × 0.0075) : 0
professionalTax = (fulltime && gross ≥ 25000) ? 200 : 0
tds          = 0                                  # offer stage; HR/CA can override

netMonthly   = gross − (employeePf + employeeEsi + professionalTax + tds)
```

**Annual** = monthly × 12 for everything **except PT**, whose annual is `11×200 + 300 = 2,500`
(the February bump).

### Category differences
- **Full-time** — all statutory items (PF, ESI, PT) apply per the gates above.
- **Intern** / **Freelancer** — **no PF, no ESI, no PT**. Basic/HRA/Other still split the
  same way, so Gross = CTC and Net = Gross.

---

## 4. Backward calculation (Basic → CTC) — Salary Tool only

Because **Basic is fixed at 50% of monthly CTC**, the relationship inverts exactly:

```
monthlyCtc = 2  × Basic
annualCtc  = 24 × Basic
```

The tool then runs the **same forward calculation** on that CTC to fill in
HRA / Other / PF / ESI / PT / Net. So "enter Basic → CTC and the full breakup come back."

---

## 5. Worked examples (full-time)

| Input | CTC/mo | Basic | HRA | Other | Gross | Empr PF | Empr ESI | Empe PF | Empe ESI | PT | Net |
|---|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|
| CTC ₹6,00,000/yr | 50,000 | 25,000 | 12,500 | 10,700 | 48,200 | 1,800 | 0 | 1,800 | 0 | 200 | 46,200 |
| **Basic ₹25,000** (backward) | 50,000 | 25,000 | 12,500 | 10,700 | 48,200 | 1,800 | 0 | 1,800 | 0 | 200 | 46,200 |
| CTC ₹18,000/mo (ESI applies) | 18,000 | 9,000 | 4,500 | 2,870 | 16,370 | 1,080 | 550 | 1,080 | 123 | 0 | 15,167 |
| CTC ₹30,000/mo (PT applies) | 30,000 | 15,000 | 7,500 | 5,700 | 28,200 | 1,800 | 0 | 1,800 | 0 | 200 | 26,200 |
| ₹18,000/mo as **intern** | 18,000 | 9,000 | 4,500 | 4,500 | 18,000 | 0 | 0 | 0 | 0 | 0 | 18,000 |

Note how ESI turns **on** at ₹18k (gross ≤ 21k) and PT turns **on** at ₹30k (gross ≥ 25k),
while PF caps at ₹1,800 (12% of the ₹15k basic cap) once Basic ≥ ₹15,000.

---

## 6. Implementation map

### Shared engine
- `app/Services/LetterSalaryCalculator.php` — `breakup(annualCtc, category)` returns every
  monthly + annual field. **Single source of truth.**

### Offer / appointment letters
- `app/Http/Controllers/Api/HR/LetterController.php::previewBreakup()`
- Route: `POST /api/letters/preview-breakup` (auth: `ALLOWED_ROLES` — CEO/COO/CFO/HR/BA).
- `public/js/letters.js` — autofills the Annexure-I form fields; runs for `fulltime` only.

### Salary Tool (Shoyab, Finance)
- `app/Http/Controllers/Api/SalaryToolController.php::compute()` — forward (`mode=ctc`,
  annual or monthly) and backward (`mode=basic`). Reuses `LetterSalaryCalculator`.
- Route: `POST /api/salary-tool`. Auth: per-user allowlist `SALARY_TOOL_USER_IDS` (Shoyab #32).
- `public/js/salary-tool.js` — `SalaryToolModule.render()` into `#salary_toolView`; "From CTC" /
  "From Basic" modes + Full-time/Intern/Freelancer + live breakup table.
- Sidebar tab injected in `DashboardController` (`$salaryToolUserIds`); blade label/icon/
  container/script + `portal.js` dispatch (`view === 'salary_tool'`); CSS `.st-*` in `app/css/app.css`.

To grant the tool to more people: add their user id to **both** `SALARY_TOOL_USER_IDS`
(controller) and `$salaryToolUserIds` (DashboardController).
