<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DesignFile;
use App\Models\Hat;
use App\Services\PrintfulService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PrintfulController extends Controller
{
    /**
     * Hardcoded shortlist used when Printful is unconfigured/unavailable,
     * so the Design Studio always has something to show.
     */
    protected const FALLBACK_PRODUCTS = [
        ['id' => 206, 'title' => 'Snapback Cap'],
        ['id' => 662, 'title' => 'Dad Hat'],
        ['id' => 619, 'title' => 'Trucker Cap'],
    ];

    /**
     * Curated headwear catalog for the Design Studio's "pick a base hat"
     * step. Falls back to a hardcoded shortlist (flagged `live: false`) if
     * Printful is unconfigured or unavailable.
     */
    public function catalogProducts(PrintfulService $printful): JsonResponse
    {
        $products = $printful->getCatalogProducts();

        if (empty($products)) {
            return response()->json([
                'products' => self::FALLBACK_PRODUCTS,
                'live' => false,
            ]);
        }

        return response()->json([
            'products' => $products,
            'live' => true,
        ]);
    }

    /**
     * A single catalog product with its color/style variants.
     */
    public function product(int $id, PrintfulService $printful): JsonResponse
    {
        $product = $printful->getProduct($id);

        if ($product === null) {
            return response()->json([
                'error' => 'Product not found, or Printful is currently unavailable.',
            ], 404);
        }

        return response()->json($product);
    }

    /**
     * Kick off a Printful mockup-generation task for a hat design, and
     * remember the chosen product/variant/design on the hat.
     */
    public function mockup(Request $request, Hat $hat, PrintfulService $printful): JsonResponse
    {
        $validated = $request->validate([
            'printful_product_id' => ['required', 'integer'],
            'printful_variant_id' => ['required', 'integer'],
            'design_file_id' => ['required', 'integer', 'exists:design_files,id'],
        ]);

        $imageUrl = route('design-files.show', ['designFile' => $validated['design_file_id']]);

        $taskKey = $printful->createMockupTask(
            $validated['printful_product_id'],
            [$validated['printful_variant_id']],
            $imageUrl,
        );

        if ($taskKey === null) {
            return response()->json([
                'error' => $this->detailedError($printful, 'Could not start mockup generation.'),
            ], 422);
        }

        $hat->update([
            'printful_product_id' => $validated['printful_product_id'],
            'printful_variant_id' => $validated['printful_variant_id'],
            'design_file_id' => $validated['design_file_id'],
        ]);

        $variantColor = $this->variantColor(
            $printful,
            $validated['printful_product_id'],
            $validated['printful_variant_id'],
        );

        return response()->json([
            'task_key' => $taskKey,
            'variant_color' => $variantColor,
            'color_mismatch' => $this->colorsMismatch($hat->color, $variantColor),
        ]);
    }

    /**
     * Look up the chosen Printful variant's color (null if unavailable).
     */
    protected function variantColor(PrintfulService $printful, int $productId, int $variantId): ?string
    {
        $product = $printful->getProduct($productId);

        if ($product === null) {
            return null;
        }

        $variant = collect($product['variants'])->firstWhere('id', $variantId);

        return $variant['color'] ?? null;
    }

    /**
     * True when both colors are known and neither contains the other
     * (case-insensitive) — e.g. a "Red" hat on a "Black" variant.
     * Null when either color is unknown (no verdict).
     */
    protected function colorsMismatch(?string $hatColor, ?string $variantColor): ?bool
    {
        if (! $hatColor || ! $variantColor) {
            return null;
        }

        $a = mb_strtolower(trim($hatColor));
        $b = mb_strtolower(trim($variantColor));

        return ! (str_contains($a, $b) || str_contains($b, $a));
    }

    /**
     * Poll a mockup-generation task. When it has completed and a `hat_id`
     * query param is supplied, persist the resulting mockup URLs (and cover
     * image) onto that hat.
     */
    public function mockupTask(Request $request, string $taskKey, PrintfulService $printful): JsonResponse
    {
        $result = $printful->getMockupTask($taskKey);

        if ($result === null) {
            return response()->json([
                'error' => 'Could not fetch mockup task status.',
            ], 502);
        }

        if (($result['status'] ?? null) === 'completed') {
            $hatId = $request->integer('hat_id');

            if ($hatId) {
                $hat = Hat::find($hatId);

                if ($hat) {
                    // Printful mockup URLs expire after ~72h — archive each
                    // image into the database and serve it from our own route.
                    $mockupUrls = collect($result['mockups'] ?? [])
                        ->pluck('mockup_url')
                        ->filter()
                        ->values()
                        ->map(fn (string $url, int $index) => $this->archiveMockup($hat, $url, $index))
                        ->all();

                    $hat->update([
                        'mockup_urls' => $mockupUrls,
                        'image_url' => $mockupUrls[0] ?? $hat->image_url,
                    ]);
                }
            }
        }

        return response()->json($result);
    }

    /**
     * Download a Printful mockup and store it as a DesignFile, returning our
     * own permanent URL. Falls back to the (expiring) remote URL on failure.
     */
    protected function archiveMockup(Hat $hat, string $url, int $index): string
    {
        try {
            $response = Http::timeout(20)->get($url);

            if ($response->failed()) {
                return $url;
            }

            $bytes = $response->body();

            $file = DesignFile::create([
                'filename' => "mockup-hat-{$hat->id}-{$index}.jpg",
                'mime' => strtok($response->header('Content-Type') ?: 'image/jpeg', ';'),
                'data' => $bytes,
                'byte_size' => strlen($bytes),
            ]);

            return route('design-files.show', ['designFile' => $file->id]);
        } catch (\Throwable $e) {
            Log::warning('Could not archive mockup image.', ['url' => $url, 'error' => $e->getMessage()]);

            return $url;
        }
    }

    /**
     * Create a draft (unconfirmed, unpaid) Printful order for a hat design.
     * Never auto-confirms — the merchant reviews & pays in their own
     * Printful dashboard.
     */
    public function supplierOrder(Request $request, Hat $hat, PrintfulService $printful): JsonResponse
    {
        if (! $hat->printful_variant_id || ! $hat->design_file_id) {
            return response()->json([
                'error' => 'This hat has no design/variant yet — generate a mockup first.',
            ], 422);
        }

        $validated = $request->validate([
            'recipient' => ['required', 'array'],
            'recipient.name' => ['required', 'string', 'max:255'],
            'recipient.address1' => ['required', 'string', 'max:255'],
            'recipient.city' => ['required', 'string', 'max:255'],
            'recipient.country_code' => ['required', 'string', 'size:2'],
            'recipient.zip' => ['required', 'string', 'max:20'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $imageUrl = route('design-files.show', ['designFile' => $hat->design_file_id]);

        $result = $printful->createDraftOrder(
            $validated['recipient'],
            $hat->printful_variant_id,
            $validated['quantity'],
            $imageUrl,
            $hat->name,
        );

        if ($result === null) {
            return response()->json([
                'error' => $this->detailedError($printful, 'Could not create the supplier order.'),
            ], 422);
        }

        return response()->json([
            'order' => $result,
            'note' => 'Draft order created — confirm & pay in your Printful dashboard.',
        ]);
    }

    /**
     * Build a client-facing error that includes Printful's own message, plus
     * an actionable hint for the common "no store connected" case.
     */
    protected function detailedError(PrintfulService $printful, string $fallback): string
    {
        $detail = $printful->lastError();

        if ($detail === null) {
            return $fallback;
        }

        $error = "{$fallback} Printful said: {$detail}";

        if (str_contains($detail, 'store_id')) {
            $error .= ' — Your Printful account needs a store: create one under'
                .' Stores → Add store → "Manual order platform / API", then set'
                .' PRINTFUL_STORE_ID on the server.';
        }

        return $error;
    }
}
