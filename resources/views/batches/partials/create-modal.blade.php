<div class="modal fade" id="batchModal" tabindex="-1" aria-labelledby="batchModalLabel"
     aria-describedby="batchModalDescription" aria-hidden="true">
    <div class="modal-dialog tims-modal--md modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('beneficiary-batches.store') }}" id="batch-create-form">
            @csrf
            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="batchModalLabel">Create batch</h2>
                    <p class="tims-modal-subtitle" id="batchModalDescription">
                        Create a new beneficiary batch that members can be attached to.
                    </p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="tims-field">
                    <label class="form-label" for="batch-name">Batch name <span class="required">*</span></label>
                    <input type="text" class="form-control" id="batch-name" name="name"
                           maxlength="100" required placeholder="e.g. Batch 1 - SGA Pahamuddin">
                    @error('name')
                        <div class="tims-field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="tims-field">
                    <label class="form-label" for="batch-description">Description</label>
                    <input type="text" class="form-control" id="batch-description" name="description"
                           maxlength="255" placeholder="Optional note about this batch">
                    @error('description')
                        <div class="tims-field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="tims-field">
                    <label class="form-label" for="batch-capacity">Beneficiary limit</label>
                    <input type="number" class="form-control" id="batch-capacity" name="capacity"
                           min="1" max="100000" step="1" inputmode="numeric" placeholder="e.g. 100"
                           value="{{ old('capacity') }}">
                    <div class="form-text">
                        Maximum number of beneficiaries allowed in this batch. Leave blank for no limit.
                    </div>
                    @error('capacity')
                        <div class="tims-field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="tims-field">
                    <label class="form-label" for="batch-code-series">Beneficiary ID series (optional)</label>
                    <input type="text" class="form-control" id="batch-code-series"
                           name="beneficiary_code_series" maxlength="64" autocomplete="off"
                           placeholder="e.g. DOLE XII-SCFO-BARMM-102026-01646"
                           value="{{ old('beneficiary_code_series') }}">
                    <div class="form-text">
                        First beneficiary ID for this batch. The trailing number increments automatically
                        for every beneficiary added. Leave blank to use the automatic series.
                    </div>
                    @error('beneficiary_code_series')
                        <div class="tims-field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="tims-field">
                    <label class="form-label" for="batch-manager">Assigned manager</label>
                    <select class="form-select" id="batch-manager" name="manager_id">
                        <option value="">Unassigned</option>
                        @foreach ($managers as $manager)
                            <option value="{{ $manager->id }}">
                                {{ $manager->username }}{{ $manager->server ? ' - '.$manager->server->name : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('manager_id')
                        <div class="tims-field-error">{{ $message }}</div>
                    @enderror
                    @if ($managers->isEmpty())
                        <div class="form-text text-warning">
                            No active manager has been reported by an offline installation yet.
                        </div>
                    @endif
                </div>

                <div class="tims-note">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
                    </svg>
                    <div>
                        The batch opens straight away. Attach existing beneficiary records from the batch
                        management view.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" data-batch-submit>Create batch</button>
            </div>
        </form>
    </div>
</div>
