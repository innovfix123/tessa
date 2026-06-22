@php /** @var array $fields */ @endphp
<div class="section-title">Position Details:</div>
<div class="position-row"><span class="k">Role:</span> {{ $fields['role_title'] ?? '' }}</div>
@if (!empty($fields['department']))
    <div class="position-row"><span class="k">Department:</span> {{ $fields['department'] }}</div>
@endif
<div class="position-row"><span class="k">Start Date:</span> {{ $fields['start_date_display'] ?? '' }}</div>
<div class="position-row"><span class="k">Notice Period:</span> {{ $fields['notice_period'] ?? '30 Days' }}</div>
<div class="position-row"><span class="k">Work Mode:</span> {{ $fields['work_mode'] ?? '' }}</div>

<div class="section-title">Role Overview</div>
<div>{{ $fields['role_overview'] ?? '' }}</div>

@if (!empty($fields['responsibilities_list']))
    <ul class="responsibilities">
        @foreach ($fields['responsibilities_list'] as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
@endif

<div class="compensation-block">
    <div class="section-title">Compensation Details:</div>
    <div>Your annual CTC will be ₹{{ number_format((float) ($fields['annual_ctc'] ?? 0), 0) }}({{ $fields['ctc_in_words'] ?? '' }} only).</div>
    <div>A detailed salary structure is provided in Annexure – I.</div>
</div>

<div class="leave-block">
    <div class="section-title">Leave Policy:</div>
    <div>All leave taken by the employee will be treated as Paid Leave.</div>
    <div>There will be no loss of pay and no salary deduction for any leave availed.</div>
</div>

<div class="confidentiality-block">
    <div class="section-title">Confidentiality:</div>
    <div>All data, systems, content, and tools accessed during your employment are the property of Innovfix Pvt Ltd.</div>
    <div>Any sharing, misuse, or unauthorized disclosure is strictly prohibited.</div>
</div>
