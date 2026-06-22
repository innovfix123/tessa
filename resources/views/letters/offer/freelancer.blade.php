@php /** @var array $fields */ @endphp
<div class="section-title">Position Details</div>
<div class="position-row"><span class="k">Role:</span> {{ $fields['role_title'] ?? '' }}</div>
@if (!empty($fields['department']))
    <div class="position-row"><span class="k">Department:</span> {{ $fields['department'] }}</div>
@endif
<div class="position-row"><span class="k">Start Date:</span> {{ $fields['start_date_display'] ?? '' }}</div>
<div class="position-row"><span class="k">Work Mode:</span> {{ $fields['work_mode'] ?? '' }}</div>

<div class="section-title">Role Overview</div>
<div>{{ $fields['role_overview'] ?? '' }}</div>

@if (!empty($fields['responsibilities_list']))
    <div class="section-title">Key Responsibilities</div>
    <ul class="responsibilities">
        @foreach ($fields['responsibilities_list'] as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
@endif

<div class="compensation-block">
    <div class="section-title">Compensation</div>
    <div>You will be paid ₹{{ number_format((float) ($fields['hourly_rate'] ?? 0), 0) }}({{ $fields['hourly_rate_words'] ?? '' }} Rupees only) per hour as a freelance fee.</div>
    <div>Payments will be processed on a {{ $fields['payment_cadence'] ?? 'weekly' }} basis based on the total approved working hours completed during the {{ $fields['payment_cadence'] ?? 'week' }}.</div>
</div>

<div class="nature-block">
    <div class="section-title">Nature of Engagement</div>
    <div>This is a freelance/contract-based engagement and does not constitute full-time employment with Innovfix Private Limited.</div>
    <div>You will not be entitled to employee benefits such as PF, ESI, paid leave, or other statutory benefits.</div>
</div>

<div class="perf-block">
    <div class="section-title">Performance &amp; Conduct</div>
    <ul class="responsibilities">
        <li>You are expected to maintain professionalism and timely communication</li>
        <li>Consistent performance and task completion are required</li>
        <li>Innovfix reserves the right to discontinue the engagement if performance is unsatisfactory</li>
    </ul>
</div>

<div class="confidentiality-block">
    <div class="section-title">Confidentiality</div>
    <div>All data, systems, content, and tools accessed during your engagement are the property of Innovfix Private Limited. Any sharing, misuse, or unauthorized disclosure is strictly prohibited.</div>
</div>
