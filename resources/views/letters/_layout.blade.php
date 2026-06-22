@php
    /** @var array $fields */
    /** @var string $bodyHtml */
    /** @var string $letterType */
    /** @var string $category */
    /** @var bool $showAnnexure */
    $bgPath = public_path('img/letterhead-bg.png');
    $signaturePath = public_path('img/jp-signature.png');
    $bgB64 = is_file($bgPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($bgPath)) : null;
    $sigB64 = is_file($signaturePath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($signaturePath)) : null;
    $subject = $fields['letter_subject'] ?? '';
    $letterDateDisplay = $fields['letter_date_display'] ?? date('F j, Y');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $subject }}</title>
    <style>
        @page { margin: 0; size: A4; }
        html, body {
            margin: 0; padding: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            color: #1f1f1f;
            line-height: 1.45;
        }
        .letter-page {
            position: relative;
            page-break-before: always;
            height: 838pt;
            overflow: hidden;
        }
        .letter-page:first-child { page-break-before: avoid; }
        .letter-bg {
            position: absolute;
            top: 0; left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }
        .header-fallback {
            position: absolute; top: 0; left: 0; right: 0;
            background: #1d1d1d;
            color: #fff;
            padding: 28px 42px 22px 42px;
            z-index: 0;
        }
        .header-fallback .brand-name {
            font-size: 24pt; font-weight: 700; letter-spacing: 6px; line-height: 1;
        }
        .header-fallback .brand-tag {
            font-size: 9pt; color: #d6d6d6; letter-spacing: 2px; margin-top: 4px;
        }
        .header-fallback .contact-pill {
            text-align: right; font-size: 8.5pt; color: #f2f2f2; line-height: 1.7;
        }
        .body-pad {
            position: relative;
            z-index: 1;
            padding: 130pt 30pt 145pt 60pt;
        }
        .recipient-block .to-label { font-weight: 700; }
        .recipient-block .to-name { font-weight: 700; }
        .top-row { width: 100%; border-collapse: collapse; }
        .top-row td { vertical-align: top; }
        .doc-title-top {
            text-align: center;
            font-size: 12pt;
            font-weight: 700;
            margin: 0 0 8pt 0;
        }
        .date-right { text-align: right; font-size: 10pt; font-weight: 700; }
        .subject-line { margin: 4pt 0 3pt 0; }
        .subject-line .label { font-weight: 700; }
        .section-title { font-weight: 700; margin-top: 6pt; margin-bottom: 1pt; }
        .position-row { margin: 0; }
        .position-row .k { font-weight: 700; display: inline-block; min-width: 96pt; }
        ul.responsibilities { margin: 1pt 0 3pt 14pt; padding: 0; }
        ul.responsibilities li { margin: 0; }
        .compensation-block, .leave-block, .confidentiality-block,
        .nature-block, .perf-block, .ai-responsibilities-block { margin-top: 5pt; page-break-inside: avoid; }
        .signature-row {
            position: absolute;
            left: 60pt; right: 30pt;
            bottom: 60pt;
            z-index: 1;
        }
        .signature-row table { width: 100%; border-collapse: collapse; }
        .signature-row td { vertical-align: top; }
        .auth-block { font-size: 9pt; line-height: 1.35; }
        .auth-block .auth-name { font-weight: 700; }
        .sig-right { text-align: right; }
        .sig-right img { max-width: 180pt; max-height: 75pt; }
        table.annexure {
            width: 100%; border-collapse: collapse; margin-top: 12pt; font-size: 10pt;
        }
        table.annexure th, table.annexure td {
            border: 1px solid #b8b8b8; padding: 6pt 8pt; text-align: center;
        }
        table.annexure th { background: #ececec; font-weight: 700; }
        table.annexure td.label { text-align: left; }
        table.annexure tr.section-row td { background: #ececec; font-weight: 700; }
        table.annexure tr.total-row td { font-weight: 700; }
        .annexure-title { text-align: center; font-weight: 700; margin-top: 6pt; }
        .annexure-subtitle { text-align: center; font-weight: 700; margin: 6pt 0 2pt 0; }
        .net-line { margin-top: 10pt; font-size: 10.5pt; }
    </style>
</head>
<body>

<div class="letter-page">
    @if ($bgB64)
        <img class="letter-bg" src="{{ $bgB64 }}" alt="">
    @else
        <div class="header-fallback">
            <table style="width:100%;">
                <tr>
                    <td style="width:55%;">
                        <div class="brand-name">INNOVFIX</div>
                        <div class="brand-tag">Pvt Ltd</div>
                    </td>
                    <td class="contact-pill" style="width:45%;">
                        +91 7418 676 356<br>
                        contact@innovfix.in<br>
                        www.innovfix.ai<br>
                        <span style="font-size:7.5pt;">Municipal No. 420, PID68-6-420, IV Block, Koramangala, Bangalore - 560034, Karnataka, India.</span>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    <div class="body-pad">
        @if (in_array($letterType, ['relieving', 'experience'], true))
            {{-- Offboarding letters have no To/Subject block — just a date, then
                 the body supplies its own centered heading (matches the HR templates). --}}
            <div class="date-right" style="margin-bottom: 4pt;">Date: {{ $letterDateDisplay }}</div>

            {!! $bodyHtml !!}
        @else
            @if ($letterType === 'appointment')
                <div class="doc-title-top">Appointment Letter</div>
            @elseif ($letterType === 'probation')
                <div class="doc-title-top">Probation Letter</div>
            @endif

            <table class="top-row">
                <tr>
                    <td style="width:60%;">
                        <div class="recipient-block">
                            <div class="to-label">To :</div>
                            <div class="to-name">{{ $fields['recipient_name'] ?? '' }}</div>
                            <div>{{ $fields['recipient_email'] ?? '' }}</div>
                        </div>
                    </td>
                    <td style="width:40%;" class="date-right">
                        Date: {{ $letterDateDisplay }}
                    </td>
                </tr>
            </table>

            <div class="subject-line">
                <span class="label">Subject:</span> {{ $subject }}
            </div>

            {!! $bodyHtml !!}
        @endif
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
                    @if ($sigB64)
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

@if ($showAnnexure)
    @include('letters._annexure_salary', ['fields' => $fields, 'sigB64' => $sigB64, 'bgB64' => $bgB64])
@endif

</body>
</html>
