<?php

namespace Tests\Unit;

use App\Domain\Telegram\Keyboard;
use Tests\TestCase;

class TelegramKeyboardUxTest extends TestCase
{
    public function test_age_buttons_cover_18_through_80_with_compact_pagination(): void
    {
        $first = Keyboard::ages(4, 0);
        $last = Keyboard::ages(4, 7);
        $this->assertSame(['18', '19', '20', '21'], array_column($first[0], 'text'));
        $this->assertSame(['22', '23', '24', '25'], array_column($first[1], 'text'));
        $this->assertSame('80', end($last[1])['text']);
        $this->assertSame('r:4:age_page_1', $first[2][0]['callback_data']);
        $this->assertSame('r:4:age_page_6', $last[2][0]['callback_data']);
        $this->assertCount(1, $last[2]);
    }

    public function test_navigation_and_one_based_pagination_are_consistent(): void
    {
        $this->assertSame('d:2:back', Keyboard::navigation('d', 2, 'back', 'cancel')[0][0]['callback_data']);
        $this->assertSame('d:2:cancel', Keyboard::navigation('d', 2, 'back', 'cancel')[0][1]['callback_data']);
        $this->assertSame('d:2:page_2', Keyboard::pagination('d', 2, 1, true, 'page_', 1)[0][0]['callback_data']);
        $this->assertSame('d:2:page_1', Keyboard::pagination('d', 2, 2, false, 'page_', 1)[0][0]['callback_data']);
    }

    public function test_event_buttons_offer_days_times_and_capacity(): void
    {
        $days = Keyboard::eventDays(1);
        $times = Keyboard::eventTimes(1);
        $capacity = Keyboard::capacities(1);
        $this->assertSame('d:1:event_day_', substr($days[0][0]['callback_data'], 0, 14));
        $this->assertSame('d:1:event_time_0800', $times[0][0]['callback_data']);
        $this->assertSame('d:1:event_time_custom', $times[2][0]['callback_data']);
        $this->assertSame('d:1:event_capacity_4', $capacity[0][0]['callback_data']);
        $this->assertSame('d:1:event_capacity_0', $capacity[2][0]['callback_data']);
    }
}
