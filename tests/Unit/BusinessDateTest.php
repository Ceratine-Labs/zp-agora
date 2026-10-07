<?php

namespace Tests\Unit;

use App\Support\BusinessDate;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Today is the customer's date, not the server's (Ryan, 7 Oct 2026: "we are
 * utc+2 … should we not correct that").
 */
class BusinessDateTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 23:30 UTC on the 7th is 01:30 on the 8th in South Africa. */
    public function test_between_midnight_and_two_it_is_already_tomorrow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 23:30:00', 'UTC'));

        $this->assertSame('2026-10-08', BusinessDate::today()->toDateString());
        $this->assertSame('00:00:00', BusinessDate::today()->format('H:i:s'), 'A date: midnight, nothing else.');
    }

    /** An instant Agora stamped in UTC, on the customer's clock — string from SQL or a model's Carbon. */
    public function test_a_utc_instant_shows_in_south_african_time(): void
    {
        $this->assertSame('2026-10-07 17:57', BusinessDate::show('2026-10-07 15:57:27'));
        $this->assertSame('8 Oct 01:30', BusinessDate::show(Carbon::parse('2026-10-07 23:30:00', 'UTC'), 'j M H:i'));
        $this->assertSame('—', BusinessDate::show(null));
    }

    public function test_during_the_day_it_is_the_same_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'UTC'));

        $this->assertSame('2026-10-07', BusinessDate::today()->toDateString());
    }
}
