<?php

namespace Tests\Feature;

use App\Rules\PhilippinePhoneNumber;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PhilippinePhoneNumberTest extends TestCase
{
    public function test_valid_philippine_mobile_and_landline_formats_pass(): void
    {
        $valid = [
            '09171234567',
            '0917 123 4567',
            '0917-123-4567',
            '+63 917 123 4567',
            '639171234567',
            '9171234567',
            '(02) 8888 8888',
            '032 123 4567',
        ];

        foreach ($valid as $number) {
            $validator = Validator::make(
                ['phone' => $number],
                ['phone' => ['required', new PhilippinePhoneNumber]],
            );

            $this->assertTrue($validator->passes(), "Expected valid: {$number}");
        }
    }

    public function test_missing_or_extra_digits_and_non_numeric_values_are_rejected(): void
    {
        $invalid = [
            '0917123456',   // one digit short
            '091712345678', // one digit too many
            '+63917123456', // one digit short in international form
            '0917 ABC 4567',
            '12345',
            '',
        ];

        foreach ($invalid as $number) {
            $validator = Validator::make(
                ['phone' => $number],
                ['phone' => ['required', new PhilippinePhoneNumber]],
            );

            $this->assertTrue($validator->fails(), "Expected invalid: {$number}");
        }
    }
}
