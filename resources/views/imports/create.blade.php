@extends('layouts.app')

@section('title', 'Upload workbook')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Upload beneficiary workbook</h1>
    <a class="btn btn-outline-secondary" href="{{ route('imports.index') }}">Back to batches</a>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white">Choose .xlsx file</div>
            <div class="card-body">
                <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="beneficiary_batch_id">Release batch (optional)</label>
                        <select class="form-select @error('beneficiary_batch_id') is-invalid @enderror" id="beneficiary_batch_id" name="beneficiary_batch_id">
                            <option value="">Unassigned (no batch)</option>
                            @foreach (($batches ?? []) as $batch)
                                <option value="{{ $batch->id }}" @selected(old('beneficiary_batch_id') == $batch->id)>{{ $batch->name }} @if($batch->status === 'closed') — closed @endif</option>
                            @endforeach
                        </select>
                        <div class="form-text">Imported beneficiaries will be placed into this release batch. Create batches first in Batches.</div>
                        @error('beneficiary_batch_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="workbook">Workbook (.xlsx, max 10 MB)</label>
                        <input class="form-control @error('workbook') is-invalid @enderror"
                               type="file" id="workbook" name="workbook" accept=".xlsx" required>
                        @error('workbook')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <button class="btn btn-primary" type="submit">Upload &amp; inspect</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white">What happens next</div>
            <div class="card-body small">
                <ol class="mb-0 ps-3">
                    <li>The file is stored privately and inspected only — nothing is written to the beneficiaries table.</li>
                    <li>You confirm the worksheet, header row and column mapping.</li>
                    <li>A dry-run classifies every row (<code>new</code>, <code>update</code>, <code>unchanged</code>, <code>duplicate</code>, <code>review</code>, <code>invalid</code>).</li>
                    <li>An administrator applies the confirmed decisions in one transaction — or the batch is cancelled and the file deleted.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
@endsection
