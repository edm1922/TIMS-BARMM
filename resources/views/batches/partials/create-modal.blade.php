<div class="modal fade" id="batchModal" tabindex="-1" aria-labelledby="batchModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="{{ route('beneficiary-batches.store') }}" id="batch-create-form">
            @csrf
            <div class="modal-header py-2">
                <h5 class="modal-title h6" id="batchModalLabel">Create batch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label small mb-1" for="batch-name">Batch name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" id="batch-name" name="name"
                           maxlength="100" required placeholder="e.g. Batch 1 — SGA Pahamuddin">
                    @error('name')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1" for="batch-description">Description</label>
                    <input type="text" class="form-control form-control-sm" id="batch-description" name="description"
                           maxlength="255" placeholder="Optional note about this batch">
                    @error('description')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>
                <p class="small text-muted mb-0">
                    The batch opens straight away. Attach existing beneficiary records from the batch
                    management view.
                </p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary" data-batch-submit>Create batch</button>
            </div>
        </form>
    </div>
</div>
