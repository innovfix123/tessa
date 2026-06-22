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

<div class="perf-block">
    <div class="section-title">Probation Period</div>
    <div class="position-row"><span class="k">Probation Duration:</span> {{ $fields['probation_duration'] ?? '1 Month (30 Days)' }}</div>
    <div class="position-row"><span class="k">Probation Ends On:</span> {{ $fields['probation_end_date_display'] ?? '' }}</div>
    <div>Your appointment begins with a probationary period of {{ $fields['probation_duration'] ?? '1 Month (30 Days)' }} commencing from your start date. During this period, your performance, conduct, punctuality, and overall suitability for the role will be assessed. Confirmation of your employment is subject to the satisfactory completion of this probation period. During probation, either party may discontinue the engagement with {{ $fields['notice_period'] ?? '30 Days' }} notice or as mutually agreed.</div>
    @if (!empty($fields['confirmation_review']))
        <div>{!! nl2br(e($fields['confirmation_review'])) !!}</div>
    @endif
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
