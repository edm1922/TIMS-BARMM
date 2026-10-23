@extends('layouts.app')

@section('title', 'Edit beneficiary')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0">Edit {{ $beneficiary->full_name }}</h1>
        <div class="font-monospace small text-muted">{{ $beneficiary->beneficiary_code }}</div>
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('beneficiaries.show', $beneficiary) }}">Back to record</a>
</div>

@if ($candidates !== null)
    <div class="alert alert-warning py-2 small" role="alert">
        <strong>Possible duplicate:</strong>
        {{ \App\Services\DuplicateDetector::ruleLabel($candidates['rule']) }} —
        @foreach ($candidates['matches'] as $i => $match)
            {{ $i > 0 ? ', ' : '' }}
            <a href="{{ route('beneficiaries.show', $match) }}" class="font-monospace">{{ $match->beneficiary_code }}</a>
        @endforeach
        (no automatic merge will happen; an admin decides on the record page).
    </div>
@endif

<form method="POST" action="{{ route('beneficiaries.update', $beneficiary) }}" id="beneficiaryForm">
    @csrf
    @method('PUT')
    <div class="card mb-3">
        <div class="card-body">
            @include('beneficiaries._form', ['beneficiary' => $beneficiary])
        </div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">Save changes</button>
        <a class="btn btn-outline-secondary" href="{{ route('beneficiaries.show', $beneficiary) }}">Cancel</a>
    </div>
</form>
@endsection
