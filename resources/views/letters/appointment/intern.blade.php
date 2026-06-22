@php /** @var array $fields */ @endphp
<div class="section-title">Position Details:</div>
<div class="position-row"><span class="k">Role:</span> {{ $fields['role_title'] ?? '' }}</div>
@if (!empty($fields['position_designation']))
    <div class="position-row"><span class="k">Designation:</span> {{ $fields['position_designation'] }}</div>
@endif
<div class="position-row"><span class="k">Joining Date:</span> {{ $fields['start_date_display'] ?? '' }}</div>
<div class="position-row"><span class="k">Salary:</span> ₹{{ number_format((float) ($fields['stipend_monthly'] ?? 0), 0) }} per month</div>
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

@if (!empty($fields['ai_responsibilities']))
    <div class="ai-responsibilities-block">
        <div class="section-title">AI-Related Responsibilities &amp; Responsible Use</div>
        <div>{!! nl2br(e($fields['ai_responsibilities'])) !!}</div>
    </div>
@endif

<div class="perf-block">
    <div class="section-title">Performance &amp; Conduct</div>
    <div>The candidate is expected to demonstrate strong communication skills, professionalism, and reliability in all interactions. Timely completion of tasks, proactive coordination, and maintaining confidentiality are essential. A positive attitude, adaptability, and commitment to quality work will be key to success in this role.</div>
</div>

<div class="confidentiality-block">
    <div class="section-title">Confidentiality</div>
    <div>All information, data, and tools accessed during this internship are the property of Innovfix Private Limited. Sharing or misuse of such data is strictly prohibited.</div>
</div>
