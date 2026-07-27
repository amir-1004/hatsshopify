<?php

namespace App\Services;

/**
 * Draws a hat as an SVG from its style and color.
 *
 * Every hat in the catalog must have an image, and merchants don't always
 * have a photo ready — this renders a decent-looking illustration on demand
 * so `image_url` is never empty. It's also what the seeder and the
 * "not-null" backfill migration point at.
 */
class HatArtService
{
    /** Styles we have hand-drawn geometry for. */
    public const STYLES = ['Baseball', 'Snapback', 'Trucker', 'Beanie', 'Bucket'];

    /** Used when the hat's color isn't a name or hex we recognise. */
    public const FALLBACK_COLOR = '#4c6ef5';

    /**
     * Color names merchants actually type, mapped to something that looks
     * good on a dark dashboard.
     *
     * @var array<string, string>
     */
    protected const NAMED_COLORS = [
        'red' => '#e03131',
        'maroon' => '#a51111',
        'burgundy' => '#7d1128',
        'orange' => '#f76707',
        'yellow' => '#f2c037',
        'gold' => '#d4a017',
        'green' => '#2f9e44',
        'olive' => '#66801f',
        'forest' => '#1f6f3f',
        'teal' => '#0c8599',
        'blue' => '#1c7ed6',
        'navy' => '#1b2a4a',
        'sky' => '#4dabf7',
        'purple' => '#7048e8',
        'pink' => '#e64980',
        'brown' => '#7f5539',
        'tan' => '#c9a227',
        'khaki' => '#b3a369',
        'beige' => '#d8c3a5',
        'cream' => '#efe4cf',
        'white' => '#f1f3f5',
        'grey' => '#868e96',
        'gray' => '#868e96',
        'silver' => '#adb5bd',
        'charcoal' => '#3b3f46',
        'black' => '#22252a',
    ];

    /**
     * A root-relative art URL for a hat. Deliberately relative so the same
     * value works in tests, CI, and production without depending on
     * APP_URL — see the `image_url` backfill migration.
     */
    public static function urlFor(string $style, string $color): string
    {
        return '/hat-art/'.rawurlencode($style).'?color='.rawurlencode($color);
    }

    /**
     * Render the hat illustration as a standalone SVG document.
     */
    public function render(string $style, string $color): string
    {
        $base = $this->resolveColor($color);
        $dark = $this->shade($base, 0.72);
        $darker = $this->shade($base, 0.55);
        $light = $this->shade($base, 1.18);

        $body = match ($this->normalizeStyle($style)) {
            'Snapback' => $this->snapback($base, $dark, $darker, $light),
            'Trucker' => $this->trucker($base, $dark, $darker, $light),
            'Beanie' => $this->beanie($base, $dark, $darker, $light),
            'Bucket' => $this->bucket($base, $dark, $darker, $light),
            default => $this->baseball($base, $dark, $darker, $light),
        };

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 340 240" width="340" height="240" role="img" aria-label="Hat illustration">
        <g stroke="{$darker}" stroke-width="2" stroke-linejoin="round">{$body}</g>
        </svg>
        SVG;
    }

    /**
     * Map free-text style input onto a style we can draw.
     */
    public function normalizeStyle(string $style): string
    {
        foreach (self::STYLES as $known) {
            if (strcasecmp($known, trim($style)) === 0) {
                return $known;
            }
        }

        return 'Baseball';
    }

    /**
     * Resolve a color name or hex string to a `#rrggbb` value. Anything we
     * can't parse becomes the fallback, which also neutralises attempts to
     * smuggle markup in through the query string.
     */
    public function resolveColor(string $color): string
    {
        $color = strtolower(trim($color));

        if (preg_match('/^#?([0-9a-f]{6})$/', $color, $matches)) {
            return '#'.$matches[1];
        }

        if (preg_match('/^#?([0-9a-f]{3})$/', $color, $matches)) {
            [$r, $g, $b] = str_split($matches[1]);

            return "#{$r}{$r}{$g}{$g}{$b}{$b}";
        }

        foreach (self::NAMED_COLORS as $name => $hex) {
            if ($color === $name || str_contains($color, $name)) {
                return $hex;
            }
        }

        return self::FALLBACK_COLOR;
    }

    /**
     * Multiply a hex color's channels — <1 darkens, >1 lightens.
     */
    protected function shade(string $hex, float $factor): string
    {
        $channels = array_map(
            fn (string $pair) => max(0, min(255, (int) round(hexdec($pair) * $factor))),
            str_split(ltrim($hex, '#'), 2),
        );

        return '#'.implode('', array_map(
            fn (int $value) => str_pad(dechex($value), 2, '0', STR_PAD_LEFT),
            $channels,
        ));
    }

    protected function baseball(string $base, string $dark, string $darker, string $light): string
    {
        return <<<SVG
        <path d="M232 150 C 282 144 314 154 318 172 C 306 186 246 180 224 166 Z" fill="{$dark}"/>
        <path d="M70 152 C 70 86 112 56 160 56 C 210 56 246 88 246 152 Z" fill="{$base}"/>
        <path d="M160 56 C 148 92 146 124 149 152" fill="none" stroke="{$darker}" stroke-width="2.5"/>
        <path d="M160 56 C 186 84 202 118 208 152" fill="none" stroke="{$darker}" stroke-width="2.5"/>
        <path d="M72 142 C 110 152 208 152 245 142 L 246 152 L 70 152 Z" fill="{$dark}"/>
        <circle cx="160" cy="54" r="8" fill="{$light}"/>
        SVG;
    }

    protected function snapback(string $base, string $dark, string $darker, string $light): string
    {
        return <<<SVG
        <path d="M240 134 L 322 142 L 320 160 L 238 154 Z" fill="{$dark}"/>
        <path d="M74 152 C 74 80 112 50 160 50 C 210 50 248 80 248 152 Z" fill="{$base}"/>
        <path d="M160 50 L 158 152" fill="none" stroke="{$darker}" stroke-width="2.5"/>
        <path d="M208 58 C 220 88 228 120 230 152" fill="none" stroke="{$darker}" stroke-width="2.5"/>
        <path d="M76 140 C 112 150 210 150 247 140 L 248 152 L 74 152 Z" fill="{$dark}"/>
        <rect x="86" y="120" width="26" height="30" rx="4" fill="{$light}"/>
        <circle cx="160" cy="48" r="8" fill="{$light}"/>
        SVG;
    }

    protected function trucker(string $base, string $dark, string $darker, string $light): string
    {
        return <<<SVG
        <defs>
        <pattern id="mesh" width="10" height="10" patternUnits="userSpaceOnUse">
        <path d="M0 0 L10 10 M10 0 L0 10" stroke="{$darker}" stroke-width="1.2" fill="none"/>
        </pattern>
        </defs>
        <path d="M236 148 L 320 140 L 322 158 L 234 166 Z" fill="{$dark}"/>
        <path d="M70 152 C 70 84 112 54 160 54 C 210 54 246 86 246 152 Z" fill="{$light}"/>
        <path d="M70 152 C 70 84 112 54 160 54 C 210 54 246 86 246 152 Z" fill="url(#mesh)" stroke="none"/>
        <path d="M160 54 C 200 56 232 92 236 152 L 160 152 Z" fill="{$base}"/>
        <path d="M72 142 C 110 152 208 152 245 142 L 246 152 L 70 152 Z" fill="{$dark}"/>
        <circle cx="160" cy="52" r="8" fill="{$dark}"/>
        SVG;
    }

    protected function beanie(string $base, string $dark, string $darker, string $light): string
    {
        return <<<SVG
        <path d="M78 158 C 78 92 112 60 160 60 C 208 60 242 92 242 158 Z" fill="{$base}"/>
        <path d="M120 66 C 116 98 114 130 116 158" fill="none" stroke="{$darker}" stroke-width="2"/>
        <path d="M160 60 L 160 158" fill="none" stroke="{$darker}" stroke-width="2"/>
        <path d="M200 66 C 204 98 206 130 204 158" fill="none" stroke="{$darker}" stroke-width="2"/>
        <rect x="70" y="152" width="180" height="34" rx="12" fill="{$dark}"/>
        <path d="M92 156 L 92 182 M116 156 L 116 182 M140 156 L 140 182 M164 156 L 164 182 M188 156 L 188 182 M212 156 L 212 182" stroke="{$darker}" stroke-width="2"/>
        <circle cx="160" cy="52" r="14" fill="{$light}"/>
        SVG;
    }

    protected function bucket(string $base, string $dark, string $darker, string $light): string
    {
        return <<<SVG
        <path d="M96 150 C 96 96 124 74 160 74 C 196 74 224 96 224 150 Z" fill="{$base}"/>
        <path d="M96 132 C 130 142 190 142 224 132 L 224 150 L 96 150 Z" fill="{$dark}"/>
        <path d="M56 148 C 56 174 102 188 160 188 C 218 188 264 174 264 148 C 236 160 200 166 160 166 C 120 166 84 160 56 148 Z" fill="{$light}"/>
        <path d="M124 80 L 128 132 M196 80 L 192 132" fill="none" stroke="{$darker}" stroke-width="1.8"/>
        SVG;
    }
}
