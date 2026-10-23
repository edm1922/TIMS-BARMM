<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\User;
use App\Services\AgeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgeStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function withNow(string $now): void
    {
        Carbon::setTestNow(Carbon::parse($now));
    }

    public function test_one_day_before_18th_birthday_is_underage(): void
    {
        $this->withNow('2026-10-18 12:00:00');

        $b = Beneficiary::createWithCode([
            'first_name' => 'BOUNDARY', 'last_name' => 'BEFORE', 'birthdate' => '2008-10-19',
        ]);

        $this->assertSame(17, $b->computedAge());
        $this->assertSame('underage', $b->ageStatus());
    }

    public function test_on_18th_birthday_is_adult(): void
    {
        $this->withNow('2026-10-18 12:00:00');

        $b = Beneficiary::createWithCode([
            'first_name' => 'BOUNDARY', 'last_name' => 'ON', 'birthdate' => '2008-10-18',
        ]);

        $this->assertSame(18, $b->computedAge());
        $this->assertSame('adult', $b->ageStatus());
    }

    public function test_one_day_after_18th_birthday_is_adult(): void
    {
        $this->withNow('2026-10-18 12:00:00');

        $b = Beneficiary::createWithCode([
            'first_name' => 'BOUNDARY', 'last_name' => 'AFTER', 'birthdate' => '2008-10-17',
        ]);

        $this->assertSame(18, $b->computedAge());
        $this->assertSame('adult', $b->ageStatus());
    }

    public function test_missing_birthdate_is_unknown_never_underage(): void
    {
        $this->withNow('2026-10-18 12:00:00');

        $b = Beneficiary::createWithCode([
            'first_name' => 'MISSING', 'last_name' => 'BIRTHDATE', 'birthdate' => null,
        ]);

        $this->assertNull($b->computedAge());
        $this->assertSame('unknown', $b->ageStatus());
        $this->assertSame('Needs Review', AgeService::label($b->ageStatus()));
    }

    public function test_future_birthdate_is_unknown_not_negative_age(): void
    {
        $this->withNow('2026-10-18 12:00:00');

        $this->assertSame('unknown', AgeService::status('2026-10-19'));
    }

    public function test_excel_age_column_is_never_used_for_status(): void
    {
        $this->withNow('2026-10-18 12:00:00');

        // source says 40 but birthdate makes the person a minor
        $b = Beneficiary::createWithCode([
            'first_name' => 'AGE', 'last_name' => 'CONFLICT', 'birthdate' => '2012-01-01',
            'age_source' => 40,
        ]);

        $this->assertSame('underage', $b->ageStatus());
        $this->assertSame(40, $b->age_source);
    }

    public function test_dashboard_counts_underage_and_unknown(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Beneficiary::createWithCode(['first_name' => 'A', 'last_name' => 'ADULT', 'birthdate' => '1990-01-01']);
        Beneficiary::createWithCode(['first_name' => 'B', 'last_name' => 'MINOR', 'birthdate' => '2015-05-05']);
        Beneficiary::createWithCode(['first_name' => 'C', 'last_name' => 'UNKNOWN', 'birthdate' => null]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertViewHas('counts', function (array $counts): bool {
            return $counts['total'] === 3
                && $counts['underage'] === 1
                && $counts['unknown_age'] === 1;
        });
    }
}
