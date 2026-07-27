<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hat;
use App\Rules\HatImage;
use App\Services\ClaudeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HatController extends Controller
{
    /**
     * List hats, newest first, 15 per page.
     */
    public function index(): JsonResponse
    {
        return response()->json(Hat::latest()->paginate(15));
    }

    /**
     * Create a new hat.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'max:100'],
            'style' => ['required', 'string', 'max:100'],
            'size' => ['sometimes', 'required', Rule::in(Hat::SIZES)],
            'description' => ['nullable', 'string'],
            'image_url' => ['required', 'string', 'max:2048', new HatImage],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        $validated['image_url'] = trim($validated['image_url']);

        $hat = Hat::create($validated);

        return response()->json($hat, 201);
    }

    /**
     * Show a single hat.
     */
    public function show(Hat $hat): JsonResponse
    {
        return response()->json($hat);
    }

    /**
     * Update a hat. All fields are optional; only fields present in the
     * request are validated and persisted.
     */
    public function update(Request $request, Hat $hat): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'color' => ['sometimes', 'required', 'string', 'max:100'],
            'style' => ['sometimes', 'required', 'string', 'max:100'],
            'size' => ['sometimes', 'required', Rule::in(Hat::SIZES)],
            'description' => ['sometimes', 'nullable', 'string'],
            'image_url' => ['sometimes', 'required', 'string', 'max:2048', new HatImage],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
        ]);

        if (isset($validated['image_url'])) {
            $validated['image_url'] = trim($validated['image_url']);
        }

        $hat->update($validated);

        return response()->json($hat);
    }

    /**
     * Delete a hat.
     */
    public function destroy(Hat $hat): JsonResponse
    {
        $hat->delete();

        return response()->json(null, 204);
    }

    /**
     * Generate a marketing description for a hat via Claude.
     *
     * Always responds 200. On AI failure, `description` is null and an
     * `error` message is included so the frontend can prompt manual entry.
     */
    public function generateDescription(Request $request, ClaudeService $claude): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'max:100'],
            'style' => ['required', 'string', 'max:100'],
        ]);

        $description = $claude->generateHatDescription(
            $validated['name'],
            $validated['color'],
            $validated['style'],
        );

        return response()->json([
            'description' => $description,
            'error' => $description === null ? 'AI description generation is currently unavailable.' : null,
        ]);
    }
}
