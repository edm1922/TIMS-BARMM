<?php

namespace App\Services\Sync;

use App\Models\Beneficiary;
use App\Models\SyncEvent;
use App\Models\SyncOperation;
use App\Services\BeneficiaryIdGenerator;
use App\Support\SyncIdentity;

/**
 * Makes room for a record that arrives from another installation.
 *
 * The sending server owns the identity of the records it holds, and it may
 * have replaced a record since this installation last downloaded it. Two
 * clashes can therefore happen here:
 *
 * - The sending server replaced a record: the same source ID number in the
 *   same batch, but a new record identity. The copy held here is stale, so it
 *   is removed and the current record takes its place.
 * - The code was already taken here by a record of this installation: that
 *   record is re-issued a fresh code, so the incoming record keeps the code
 *   every other installation already uses.
 *
 * Either way the incoming record is stored with the identity it has on the
 * sending server.
 */
class IncomingBeneficiaryResolver
{
    public function __construct(private readonly BeneficiaryIdGenerator $codes) {}

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function resolve(array $fields): array
    {
        $uuid = (string) ($fields['global_uuid'] ?? '');

        if ($uuid === '') {
            return $fields;
        }

        $origin = isset($fields['origin_server_uuid']) && is_string($fields['origin_server_uuid'])
            ? $fields['origin_server_uuid']
            : null;

        $this->supersedeReplacedCopy($fields, $origin);
        $this->supersedeGovernmentIdCopy($fields, $origin);

        $code = $fields['beneficiary_code'] ?? null;

        if (! is_string($code) || $code === '') {
            return $fields;
        }

        $holder = Beneficiary::query()
            ->where('beneficiary_code', $code)
            ->where('global_uuid', '!=', $uuid)
            ->first();

        if ($holder === null) {
            return $fields;
        }

        if ($this->sameOrigin($holder, $origin)) {
            $this->supersede($holder, $fields);

            return $fields;
        }

        if ($this->belongsToThisInstallation($holder)) {
            // A record of this installation took the code earlier: it is
            // re-issued, so the incoming record keeps the code every other
            // installation already uses.
            $this->reissue($holder);

            return $fields;
        }

        // The code belongs to a record of a third installation. That record is
        // left alone and the copy held here is given a code of its own.
        $fields['beneficiary_code'] = $this->freshCode($holder);

        return $fields;
    }

    /**
     * A record that the sending server replaced keeps its source ID number in
     * the same batch, but carries a new identity. The copy held here is stale.
     */
    private function supersedeReplacedCopy(array $fields, ?string $origin): void
    {
        $source = $fields['source_id_number'] ?? null;
        $batch = $fields['beneficiary_batch_id'] ?? null;

        if (! is_string($source) || $source === '' || $batch === null) {
            return;
        }

        $stale = Beneficiary::query()
            ->where('global_uuid', '!=', (string) $fields['global_uuid'])
            ->where('origin_server_uuid', $origin)
            ->where('beneficiary_batch_id', $batch)
            ->where('source_id_number', $source)
            ->first();

        if ($stale === null) {
            return;
        }

        $this->supersede($stale, $fields);
    }

    /**
     * A same-origin record that still holds the incoming record's government ID
     * under a different identity is a stale copy: the sending server replaced
     * it (for example when a batch was re-imported). Remove it so the incoming
     * record can take the unique (source_id_no, origin) slot — a soft-deleted or
     * archived copy still occupies that slot in the database.
     */
    private function supersedeGovernmentIdCopy(array $fields, ?string $origin): void
    {
        if ($origin === null) {
            return;
        }

        $governmentId = trim((string) ($fields['source_id_no'] ?? ''));

        if ($governmentId === '') {
            return;
        }

        $stale = Beneficiary::query()
            ->where('global_uuid', '!=', (string) ($fields['global_uuid'] ?? ''))
            ->where('origin_server_uuid', $origin)
            ->where('source_id_no', $governmentId)
            ->first();

        if ($stale === null) {
            return;
        }

        $this->supersede($stale, $fields);

        // Release the unique slot even though the stale row is now archived or
        // soft-deleted (the database unique index counts those rows too).
        $stale->forceFill(['source_id_no' => null])->save();

        $this->recordEvent('beneficiary_superseded_government_id', $stale, $fields, null);
    }

    /**
     * Remove a copy that the sending server replaced. A record that still
     * carries local edits that are queued for upload is parked instead of
     * removed, so nothing is lost while the incoming record takes its place.
     */
    private function supersede(Beneficiary $stale, array $incoming): void
    {
        if ($this->hasQueuedEdits($stale)) {
            $code = $this->freshCode($stale);

            $stale->forceFill([
                'beneficiary_code' => $code,
                'source_id_number' => null,
                'archived_at' => now(),
                'archive_reason' => 'Superseded by a newer copy of this record.',
            ])->save();

            $this->updateQueuedPayloads($stale, $code);

            $this->recordEvent('beneficiary_superseded_copy_archived', $stale, $incoming, $code);

            return;
        }

        $stale->delete();

        $this->recordEvent('beneficiary_superseded_copy_removed', $stale, $incoming, null);
    }

    /**
     * Give a record a new code that is not in use yet.
     */
    public function reissue(Beneficiary $beneficiary): string
    {
        $previous = (string) $beneficiary->beneficiary_code;
        $code = $this->freshCode($beneficiary);

        $beneficiary->forceFill(['beneficiary_code' => $code])->save();

        // A queued upload still carries the old code, which would collide on
        // the Central Server. The queued payload follows the new code.
        $this->updateQueuedPayloads($beneficiary, $code);

        SyncEvent::create([
            'type' => 'beneficiary_code_reissued',
            'severity' => 'warning',
            'message' => 'The beneficiary code was re-issued because another installation already used it for a different record.',
            'context' => [
                'beneficiary_uuid' => $beneficiary->global_uuid,
                'previous_code' => $previous,
                'beneficiary_code' => $code,
            ],
        ]);

        return $code;
    }

    private function freshCode(Beneficiary $beneficiary): string
    {
        for ($attempt = 0; ; $attempt++) {
            $code = $this->codes->generate(
                (string) $beneficiary->first_name,
                $beneficiary->middle_name,
                (string) $beneficiary->last_name,
                $attempt,
            );

            $taken = Beneficiary::query()
                ->where('beneficiary_code', $code)
                ->where('id', '!=', $beneficiary->id)
                ->exists();

            if (! $taken) {
                return $code;
            }
        }
    }

    /**
     * Keep the code of a record that is waiting to be uploaded in step with
     * the code the record now carries.
     */
    private function updateQueuedPayloads(Beneficiary $beneficiary, string $code): void
    {
        SyncOperation::query()
            ->where('entity_type', 'beneficiary')
            ->where('entity_uuid', $beneficiary->global_uuid)
            ->whereIn('status', [SyncOperation::STATUS_PENDING, SyncOperation::STATUS_FAILED])
            ->get()
            ->each(function (SyncOperation $operation) use ($code): void {
                $payload = $operation->payload ?? [];

                if (! array_key_exists('beneficiary_code', $payload)) {
                    return;
                }

                $payload['beneficiary_code'] = $code;

                $operation->forceFill(['payload' => $payload])->save();
            });
    }

    private function hasQueuedEdits(Beneficiary $beneficiary): bool
    {
        return SyncOperation::query()
            ->where('entity_type', 'beneficiary')
            ->where('entity_uuid', $beneficiary->global_uuid)
            ->whereIn('status', [
                SyncOperation::STATUS_PENDING,
                SyncOperation::STATUS_FAILED,
                SyncOperation::STATUS_SYNCING,
            ])
            ->exists();
    }

    private function recordEvent(string $type, Beneficiary $stale, array $incoming, ?string $code): void
    {
        SyncEvent::create([
            'type' => $type,
            'severity' => 'warning',
            'message' => 'A copy of a record that the central server replaced was removed from this installation.',
            'context' => [
                'stale_uuid' => $stale->global_uuid,
                'stale_code' => $stale->beneficiary_code,
                'incoming_uuid' => $incoming['global_uuid'] ?? null,
                'incoming_code' => $incoming['beneficiary_code'] ?? null,
                'beneficiary_code' => $code,
            ],
        ]);
    }

    private function sameOrigin(Beneficiary $beneficiary, ?string $origin): bool
    {
        if ($origin === null) {
            return false;
        }

        return $beneficiary->origin_server_uuid === $origin;
    }

    /**
     * A record this installation owns: created here, or created by the server
     * this installation is.
     */
    private function belongsToThisInstallation(Beneficiary $beneficiary): bool
    {
        $origin = $beneficiary->origin_server_uuid;

        if (! is_string($origin) || $origin === '') {
            return true;
        }

        return $origin === SyncIdentity::originUuid();
    }
}
