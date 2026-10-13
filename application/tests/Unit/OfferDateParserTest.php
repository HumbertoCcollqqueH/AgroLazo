<?php

namespace Tests\Unit;

use App\Services\Messaging\OfferParser;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class OfferDateParserTest extends TestCase
{
    public function test_relative_dates_use_the_producers_peru_day(): void
    {
        $parser = new OfferParser;
        $reference = CarbonImmutable::parse('2026-09-26T02:00:00Z');

        foreach ([
            'hoy' => '2026-09-25',
            'mañana' => '2026-09-26',
            '  pasado   mañana  ' => '2026-09-27',
            'en 7 días' => '2026-10-02',
            'en 1 día' => '2026-09-26',
            'en 0 días' => '2026-09-25',
        ] as $input => $expected) {
            $this->assertSame($expected, $parser->date($input, $reference), $input);
        }
    }

    public function test_exact_dates_accept_short_numeric_and_spanish_month_formats(): void
    {
        $parser = new OfferParser;
        $reference = CarbonImmutable::parse('2026-09-25T12:00:00-05:00');

        foreach ([
            '2026-09-25' => '2026-09-25',
            '25/09/2026' => '2026-09-25',
            '1/10/2026' => '2026-10-01',
            '25/9' => '2026-09-25',
            '1/10' => '2026-10-01',
            '25 de septiembre' => '2026-09-25',
            '25 de setiembre de 2026' => '2026-09-25',
            '1 de octubre 2026' => '2026-10-01',
            '1 de enero del 2027' => '2027-01-01',
            '29/2/2028' => '2028-02-29',
        ] as $input => $expected) {
            $this->assertSame($expected, $parser->date($input, $reference), $input);
        }
    }

    public function test_dates_without_a_year_never_silently_roll_into_next_year(): void
    {
        $parser = new OfferParser;
        $reference = CarbonImmutable::parse('2026-12-31T12:00:00-05:00');

        $this->assertSame('2026-12-31', $parser->date('31/12', $reference));
        $this->assertNull($parser->date('1/1', $reference));
        $this->assertNull($parser->date('1 de enero', $reference));
        $this->assertSame('2027-01-01', $parser->date('1/1/2027', $reference));
        $this->assertSame('2027-01-01', $parser->date('mañana', $reference));
    }

    public function test_invalid_ambiguous_and_past_dates_are_rejected(): void
    {
        $parser = new OfferParser;
        $reference = CarbonImmutable::parse('2026-09-25T12:00:00-05:00');

        foreach ([
            '24/9', '2026-09-24', '24 de septiembre', '31/02/2027', '29/02/2027',
            '2027-02-29', '31 de abril de 2027', '0/10', '1/13', '1/10/0000',
            '1 de octubree', '01/10/26', '2026-10-01 mañana', 'en -2 días',
            'en 1.5 días', 'en muchos días', 'el próximo viernes', '',
        ] as $input) {
            $this->assertNull($parser->date($input, $reference), $input);
        }
    }

    public function test_availability_durations_include_the_first_day_and_start_at_available_from(): void
    {
        $parser = new OfferParser;
        $reference = CarbonImmutable::parse('2026-09-25T12:00:00-05:00');

        foreach ([
            'solo ese día' => '2026-10-01',
            'el mismo día' => '2026-10-01',
            'un día' => '2026-10-01',
            '1 día' => '2026-10-01',
            '7 días' => '2026-10-07',
            'por 7 días' => '2026-10-07',
            'durante 15 días' => '2026-10-15',
            '15 días' => '2026-10-15',
            '30 días' => '2026-10-30',
            'una semana' => '2026-10-07',
            'dos semanas' => '2026-10-14',
            'un mes' => '2026-10-30',
        ] as $input => $expected) {
            $this->assertSame($expected, $parser->availabilityEnd($input, '2026-10-01', $reference), $input);
        }

        $this->assertSame('2027-01-04', $parser->availabilityEnd('7 días', '2026-12-29', $reference));
        $this->assertSame('2027-03-01', $parser->availabilityEnd('un mes', '2027-01-31', $reference));
    }

    public function test_explicit_availability_end_must_be_on_or_after_start_and_today(): void
    {
        $parser = new OfferParser;
        $reference = CarbonImmutable::parse('2026-09-26T02:00:00Z');

        $this->assertSame('2026-09-25', $parser->availabilityEnd('hoy', '2026-09-25', $reference));
        $this->assertSame('2026-10-01', $parser->availabilityEnd('1/10', '2026-10-01', $reference));
        $this->assertSame('2026-10-23', $parser->availabilityEnd('23 de octubre', '2026-10-01', $reference));
        $this->assertNull($parser->availabilityEnd('hoy', '2026-10-01', $reference));
        $this->assertNull($parser->availabilityEnd('30/9', '2026-10-01', $reference));
        $this->assertNull($parser->availabilityEnd('24/9', '2026-09-20', $reference));
        $this->assertSame('2026-09-26', $parser->availabilityEnd('7 días', '2026-09-20', $reference));
        $this->assertNull($parser->availabilityEnd('1 día', '2026-09-20', $reference));
    }

    public function test_availability_never_guesses_an_end_from_an_invalid_or_open_ended_duration(): void
    {
        $parser = new OfferParser;
        $reference = CarbonImmutable::parse('2026-09-25T12:00:00-05:00');

        foreach (['0 días', '-7 días', '1.5 días', 'hasta agotar', 'hasta agotar existencias', 'varios días', '7', '31/02/2027'] as $input) {
            $this->assertNull($parser->availabilityEnd($input, '2026-09-25', $reference), $input);
        }

        foreach (['2026-02-31', '2026-00-25', '25/09/2026', '2026-9-25', '0000-09-25', ''] as $availableFrom) {
            $this->assertNull($parser->availabilityEnd('7 días', $availableFrom, $reference), $availableFrom);
        }
    }
}
