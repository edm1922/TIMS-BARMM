<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\OfflineServer;
use App\Models\SyncChange;
use App\Models\SyncConflict;
use App\Models\SyncRequest;
use App\Models\SyncRequestDecision;
use App\Models\SyncRequestItem;
use App\Models\User;
use App\Services\Sync\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 5 — a submission is a reviewable request, not a single success flag.
 * Clean records are applied on receipt; duplicates and conflicts wait for an
 * administrator decision and only then touch the beneficiary tables.
 */
class SyncRequestReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{server: OfflineServer, token: string}
     */
    private function enrollServer(): array
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $response = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => 'Review Test Server',
            'server_uuid' => (string) Str::uuid(),
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'city_municipality',
            'scope_code' => '1900702000',
        ])->assertCreated();

        $data = $response->json('data');

        return [
            'server' => OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail(),
            'token' => $data['server_token'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function createOperation(array $overrides = []): array
    {
        return array_merge([
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'created',
            'base_revision' => 0,
            'payload' => [
                'first_name' => 'REVIEW',
                'last_name' => 'SUBJECT',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-'.Str::upper(Str::random(10)),
                'birthdate' => '1990-05-04',
            ],
        ], $overrides);
    }

    private function postOperations(string $token, array $operations): TestResponse
    {
        return $this->withHeader((string) config('sync.server_token_header'), $token)
            ->postJson('/api/sync/v1/operations', ['operations' => $operations]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_a_clean_upload_is_recorded_as_an_accepted_request(): void
    {
        ['token' => $token] = $this->enrollServer();
        $operation = $this->createOperation();

        $response = $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied');

        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();

        $this->assertSame(SyncRequest::STATUS_ACCEPTED, $request->status);
        $this->assertSame(1, $request->total_operations);
        $this->assertSame(1, $request->accepted_count);
        $this->assertSame(0, $request->pending_count);
        $this->assertSame(1, $request->items()->count());
        $this->assertSame(SyncRequestItem::STATUS_APPLIED, $request->items()->first()->status);
    }

    public function test_a_duplicate_identity_is_held_for_review_instead_of_being_inserted(): void
    {
        ['token' => $token] = $this->enrollServer();

        $existing = Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-DUPLICATE-1',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'EXISTING',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-DUPLICATE-1',
                'birthdate' => '1985-01-02',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'duplicate');

        $this->assertSame(1, Beneficiary::query()->count());

        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();
        $item = $request->items()->firstOrFail();

        $this->assertSame(SyncRequest::STATUS_AWAITING_REVIEW, $request->status);
        $this->assertSame(1, $request->pending_count);
        $this->assertSame(1, $request->duplicate_count);
        $this->assertSame(SyncRequestItem::STATUS_AWAITING_REVIEW, $item->status);
        $this->assertSame($existing->beneficiary_code, $item->duplicate_matches[0] ?? null);
    }

    public function test_a_reviewer_confirms_a_flagged_duplicate_without_inserting_a_second_person(): void
    {
        ['server' => $server, 'token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-DUPLICATE-2',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'EXISTING',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-DUPLICATE-2',
                'birthdate' => '1985-01-02',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();
        $item = $request->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('sync-requests.items.approve', [$request, $item]))
            ->assertRedirect(route('sync-requests.show', $request));

        $item->refresh();
        $request->refresh();

        $this->assertSame(SyncRequestItem::STATUS_ACCEPTED, $item->status);
        $this->assertSame(SyncRequest::STATUS_ACCEPTED, $request->status);
        $this->assertSame(1, Beneficiary::query()->count());
        $this->assertSame('duplicate_confirmed', $item->result['code']);

        // A retry after the decision replays it: no second record, no second
        // request, and no return to the review queue.
        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'duplicate')
            ->assertJsonPath('data.0.code', 'duplicate_confirmed');

        $this->assertSame(1, Beneficiary::query()->count());
        $this->assertSame(0, Beneficiary::query()->where('global_uuid', $operation['entity_uuid'])->count());
        $this->assertSame(1, SyncRequest::query()->count());
        $this->assertSame(1, SyncRequestItem::query()->count());
    }

    public function test_a_reviewer_can_apply_the_submitted_values_onto_the_matched_central_record(): void
    {
        ['token' => $token] = $this->enrollServer();

        $existing = Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-DUPLICATE-2B',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'CORRECTED',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-DUPLICATE-2B',
                'birthdate' => '1985-01-02',
                'contact_no' => '09170000000',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();
        $item = $request->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('sync-requests.items.approve', [$request, $item]), ['action' => 'update'])
            ->assertRedirect(route('sync-requests.show', $request));

        $this->assertSame('CORRECTED', $existing->fresh()->first_name);
        $this->assertSame('09170000000', $existing->fresh()->contact_no);
        $this->assertSame(SyncRequestItem::STATUS_ACCEPTED, $item->fresh()->status);
        $this->assertSame(1, Beneficiary::query()->count());
    }

    public function test_a_reviewer_rejects_a_record_with_a_reason_and_nothing_is_created(): void
    {
        ['token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-DUPLICATE-3',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'EXISTING',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-DUPLICATE-3',
                'birthdate' => '1985-01-02',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();
        $item = $request->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('sync-requests.items.reject', [$request, $item]), [
                'reason' => 'Same person, already registered under this source ID.',
            ])
            ->assertRedirect(route('sync-requests.show', $request));

        $item->refresh();
        $request->refresh();

        $this->assertSame(SyncRequestItem::STATUS_REJECTED, $item->status);
        $this->assertSame('Same person, already registered under this source ID.', $item->decision_reason);
        $this->assertSame(SyncRequest::STATUS_REJECTED, $request->status);
        $this->assertSame(1, Beneficiary::query()->count());
        $this->assertDatabaseMissing('beneficiaries', ['global_uuid' => $operation['entity_uuid']]);

        // The retry receives the recorded rejection instead of a fresh review.
        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.code', 'reviewer_rejected');
    }

    public function test_a_reviewer_requires_a_reason_to_reject(): void
    {
        ['token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-DUPLICATE-4',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'EXISTING',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-DUPLICATE-4',
                'birthdate' => '1985-01-02',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();
        $item = $request->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('sync-requests.items.reject', [$request, $item]), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(SyncRequestItem::STATUS_AWAITING_REVIEW, $item->fresh()->status);
    }

    public function test_a_revision_conflict_is_reviewed_and_resolved_by_accepting_the_submission(): void
    {
        ['token' => $token] = $this->enrollServer();

        $beneficiary = Beneficiary::createWithCode([
            'first_name' => 'CENTRAL',
            'last_name' => 'EDITED',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-CONFLICT-1',
            'birthdate' => '1980-03-03',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 2,
        ]);

        $operation = $this->createOperation([
            'entity_uuid' => $beneficiary->global_uuid,
            'operation' => 'updated',
            'base_revision' => 1,
            'payload' => [
                'first_name' => 'OFFLINE',
                'last_name' => 'EDITED',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-CONFLICT-1',
                'birthdate' => '1980-03-03',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'conflict');

        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();
        $item = $request->items()->firstOrFail();

        $this->assertSame(SyncRequestItem::STATUS_AWAITING_REVIEW, $item->status);
        $this->assertNotNull($item->conflict_uuid);
        $this->assertSame(1, SyncConflict::query()->where('status', 'open')->count());

        $this->actingAs($this->admin())
            ->post(route('sync-requests.items.approve', [$request, $item]), ['action' => 'accept_local'])
            ->assertRedirect(route('sync-requests.show', $request));

        $this->assertSame('OFFLINE', $beneficiary->fresh()->first_name);
        $this->assertSame(SyncRequestItem::STATUS_ACCEPTED, $item->fresh()->status);
        $this->assertSame(0, SyncConflict::query()->where('status', 'open')->count());
    }

    public function test_merging_a_conflict_requires_at_least_one_field(): void
    {
        ['token' => $token] = $this->enrollServer();

        $beneficiary = Beneficiary::createWithCode([
            'first_name' => 'CENTRAL',
            'last_name' => 'EDITED',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-CONFLICT-2',
            'birthdate' => '1980-03-03',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 2,
        ]);

        $operation = $this->createOperation([
            'entity_uuid' => $beneficiary->global_uuid,
            'operation' => 'updated',
            'base_revision' => 1,
            'payload' => [
                'first_name' => 'OFFLINE',
                'last_name' => 'EDITED',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-CONFLICT-2',
                'birthdate' => '1980-03-03',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();
        $item = $request->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->from(route('sync-requests.show', $request))
            ->post(route('sync-requests.items.approve', [$request, $item]), ['action' => 'merge'])
            ->assertRedirect(route('sync-requests.show', $request))
            ->assertSessionHasErrors('review');

        // Nothing was merged and the record still waits for a decision.
        $this->assertSame('CENTRAL', $beneficiary->fresh()->first_name);
        $this->assertSame(SyncRequestItem::STATUS_AWAITING_REVIEW, $item->fresh()->status);
        $this->assertSame(1, SyncConflict::query()->where('status', 'open')->count());
    }

    public function test_an_unresolved_operation_does_not_open_a_second_request_when_retried(): void
    {
        ['token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-DUPLICATE-5',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'EXISTING',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-DUPLICATE-5',
                'birthdate' => '1985-01-02',
            ],
        ]);

        $this->postOperations($token, [$operation])->assertOk();
        $this->postOperations($token, [$operation])->assertOk();

        $this->assertSame(1, SyncRequest::query()->count());
        $this->assertSame(1, SyncRequestItem::query()->count());
    }

    public function test_staff_cannot_review_requests(): void
    {
        ['token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-DUPLICATE-6',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'EXISTING',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-DUPLICATE-6',
                'birthdate' => '1985-01-02',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();
        $item = $request->items()->firstOrFail();

        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);

        $this->actingAs($staff)->get(route('sync-requests.index'))->assertForbidden();
        $this->actingAs($staff)
            ->post(route('sync-requests.items.approve', [$request, $item]))
            ->assertForbidden();

        $this->assertSame(SyncRequestItem::STATUS_AWAITING_REVIEW, $item->fresh()->status);
        $this->assertDatabaseMissing('beneficiaries', ['global_uuid' => $operation['entity_uuid']]);
    }

    public function test_the_review_page_compares_the_submission_with_central_data(): void
    {
        ['token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-DUPLICATE-7',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'EXISTING',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-DUPLICATE-7',
                'birthdate' => '1985-01-02',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('sync-requests.show', $request))
            ->assertOk()
            ->assertSee('Sync request review')
            ->assertSee('Submitted by the offline server')
            ->assertSee('Central server record')
            ->assertSee('Exact identifier matches')
            ->assertSee('Held: Same DOLE ID NUMBER');
    }

    public function test_the_pending_requests_page_lists_the_request_and_its_counts(): void
    {
        ['token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-DUPLICATE-9',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'EXISTING',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-DUPLICATE-9',
                'birthdate' => '1985-01-02',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('sync-requests.index'))
            ->assertOk()
            ->assertSee('Pending Sync Requests')
            ->assertSee('Review Test Server')
            ->assertSee(substr($request->request_uuid, 0, 8))
            ->assertSee('Awaiting review');
    }

    public function test_a_record_cannot_be_decided_twice(): void
    {
        ['token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-DUPLICATE-10',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'EXISTING',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-DUPLICATE-10',
                'birthdate' => '1985-01-02',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();
        $item = $request->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('sync-requests.items.approve', [$request, $item]))
            ->assertRedirect(route('sync-requests.show', $request));

        $this->actingAs($this->admin())
            ->from(route('sync-requests.show', $request))
            ->post(route('sync-requests.items.reject', [$request, $item]), ['reason' => 'Too late.'])
            ->assertRedirect(route('sync-requests.show', $request))
            ->assertSessionHasErrors('review');

        $this->assertSame(SyncRequestItem::STATUS_ACCEPTED, $item->fresh()->status);
        $this->assertSame(1, SyncRequest::query()->count());
        $this->assertSame(1, SyncRequestItem::query()->count());
    }

    public function test_a_mixed_request_reports_partial_acceptance(): void
    {
        ['token' => $token] = $this->enrollServer();

        $good = $this->createOperation([
            'payload' => [
                'first_name' => 'GOOD',
                'last_name' => 'RECORD',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-PARTIAL-GOOD',
                'birthdate' => '1991-02-02',
            ],
        ]);

        $bad = $this->createOperation([
            'payload' => [
                'first_name' => 'MISSING',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-PARTIAL-BAD',
            ],
        ]);

        $response = $this->postOperations($token, [$good, $bad])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();

        $this->assertSame(SyncRequest::STATUS_PARTIALLY_ACCEPTED, $request->status);
        $this->assertSame(1, $request->accepted_count);
        $this->assertSame(1, $request->rejected_count);
        $this->assertSame(0, $request->pending_count);

        // One rejected record does not obscure the other record's outcome.
        $this->assertSame(SyncRequestItem::STATUS_APPLIED, $request->items()->where('operation_uuid', $good['operation_uuid'])->firstOrFail()->status);
        $this->assertSame(SyncRequestItem::STATUS_REJECTED, $request->items()->where('operation_uuid', $bad['operation_uuid'])->firstOrFail()->status);

        $this->actingAs($this->admin())
            ->get(route('sync-requests.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee('Partially accepted');
    }

    public function test_the_review_summary_classifies_the_submission(): void
    {
        ['token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-SUMMARY-DUP',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $conflictTarget = Beneficiary::createWithCode([
            'first_name' => 'CENTRAL',
            'last_name' => 'EDITED',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-SUMMARY-CONF',
            'birthdate' => '1980-03-03',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 2,
        ]);

        $operations = [
            $this->createOperation([
                'payload' => [
                    'first_name' => 'BRAND',
                    'last_name' => 'NEW',
                    'location_code' => '1900702001',
                    'source_id_number' => 'SRC-SUMMARY-NEW',
                    'birthdate' => '1992-07-07',
                ],
            ]),
            $this->createOperation([
                'payload' => [
                    'first_name' => 'EXISTING',
                    'last_name' => 'PERSON',
                    'location_code' => '1900702001',
                    'source_id_number' => 'SRC-SUMMARY-DUP',
                    'birthdate' => '1985-01-02',
                ],
            ]),
            $this->createOperation([
                'entity_uuid' => $conflictTarget->global_uuid,
                'operation' => 'updated',
                'base_revision' => 1,
                'payload' => [
                    'first_name' => 'OFFLINE',
                    'last_name' => 'EDITED',
                    'location_code' => '1900702001',
                    'source_id_number' => 'SRC-SUMMARY-CONF',
                    'birthdate' => '1980-03-03',
                ],
            ]),
        ];

        $response = $this->postOperations($token, $operations)->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();

        $this->assertSame(3, $request->total_operations);
        $this->assertSame(2, $request->pending_count);

        $this->actingAs($this->admin())
            ->get(route('sync-requests.show', $request))
            ->assertOk()
            ->assertSee('Review summary')
            ->assertSee('New records')
            ->assertSee('Updates to existing')
            ->assertSee('Exact identifier matches')
            ->assertSee('Possible duplicates (name)')
            ->assertSee('Invalid records')
            ->assertSee('Conflicting updates')
            ->assertSee('Held because Same DOLE ID NUMBER matches an existing record')
            ->assertSee('Accepting writes the submitted values over the central record');
    }

    public function test_an_invalid_record_shows_an_actionable_reason(): void
    {
        ['token' => $token] = $this->enrollServer();

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'NO',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-INVALID-1',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.code', 'validation_failed');

        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('sync-requests.show', $request))
            ->assertOk()
            ->assertSee('Cannot be applied')
            ->assertSee('Required data is missing or invalid for this record.');
    }

    public function test_decisions_are_immutable(): void
    {
        ['token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-HISTORY-1',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'EXISTING',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-HISTORY-1',
                'birthdate' => '1985-01-02',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();
        $item = $request->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('sync-requests.items.reject', [$request, $item]), ['reason' => 'Duplicate.'])
            ->assertRedirect(route('sync-requests.show', $request));

        $decision = SyncRequestDecision::query()->firstOrFail();

        $this->assertSame(SyncRequestDecision::DECISION_REJECTED, $decision->decision);
        $this->assertSame('reject', $decision->action);
        $this->assertSame('Duplicate.', $decision->reason);
        $this->assertSame($request->request_uuid, $decision->request_uuid);
        $this->assertSame($item->operation_uuid, $decision->operation_uuid);
        $this->assertSame($request->server->server_uuid, $decision->source_server_uuid);

        $this->expectException(\RuntimeException::class);
        $decision->forceFill(['reason' => 'Rewritten.'])->save();
    }

    public function test_a_resubmission_keeps_the_earlier_decision_visible(): void
    {
        ['token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-HISTORY-2',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $payload = [
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-HISTORY-2',
            'birthdate' => '1985-01-02',
        ];

        $firstOperation = $this->createOperation(['payload' => $payload]);
        $first = $this->postOperations($token, [$firstOperation])->assertOk();
        $firstRequest = SyncRequest::query()->where('request_uuid', $first->json('meta.request_uuid'))->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('sync-requests.items.reject', [$firstRequest, $firstRequest->items()->firstOrFail()]), [
                'reason' => 'Duplicate of an existing record.',
            ])
            ->assertRedirect();

        // The manager corrects the record and submits the same local record
        // again: same global UUID (so it is the same person), new operation ID.
        $retry = $this->createOperation([
            'entity_uuid' => $firstOperation['entity_uuid'],
            'payload' => $payload,
        ]);

        $second = $this->postOperations($token, [$retry])->assertOk();
        $secondRequest = SyncRequest::query()->where('request_uuid', $second->json('meta.request_uuid'))->firstOrFail();

        $this->assertNotSame($firstRequest->id, $secondRequest->id);
        $this->assertSame(2, SyncRequest::query()->count());
        $this->assertSame(2, SyncRequestItem::query()->count());
        $this->assertSame(1, SyncRequestDecision::query()->count());

        $this->actingAs($this->admin())
            ->get(route('sync-requests.show', $secondRequest))
            ->assertOk()
            ->assertSee('Earlier decisions for the same records')
            ->assertSee('Duplicate of an existing record.');
    }

    public function test_the_review_decision_writes_an_audit_event(): void
    {
        ['token' => $token] = $this->enrollServer();

        Beneficiary::createWithCode([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'location_code' => '1900702001',
            'source_id_number' => 'SRC-DUPLICATE-8',
            'birthdate' => '1985-01-02',
            'origin_server_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'EXISTING',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-DUPLICATE-8',
                'birthdate' => '1985-01-02',
            ],
        ]);

        $response = $this->postOperations($token, [$operation])->assertOk();
        $request = SyncRequest::query()->where('request_uuid', $response->json('meta.request_uuid'))->firstOrFail();
        $item = $request->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('sync-requests.items.reject', [$request, $item]), ['reason' => 'Duplicate of an existing record.'])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sync.request_item_rejected',
            'entity_type' => 'beneficiary',
        ]);

        $this->assertDatabaseHas('sync_events', [
            'type' => 'request.item_rejected',
        ]);

        $receipt = SyncChange::query()->where('change_uuid', $operation['operation_uuid'])->firstOrFail();

        $this->assertSame('rejected', $receipt->payload['result']['status']);
        $this->assertSame('reviewer_rejected', $receipt->payload['result']['code']);
    }

    public function test_the_upload_history_page_uses_the_shared_dashboard_layout(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('sync-requests.index'))
            ->assertOk()
            ->assertSee('Pending Sync Requests')
            ->assertSee('Upload batches')
            ->assertSee('Requests awaiting review');
    }
}
