<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhilippinePhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = preg_replace('/[\s\-().]+/', '', (string) $value);

        if (str_starts_with($normalized, '+63')) {
            $normalized = '0'.substr($normalized, 3);
        } elseif (str_starts_with($normalized, '63')) {
            $normalized = '0'.substr($normalized, 2);
        } elseif (preg_match('/^9\d{9}$/', $normalized) === 1) {
            $normalized = '0'.$normalized;
        }

        $isMobile = preg_match('/^09\d{9}$/', $normalized) === 1;
        $isLandline = preg_match('/^0[2-8]\d{8,9}$/', $normalized) === 1;

        if (! $isMobile && ! $isLandline) {
            $fail('Enter a valid Philippine phone number, for example 0917 123 4567 or (02) 8888 8888.');
        }
    }
}
