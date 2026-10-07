<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The customer's calendar date — South African, whatever the clock says.
 *
 * Agora STORES times in UTC: the application runs on UTC (config app.timezone)
 * and its ledgers are stamped with SYSUTCDATETIME() (v1__13py, 24 Sep 2026),
 * converted to SAST where they are shown (the audit trail). That is right for
 * an instant. It is wrong for a BUSINESS date — "today", a default To date, the
 * week to date — because PumpIT's TransactionDate, LineDate and every date a
 * person picks on a screen are South African dates. Between midnight and 02:00
 * SAST the UTC date is still yesterday, so every "today" in PHP was a day
 * behind for two hours a night (Ryan, 7 Oct 2026: "we are utc+2 … should we
 * not correct that"). The SQL side already gets it right: CONVERT(date,
 * GETDATE()) on the customer's instance is a South African date.
 *
 * So a date that means "the day it is for the customer" comes from here, and
 * Carbon::today() / now()->toDateString() are for nothing a person reads as a
 * date. scripts/check-business-date.sh keeps it that way.
 */
final class BusinessDate
{
    /** The customer's zone. ZP's sites are all in South Africa. */
    public const ZONE = 'Africa/Johannesburg';

    /**
     * Today in South Africa, as midnight in the application's zone — so it
     * compares, subtracts and formats like every other date in the app, and
     * toDateString() is the South African date.
     */
    public static function today(): Carbon
    {
        return Carbon::parse(Carbon::now(self::ZONE)->toDateString());
    }

    /**
     * An instant AGORA stamped — a model's CreatedAt, a ledger's CommittedAt,
     * anything written by Eloquent or by SYSUTCDATETIME() — on the customer's
     * clock. Not for PumpIT's own stamps: those were written in South African
     * time and are shown as stored. A grid column says which it holds by its
     * format: `instant` for Agora's, `datetime` for PumpIT's.
     */
    public static function fromUtc(mixed $instant): ?Carbon
    {
        if ($instant === null || $instant === '') {
            return null;
        }

        $utc = $instant instanceof \DateTimeInterface
            ? Carbon::instance($instant)
            : Carbon::parse((string) $instant, 'UTC');

        return $utc->setTimezone(self::ZONE);
    }

    /** fromUtc(), written out; an em dash for nothing. */
    public static function show(mixed $instant, string $pattern = 'Y-m-d H:i'): string
    {
        return self::fromUtc($instant)?->format($pattern) ?? Format::NOTHING;
    }
}
