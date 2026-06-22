@php /** @var array $fields */ @endphp
<div class="section-title">Position Details:</div>
<div class="position-row"><span class="k">Role:</span> {{ $fields['role_title'] ?? '' }}</div>
@if (!empty($fields['position_designation']))
    <div class="position-row"><span class="k">Designation:</span> {{ $fields['position_designation'] }}</div>
@endif
<div class="position-row"><span class="k">Joining Date:</span> {{ $fields['start_date_display'] ?? '' }}</div>
<div class="position-row"><span class="k">Stipend:</span> ₹{{ number_format((float) ($fields['stipend_monthly'] ?? 0), 0) }} per month</div>
<div class="position-row"><span class="k">Working Days:</span> {{ $fields['working_days'] ?? 'Monday to Friday' }}</div>
<div class="position-row"><span class="k">Working Hours:</span> {{ $fields['working_hours'] ?? '' }}</div>
<div class="position-row"><span class="k">Work Mode:</span> {{ $fields['work_mode'] ?? '' }}</div>

<div class="section-title">Role Overview:-</div>
<div>{{ $fields['role_overview'] ?? '' }}</div>

@if (!empty($fields['responsibilities_list']))
    <div class="section-title">Key Responsibilities:-</div>
    <ul class="responsibilities">
        @foreach ($fields['responsibilities_list'] as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
@endif

<div class="perf-block">
    <div class="section-title">Probation Period</div>
    <div class="position-row"><span class="k">Probation Duration:</span> {{ $fields['probation_duration'] ?? '15 Days' }}</div>
    <div class="position-row"><span class="k">Probation Ends On:</span> {{ $fields['probation_end_date_display'] ?? '' }}</div>
    <div>Your internship begins with a probationary period of {{ $fields['probation_duration'] ?? '15 Days' }} commencing from your joining date. During this period, your performance, conduct, punctuality, and overall suitability for the role will be assessed. Confirmation of your internship is subject to the satisfactory completion of this probation period. During probation, either party may discontinue the engagement with short notice.</div>
    @if (!empty($fields['confirmation_review']))
        <div>{!! nl2br(e($fields['confirmation_review'])) !!}</div>
    @endif
</div>

<div class="confidentiality-block">
    <div class="section-title">Confidentiality</div>
    <div>All information, data, and tools accessed during this internship are the property of Innovfix Private Limited. Sharing or misuse of such data is strictly prohibited.</div>
</div>
