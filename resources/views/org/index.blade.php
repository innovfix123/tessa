@extends('layouts.app')

@section('title', 'Org Chart')

@section('content')
<div id="org-root"></div>
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('shared/org.css') }}?v={{ filemtime(public_path('shared/org.css')) }}">
@endpush
@push('scripts')
<script src="{{ asset('shared/org.js') }}?v={{ filemtime(public_path('shared/org.js')) }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.InnovfixOrgChart && window.InnovfixOrgChart.mount) {
        window.InnovfixOrgChart.mount('org-root');
    }
});
</script>
@endpush
