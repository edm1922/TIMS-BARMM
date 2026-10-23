@extends('layouts.app')

@section('title', 'Add beneficiary to '.$batch->name)

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="{{ route('beneficiary-batches.index') }}">Batches</a></li>
        <li class="breadcrumb-item"><a href="{{ route('beneficiary-batches.show', $batch) }}">{{ $batch->name }}</a></li>
        <li class="breadcrumb-item active" aria-current="page">Add beneficiary</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-0">Add beneficiary</h1>
        <div class="small text-muted">Registered into batch <strong>{{ $batch->name }}</strong></div>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-primary" id="duplicateCheckOpen">Verify</button>
        <a class="btn btn-outline-secondary" href="{{ route('beneficiary-batches.show', $batch) }}">Back to batch</a>
    </div>
</div>

<div class="alert alert-info py-2 small">
    The beneficiary ID (FIRSTNAME-MIDDLE-LASTNAME-0001) is generated automatically and cannot be changed.
    You must <strong>Verify</strong> the name and birthdate before you can register a beneficiary.
</div>

<form method="POST" action="{{ route('beneficiary-batches.beneficiaries.store', $batch) }}" id="beneficiaryForm"
      @if ($errors->any()) data-has-errors="1" @endif>
    @csrf
    <input type="hidden" name="duplicate_check_done" id="duplicateCheckDone" value="">
    <div class="card mb-3">
        <div class="card-body">
            @include('beneficiaries._form')
        </div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit" id="beneficiarySubmit" disabled>Create beneficiary</button>
        <a class="btn btn-outline-secondary" href="{{ route('beneficiary-batches.show', $batch) }}">Cancel</a>
    </div>
</form>

@include('beneficiaries.partials.duplicate-modal', ['autoOpen' => true])

@push('scripts')
<script src="{{ asset('assets/js/beneficiary-dup-modal.js') }}"></script>
@endpush
@endsection
