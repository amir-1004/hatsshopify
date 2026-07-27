<?php

namespace Tests\Unit;

use App\Services\HatArtService;
use PHPUnit\Framework\TestCase;

class HatArtServiceTest extends TestCase
{
    protected HatArtService $art;

    protected function setUp(): void
    {
        parent::setUp();

        $this->art = new HatArtService;
    }

    public function test_it_renders_an_svg_document(): void
    {
        $svg = $this->art->render('Baseball', 'Red');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('viewBox', $svg);
        $this->assertStringEndsWith('</svg>', trim($svg));
    }

    public function test_it_paints_the_hat_in_the_requested_named_color(): void
    {
        $svg = $this->art->render('Baseball', 'Red');

        $this->assertStringContainsString('#e03131', $svg);
    }

    public function test_it_accepts_a_hex_color_from_the_color_picker(): void
    {
        $svg = $this->art->render('Snapback', '#123456');

        $this->assertStringContainsString('#123456', $svg);
    }

    public function test_it_falls_back_to_a_neutral_color_for_nonsense_input(): void
    {
        $svg = $this->art->render('Beanie', 'chartreuse-ish maybe?');

        $this->assertStringContainsString(HatArtService::FALLBACK_COLOR, $svg);
    }

    public function test_every_known_style_renders_distinct_art(): void
    {
        $rendered = [];

        foreach (HatArtService::STYLES as $style) {
            $svg = $this->art->render($style, 'Navy');

            $this->assertStringStartsWith('<svg', $svg);
            $rendered[] = $svg;
        }

        $this->assertCount(
            count(HatArtService::STYLES),
            array_unique($rendered),
            'Each hat style should draw a different shape.'
        );
    }

    public function test_unknown_styles_still_render_something(): void
    {
        $svg = $this->art->render('Fedora', 'Black');

        $this->assertStringStartsWith('<svg', $svg);
    }

    public function test_style_matching_is_case_insensitive(): void
    {
        $this->assertSame(
            $this->art->render('Baseball', 'Red'),
            $this->art->render('baseball', 'red'),
        );
    }

    public function test_color_input_is_escaped_so_it_cannot_inject_markup(): void
    {
        $svg = $this->art->render('Baseball', '"><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $svg);
    }

    public function test_it_builds_a_relative_url_for_a_hat(): void
    {
        $url = HatArtService::urlFor('Trucker', 'Olive');

        $this->assertStringStartsWith('/hat-art/', $url);
        $this->assertStringContainsString('Trucker', $url);
        $this->assertStringContainsString('Olive', $url);
    }
}
