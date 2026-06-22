@php
    /** @var array $fields */
    $name = trim((string) ($fields['recipient_name'] ?? ''));
    $subject = $fields['pronoun_subject'] ?? 'they';
    $object = $fields['pronoun_object'] ?? 'them';
    $possessive = $fields['pronoun_possessive'] ?? 'their';
    $role = $fields['role_title'] ?? '';
    $joined = $fields['start_date_display'] ?? '';
    $relieved = $fields['last_working_date_display'] ?? '';
    $tenure = trim((string) ($fields['tenure_summary'] ?? ''));
    $conduct = trim((string) ($fields['conduct_summary'] ?? ''));
@endphp
<div class="doc-title-top" style="font-size:13pt; margin:6pt 0 26pt 0;">EXPERIENCE CERTIFICATE</div>

<div style="margin-bottom:11pt;">This is to certify that {{ $name }} was employed with Innovfix Pvt. Ltd. as a {{ $role }} from {{ $joined }} to {{ $relieved }}.</div>

@if ($tenure !== '')
    <div style="margin-bottom:11pt;">During {{ $possessive }} tenure, {{ $subject }} was actively involved in {{ $tenure }}. {{ ucfirst($subject) }} contributed to the team's objectives and assisted in day-to-day deliverables as required.</div>
@endif

@if ($conduct !== '')
    <div style="margin-bottom:11pt;">{{ $name }} demonstrated {{ $conduct }} towards {{ $possessive }} responsibilities. {{ ucfirst($subject) }} worked effectively with team members and consistently adhered to company policies and professional ethics. {{ ucfirst($possessive) }} performance and conduct throughout {{ $possessive }} employment were satisfactory.</div>
@endif

<div style="margin-bottom:11pt;">{{ ucfirst($subject) }} was relieved from {{ $possessive }} duties on {{ $relieved }}. We found {{ $object }} to be a dedicated and reliable professional.</div>

<div style="margin-bottom:11pt;">We wish {{ $object }} every success in {{ $possessive }} future career and endeavors.</div>
