<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;
use Tests\TestCase;

class PhoneNumberValidationTest extends TestCase
{
    public function test_nullable_phone_accepts_null(): void
    {
        $v = Validator::make(
            ['phone' => null],
            ['phone' => ['nullable', (new Phone)->international()]],
        );

        $this->assertTrue($v->passes());
    }

    public function test_international_rule_accepts_valid_e164(): void
    {
        $v = Validator::make(
            ['phone' => '+27821234567'],
            ['phone' => ['nullable', (new Phone)->international()]],
        );

        $this->assertTrue($v->passes());
    }

    public function test_international_rule_accepts_international_format_with_spaces(): void
    {
        $v = Validator::make(
            ['phone' => '+27 82 123 4567'],
            ['phone' => ['nullable', (new Phone)->international()]],
        );

        $this->assertTrue($v->passes());
    }

    public function test_international_rule_rejects_invalid_number(): void
    {
        $v = Validator::make(
            ['phone' => '+27 000'],
            ['phone' => ['nullable', (new Phone)->international()]],
        );

        $this->assertFalse($v->passes());
    }

    public function test_phone_number_normalizes_to_e164(): void
    {
        $formatted = (new PhoneNumber('+27 82 123 4567'))->formatE164();

        $this->assertSame('+27821234567', $formatted);
    }
}
