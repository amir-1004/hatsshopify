<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin wrapper around the Anthropic Messages API.
 *
 * Every public method is designed to never throw: on any failure (missing
 * API key, HTTP error, network exception, unexpected response shape) it
 * degrades gracefully so AI features never block the core product.
 */
class ClaudeService
{
    protected const API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * Generate a short marketing description for a hat.
     *
     * Returns null on any failure so callers can fall back to a manual
     * description field.
     */
    public function generateHatDescription(string $name, string $color, string $style): ?string
    {
        $prompt = "Write a punchy 2-3 sentence e-commerce product description for a hat.\n"
            ."Name: {$name}\n"
            ."Color: {$color}\n"
            ."Style: {$style}\n"
            .'Return plain text only — no markdown, no quotation marks, no preamble.';

        $text = $this->complete($prompt);

        return $text !== null ? trim($text) : null;
    }

    /**
     * Summarize precomputed order statistics into a plain-language merchant
     * summary.
     *
     * @param  array<string, mixed>  $stats
     * @return array{summary: string, ai: bool}
     */
    public function orderInsights(array $stats): array
    {
        $prompt = "You are analyzing Shopify order data for a small hat merchant.\n"
            ."Stats (JSON): ".json_encode($stats)."\n"
            .'Write a 3-4 sentence plain-language summary for the merchant covering '
            .'order volume, revenue, best sellers, and any notable trends or anomalies. '
            .'Return plain text only — no markdown, no preamble.';

        $text = $this->complete($prompt);

        if ($text === null) {
            return $this->fallbackInsights($stats);
        }

        return [
            'summary' => trim($text),
            'ai' => true,
        ];
    }

    /**
     * Call the Anthropic Messages API and return the response text, or null
     * on any failure.
     */
    protected function complete(string $prompt): ?string
    {
        $apiKey = config('services.anthropic.key');

        if (empty($apiKey)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(15)->post(self::API_URL, [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 500,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if ($response->failed()) {
                Log::warning('Anthropic API request failed.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $text = $response->json('content.0.text');

            return is_string($text) && $text !== '' ? $text : null;
        } catch (Throwable $e) {
            Log::warning('Anthropic API request threw an exception.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build a deterministic, non-AI summary from precomputed stats.
     *
     * @param  array<string, mixed>  $stats
     * @return array{summary: string, ai: bool}
     */
    protected function fallbackInsights(array $stats): array
    {
        $totalOrders = $stats['total_orders'] ?? 0;
        $totalRevenue = number_format((float) ($stats['total_revenue'] ?? 0), 2);
        $currency = $stats['currency'] ?? 'USD';

        if ($totalOrders === 0) {
            return [
                'summary' => 'No orders yet.',
                'ai' => false,
            ];
        }

        $summary = "{$totalOrders} orders totaling {$totalRevenue} {$currency}.";

        $topHat = $stats['top_hats'][0]['name'] ?? null;

        if ($topHat) {
            $summary .= " Best seller: {$topHat}.";
        }

        return [
            'summary' => $summary,
            'ai' => false,
        ];
    }
}
