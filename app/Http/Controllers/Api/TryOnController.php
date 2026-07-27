<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hat;
use App\Services\HatSizingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TryOnController extends Controller
{
    /**
     * Recommend a hat size from a try-on measurement.
     *
     * Callers send either the pixel distances the browser measured off the
     * face landmarks (`interpupillary_px` + `face_width_px`) or, if they
     * already know it, a `head_circumference_cm`. No image data is accepted
     * or stored — only these numbers ever leave the shopper's device.
     */
    public function recommend(Request $request, HatSizingService $sizing): JsonResponse
    {
        $validated = $request->validate([
            'interpupillary_px' => ['required_without:head_circumference_cm', 'numeric', 'gt:0'],
            'face_width_px' => ['required_with:interpupillary_px', 'numeric', 'gt:0'],
            'head_circumference_cm' => ['required_without:interpupillary_px', 'numeric', 'between:35,80'],
            'hat_id' => ['nullable', 'integer', 'exists:hats,id'],
        ]);

        $measurement = isset($validated['head_circumference_cm'])
            ? ['head_circumference_cm' => round((float) $validated['head_circumference_cm'], 1)]
            : $sizing->measureFromPixels(
                (float) $validated['interpupillary_px'],
                (float) $validated['face_width_px'],
            );

        $circumference = $measurement['head_circumference_cm'];

        if ($circumference < 35 || $circumference > 80) {
            throw ValidationException::withMessages([
                'face_width_px' => 'That measurement does not look like a head — try a photo where your whole face is visible.',
            ]);
        }

        $hat = isset($validated['hat_id']) ? Hat::find($validated['hat_id']) : null;

        return response()->json(array_merge($measurement, $sizing->recommend($circumference, $hat)));
    }
}
