<?php

namespace Tests\Unit;

use App\Services\BookingPricingService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BookingPricingServiceTest extends TestCase
{
    public function test_shared_json_price_fixtures_match_php_calculator(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 2).'/tests/fixtures/booking-pricing-cases.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $case['date'].' '.$case['start']);
            $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $case['date'].' '.$case['end']);
            $actual = BookingPricingService::calculate($fixture['rules'], 1, $start, $end);
            $this->assertSame((float) $case['expected'], $actual['total'], $case['name']);
        }
        $move = $fixture['move_case'];
        $this->assertSame((float) $move['expected_amount'], BookingPricingService::movedAmount(
            (float) $move['current_amount'], (float) $move['old_slot_total'], (float) $move['target_slot_total']
        ));
    }
}
