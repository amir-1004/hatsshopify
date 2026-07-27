<?php

namespace Tests\Unit;

use App\Services\HatSizingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HatSizingServiceTest extends TestCase
{
    protected HatSizingService $sizing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sizing = new HatSizingService;
    }

    /** @return array<string, array{float, string}> */
    public static function circumferences(): array
    {
        return [
            'child-sized' => [50.0, 'XS'],
            'just under XS ceiling' => [54.4, 'XS'],
            'bottom of S' => [54.5, 'S'],
            'top of S' => [56.4, 'S'],
            'bottom of M' => [56.5, 'M'],
            'average adult' => [57.5, 'M'],
            'bottom of L' => [58.5, 'L'],
            'bottom of XL' => [60.5, 'XL'],
            'very large' => [66.0, 'XL'],
        ];
    }

    #[DataProvider('circumferences')]
    public function test_it_maps_a_circumference_to_a_size(float $cm, string $expected): void
    {
        $this->assertSame($expected, $this->sizing->sizeFor($cm));
    }

    public function test_it_measures_an_average_adult_head_from_pixels(): void
    {
        // A face photographed so the pupils are 63px apart (1px = 1mm) and
        // the face is 140px (14cm) wide — a textbook adult head.
        $cm = $this->sizing->circumferenceFromPixels(63.0, 140.0);

        $this->assertGreaterThan(54.0, $cm);
        $this->assertLessThan(59.0, $cm);
    }

    public function test_measurement_is_independent_of_photo_resolution(): void
    {
        $small = $this->sizing->circumferenceFromPixels(63.0, 140.0);
        $large = $this->sizing->circumferenceFromPixels(252.0, 560.0);

        $this->assertEqualsWithDelta($small, $large, 0.1);
    }

    public function test_a_wider_face_measures_a_bigger_head(): void
    {
        $narrow = $this->sizing->circumferenceFromPixels(63.0, 130.0);
        $wide = $this->sizing->circumferenceFromPixels(63.0, 155.0);

        $this->assertGreaterThan($narrow, $wide);
    }

    public function test_it_rejects_impossible_measurements(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sizing->circumferenceFromPixels(0.0, 140.0);
    }

    public function test_ranges_are_contiguous_and_open_ended_at_the_extremes(): void
    {
        $this->assertSame([null, 54.5], $this->sizing->rangeFor('XS'));
        $this->assertSame([54.5, 56.5], $this->sizing->rangeFor('S'));
        $this->assertSame([58.5, 60.5], $this->sizing->rangeFor('L'));
        $this->assertSame([60.5, null], $this->sizing->rangeFor('XL'));
    }

    public function test_universal_covers_the_middle_of_the_range(): void
    {
        $this->assertSame(HatSizingService::UNIVERSAL_RANGE, $this->sizing->rangeFor('Universal'));

        $this->assertTrue($this->sizing->fits('Universal', 57.0));
        $this->assertFalse($this->sizing->fits('Universal', 62.0));
        $this->assertFalse($this->sizing->fits('Universal', 52.0));
    }

    public function test_fit_check_is_open_ended_where_the_size_is(): void
    {
        $this->assertTrue($this->sizing->fits('XS', 48.0));
        $this->assertTrue($this->sizing->fits('XL', 70.0));
        $this->assertFalse($this->sizing->fits('XS', 60.0));
    }

    public function test_the_note_names_the_measurement_and_the_size(): void
    {
        $note = $this->sizing->fitNote(57.5);

        $this->assertStringContainsString('57.5', $note);
        $this->assertStringContainsString('M', $note);
    }
}
