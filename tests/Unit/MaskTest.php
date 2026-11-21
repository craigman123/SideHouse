<?php

namespace Tests\Unit;

use App\Support\Mask;
use PHPUnit\Framework\TestCase;

class MaskTest extends TestCase
{
    public function test_email_masks_a_fixed_number_of_local_part_characters(): void
    {
        self::assertSame('cra******@gmail.com', Mask::email('craigfrenan2@gmail.com'));
        self::assertSame('jer******@gmail.com', Mask::email('jerkeangabrina38@gmail.com'));
    }

    public function test_email_keeps_only_the_first_character_for_short_local_parts(): void
    {
        self::assertSame('a******@example.com', Mask::email('a@example.com'));
        self::assertSame('a******@example.com', Mask::email('ab@example.com'));
        self::assertSame('a******@example.com', Mask::email('abc@example.com'));
    }

    public function test_email_handles_null_empty_and_invalid_values(): void
    {
        self::assertNull(Mask::email(null));
        self::assertNull(Mask::email(''));
        self::assertNull(Mask::email('not-an-email'));
        self::assertNull(Mask::email('local@'));
    }
}
