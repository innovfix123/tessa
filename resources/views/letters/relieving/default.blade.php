@php
    /** @var array $fields */
    $name = trim((string) ($fields['recipient_name'] ?? ''));
    $who = trim(($fields['salutation'] ?? '').' '.$name);
    $subject = $fields['pronoun_subject'] ?? 'they';
    $object = $fields['pronoun_object'] ?? 'them';
    $possessive = $fields['pronoun_possessive'] ?? 'their';
    $role = $fields['role_title'] ?? '';
    $joined = $fields['start_date_display'] ?? '';
    $relieved = $fields['last_working_date_display'] ?? '';
@endphp
<div class="doc-title-top" style="font-size:13pt; margin:6pt 0 26pt 0;">RELIEVING LETTER</div>

<div style="margin-bottom:11pt;">This is to confirm that {{ $who }} has been relieved from {{ $possessive }} position as {{ $role }} at Innovfix Private Limited effective {{ $relieved }}.</div>

<div style="margin-bottom:11pt;">{{ ucfirst($subject) }} joined the organization on {{ $joined }} and has been duly relieved from all {{ $possessive }} duties and responsibilities as of the aforementioned date.</div>

<div style="margin-bottom:11pt;">We confirm that {{ $subject }} has no pending obligations with the company and is free to pursue other opportunities.</div>

<div style="margin-bottom:11pt;">We wish {{ $object }} all the best in {{ $possessive }} future endeavors.</div>
