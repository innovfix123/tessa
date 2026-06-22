@extends('layouts.app')

@section('title', 'Authorize ' . $client->client_name)

@push('styles')
<style>
    .consent-wrap { max-width: 560px; margin: 40px auto; padding: 0 24px; color: #f5f5f5; }
    .consent-card { background: #151515; border: 1px solid #2d2d2d; border-radius: 16px; padding: 28px; }
    .consent-card h1 { font-size: 1.35rem; margin: 0 0 6px; font-weight: 600; }
    .consent-card p.lead { color: #a3a3a3; margin: 0 0 22px; line-height: 1.5; font-size: 0.95rem; }
    .consent-row { display: flex; gap: 12px; align-items: center; padding: 14px 0; border-top: 1px solid #232323; }
    .consent-row:first-of-type { border-top: 0; }
    .consent-row .label { color: #a3a3a3; min-width: 110px; font-size: 0.85rem; }
    .consent-row .value { color: #f5f5f5; font-weight: 500; font-size: 0.92rem; word-break: break-all; }
    .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #1f2937; color: #93c5fd; font-size: 0.75rem; font-weight: 500; }
    .tools-list { background: #0a0a0a; border: 1px solid #2d2d2d; border-radius: 10px; padding: 12px 16px; margin: 18px 0 0; max-height: 220px; overflow-y: auto; font-size: 0.82rem; color: #cfcfcf; }
    .tools-list code { background: #1f1f1f; padding: 1px 5px; border-radius: 3px; font-size: 0.78rem; color: #93c5fd; }
    .consent-actions { display: flex; gap: 10px; margin-top: 24px; }
    .consent-actions button { flex: 1; padding: 12px 14px; border-radius: 10px; font-weight: 600; font-size: 0.95rem; cursor: pointer; border: 0; }
    .btn-approve { background: #3b82f6; color: white; }
    .btn-approve:hover { background: #2563eb; }
    .btn-deny { background: #1f1f1f; color: #f5f5f5; border: 1px solid #2d2d2d !important; }
    .btn-deny:hover { background: #2a2a2a; }
    .consent-warn { background: #1a1a0a; border: 1px solid #5a4a1a; color: #fbbf24; padding: 10px 14px; border-radius: 8px; margin: 18px 0 0; font-size: 0.82rem; line-height: 1.5; }
</style>
@endpush

@section('content')
<div class="consent-wrap">
    <div class="consent-card">
        <h1>Authorize {{ $client->client_name }}</h1>
        <p class="lead">
            <strong>{{ $client->client_name }}</strong> is asking to connect to your Tessa account.
            If you approve, it will be able to act in Tessa as you — within the limits of your <strong>{{ $role_label }}</strong> role.
        </p>

        <div class="consent-row">
            <div class="label">Signed in as</div>
            <div class="value">{{ $user->name }} &lt;{{ $user->email }}&gt; <span class="pill">{{ $role_label }}</span></div>
        </div>
        <div class="consent-row">
            <div class="label">Application</div>
            <div class="value">{{ $client->client_name }}</div>
        </div>
        <div class="consent-row">
            <div class="label">Resource</div>
            <div class="value">{{ $resource }}</div>
        </div>
        <div class="consent-row">
            <div class="label">Scope</div>
            <div class="value">{{ $scope }}</div>
        </div>
        <div class="consent-row">
            <div class="label">Tools available</div>
            <div class="value">{{ $tools_count }} tools enabled for your role</div>
        </div>

        @if (!empty($available_tools))
            <div class="tools-list">
                @foreach ($available_tools as $tool)
                    <div><code>{{ $tool['name'] }}</code> &mdash; {{ \Illuminate\Support\Str::limit($tool['description'], 90) }}</div>
                @endforeach
            </div>
        @endif

        <div class="consent-warn">
            Only approve if you initiated this request. The application will be able to read and modify Tessa data on your behalf
            until you revoke access from <a href="/settings/connect-claude" style="color:#fbbf24;text-decoration:underline">Settings &rarr; Connect Claude</a>.
        </div>

        <form method="POST" action="/oauth/authorize">
            @csrf
            <input type="hidden" name="client_id" value="{{ $client->client_id }}">
            <input type="hidden" name="redirect_uri" value="{{ $redirect_uri }}">
            <input type="hidden" name="response_type" value="code">
            <input type="hidden" name="state" value="{{ $state }}">
            <input type="hidden" name="scope" value="{{ $scope }}">
            <input type="hidden" name="code_challenge" value="{{ $code_challenge }}">
            <input type="hidden" name="code_challenge_method" value="{{ $code_challenge_method }}">
            <input type="hidden" name="resource" value="{{ $resource }}">
            <div class="consent-actions">
                <button type="submit" name="decision" value="deny" class="btn-deny">Deny</button>
                <button type="submit" name="decision" value="approve" class="btn-approve">Approve</button>
            </div>
        </form>
    </div>
</div>
@endsection
