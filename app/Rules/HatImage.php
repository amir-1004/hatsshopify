<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Every hat must carry a picture of itself. Accepts either a full http(s)
 * URL (a merchant's CDN, a Printful mockup) or a path served by this app
 * (an upload at /design-files/{id}, generated art at /hat-art/{style}).
 *
 * Whitespace-only values are rejected: "not empty" means not empty, not
 * "a string that happens to exist".
 */
class HatImage implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Every hat needs an image — this cannot be blank.');

            return;
        }

        $value = trim($value);

        // Protocol-relative ("//host/x") is neither ours nor explicitly
        // http(s), so it doesn't count as a path on this site.
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if (in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return;
        }

        $fail('The hat image must be a full http(s) URL or a path on this site.');
    }
}
