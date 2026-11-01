<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeneficiaryIdTest extends TestCase
{
    use RefreshDatabase;

    private function baseAttributes(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'JUAN',
            'middle_name' => 'ANDRES',
            'last_name' => 'DELA CRUZ',
            'birthdate' => '1990-01-15',
        ], $overrides);
    }

    public function test_code_uses_first_middle_last_and_sequence(): void
    {
        $beneficiary = Beneficiary::createWithCode($this->baseAttributes());

        $this->assertSame('DOLE XII-SCFO-BARMM-'.now()->format('mY').'-00001', $beneficiary->beneficiary_code);
    }

    public function test_same_name_gets_distinct_suffixes(): void
    {
        $a = Beneficiary::createWithCode($this->baseAttributes());
        $b = Beneficiary::createWithCode($this->baseAttributes(['birthdate' => '1991-05-05']));
        $c = Beneficiary::createWithCode($this->baseAttributes(['birthdate' => '1992-06-06']));

        $this->assertSame('DOLE XII-SCFO-BARMM-'.now()->format('mY').'-00001', $a->beneficiary_code);
        $this->assertSame('DOLE XII-SCFO-BARMM-'.now()->format('mY').'-00002', $b->beneficiary_code);
        $this->assertSame('DOLE XII-SCFO-BARMM-'.now()->format('mY').'-00003', $c->beneficiary_code);
    }

    public function test_code_is_stable_when_names_are_edited(): void
    {
        $beneficiary = Beneficiary::createWithCode($this->baseAttributes());
        $original = $beneficiary->beneficiary_code;

        $beneficiary->update([
            'first_name' => 'JUANITO',
            'birthdate' => '1989-09-09',
            'occupation' => 'FISHERMAN',
        ]);

        $this->assertSame($original, $beneficiary->fresh()->beneficiary_code);
        $this->assertSame('JUANITO', $beneficiary->fresh()->first_name);
    }

    public function test_code_never_contains_birthdate_or_address(): void
    {
        $beneficiary = Beneficiary::createWithCode($this->baseAttributes([
            'barangay' => 'DATU BINASING',
            'birthdate' => '1967-05-05',
        ]));

        $this->assertStringNotContainsString('1967', $beneficiary->beneficiary_code);
        $this->assertStringNotContainsString('DATU', $beneficiary->beneficiary_code);
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', $beneficiary->beneficiary_code);
    }

    public function test_missing_middle_name_omits_segment_without_empty_hyphens(): void
    {
        $beneficiary = Beneficiary::createWithCode($this->baseAttributes([
            'middle_name' => null,
        ]));

        $this->assertSame('DOLE XII-SCFO-BARMM-'.now()->format('mY').'-00001', $beneficiary->beneficiary_code);
    }

    public function test_punctuation_accents_and_whitespace_are_normalized(): void
    {
        $beneficiary = Beneficiary::createWithCode($this->baseAttributes([
            'first_name' => '  José  L. ',
            'middle_name' => ' N/A ',
            'last_name' => 'Ñavárrø-Santos',
        ]));

        $code = $beneficiary->beneficiary_code;

        $this->assertStringStartsWith('DOLE XII-SCFO-BARMM-', $code);
        $this->assertStringNotContainsString('--', $code);
        $this->assertStringNotContainsString('N/A', $code);
    }

    public function test_codes_are_unique_at_database_level(): void
    {
        $a = Beneficiary::createWithCode($this->baseAttributes());

        $this->expectException(QueryException::class);

        // bypass the retry helper on purpose: the unique constraint must reject it
        Beneficiary::create($this->baseAttributes(['beneficiary_code' => $a->beneficiary_code]));
    }

    public function test_create_with_code_recovers_from_collision(): void
    {
        $a = Beneficiary::createWithCode($this->baseAttributes());

        $b = Beneficiary::createWithCode($this->baseAttributes([
            'beneficiary_code' => $a->beneficiary_code,
            'birthdate' => '1993-03-03',
        ]));

        $this->assertSame('DOLE XII-SCFO-BARMM-'.now()->format('mY').'-00002', $b->beneficiary_code);
    }
}
