<?php

namespace App\Services;

use App\Models\Hat;

/**
 * Turns face-landmark pixel measurements into a real head circumference and
 * a hat size.
 *
 * The browser does the face detection (the photo never leaves the device)
 * and sends back three pixel distances; all the geometry and the sizing
 * table live here so they're unit-testable and can't drift between the
 * try-on page and anything else that needs a size.
 */
class HatSizingService
{
    /**
     * Mean adult interpupillary distance, in millimetres. This is the ruler:
     * eyes are the most reliably located landmarks on a face, so we scale
     * every other measurement by how many pixels apart the pupils are.
     */
    public const AVERAGE_IPD_MM = 63.0;

    /**
     * Face landmarks trace the cheekbones; the skull (plus scalp and hair)
     * is wider than the face is at that line.
     */
    public const FACE_TO_SKULL_WIDTH = 1.12;

    /**
     * Cephalic index — skull breadth as a fraction of skull length. ~0.78
     * is the mesocephalic average, so length = breadth / 0.78.
     */
    public const CEPHALIC_INDEX = 0.78;

    /**
     * Sizing table in centimetres of head circumference. Ordered smallest
     * first; the last entry catches everything above it.
     *
     * @var array<int, array{size: string, max: float|null}>
     */
    protected const SIZE_TABLE = [
        ['size' => 'XS', 'max' => 54.5],
        ['size' => 'S', 'max' => 56.5],
        ['size' => 'M', 'max' => 58.5],
        ['size' => 'L', 'max' => 60.5],
        ['size' => 'XL', 'max' => null],
    ];

    /** A one-size-fits-most hat is comfortable across this range. */
    public const UNIVERSAL_RANGE = [54.5, 60.5];

    /**
     * Head circumference in cm from pixel measurements taken off a photo.
     *
     * Models the skull as an ellipse: the measured face width gives the
     * breadth, the cephalic index gives the depth, and Ramanujan's
     * approximation gives the perimeter.
     */
    public function circumferenceFromPixels(float $interpupillaryPx, float $faceWidthPx): float
    {
        return $this->measureFromPixels($interpupillaryPx, $faceWidthPx)['head_circumference_cm'];
    }

    /**
     * The full set of derived skull measurements, in centimetres.
     *
     * @return array{mm_per_px: float, skull_breadth_cm: float, skull_depth_cm: float, head_circumference_cm: float}
     */
    public function measureFromPixels(float $interpupillaryPx, float $faceWidthPx): array
    {
        if ($interpupillaryPx <= 0 || $faceWidthPx <= 0) {
            throw new \InvalidArgumentException('Measurements must be positive pixel distances.');
        }

        $mmPerPx = self::AVERAGE_IPD_MM / $interpupillaryPx;

        $breadthCm = $faceWidthPx * $mmPerPx * self::FACE_TO_SKULL_WIDTH / 10;
        $lengthCm = $breadthCm / self::CEPHALIC_INDEX;

        return [
            'mm_per_px' => round($mmPerPx, 4),
            'skull_breadth_cm' => round($breadthCm, 1),
            'skull_depth_cm' => round($lengthCm, 1),
            'head_circumference_cm' => round($this->ellipsePerimeter($breadthCm / 2, $lengthCm / 2), 1),
        ];
    }

    /**
     * Ramanujan's second approximation for the perimeter of an ellipse.
     */
    protected function ellipsePerimeter(float $a, float $b): float
    {
        return M_PI * (3 * ($a + $b) - sqrt((3 * $a + $b) * ($a + 3 * $b)));
    }

    /**
     * The hat size for a head circumference in cm.
     */
    public function sizeFor(float $circumferenceCm): string
    {
        foreach (self::SIZE_TABLE as $row) {
            if ($row['max'] === null || $circumferenceCm < $row['max']) {
                return $row['size'];
            }
        }

        return 'XL';
    }

    /**
     * The cm range a given size covers, as [min, max]; either end may be
     * null where the size is open-ended.
     *
     * @return array{0: float|null, 1: float|null}
     */
    public function rangeFor(string $size): array
    {
        if (strcasecmp($size, 'Universal') === 0) {
            return self::UNIVERSAL_RANGE;
        }

        $min = null;

        foreach (self::SIZE_TABLE as $row) {
            if (strcasecmp($row['size'], $size) === 0) {
                return [$min, $row['max']];
            }

            $min = $row['max'];
        }

        return [null, null];
    }

    /**
     * Does the hat the shopper is looking at actually fit their head?
     */
    public function fits(string $hatSize, float $circumferenceCm): bool
    {
        [$min, $max] = $this->rangeFor($hatSize);

        if ($min !== null && $circumferenceCm < $min) {
            return false;
        }

        return $max === null || $circumferenceCm < $max;
    }

    /**
     * A short, human sentence about the fit — this is what the try-on page
     * shows under the 3D preview.
     */
    public function fitNote(float $circumferenceCm, ?Hat $hat = null): string
    {
        $recommended = $this->sizeFor($circumferenceCm);
        $measured = number_format($circumferenceCm, 1);

        if ($hat === null) {
            return "Your head measures about {$measured} cm — size {$recommended} is your best fit.";
        }

        if ($this->fits($hat->size, $circumferenceCm)) {
            return strcasecmp($hat->size, 'Universal') === 0
                ? "This one-size hat should sit comfortably on your {$measured} cm head."
                : "Size {$hat->size} is right for your {$measured} cm head.";
        }

        [$min] = $this->rangeFor($hat->size);
        $direction = ($min !== null && $circumferenceCm < $min) ? 'loose' : 'tight';

        return "This hat is a {$hat->size} — it'll sit {$direction} on your {$measured} cm head. "
            ."Size {$recommended} is your best fit.";
    }

    /**
     * Full recommendation payload for the API.
     *
     * @return array<string, mixed>
     */
    public function recommend(float $circumferenceCm, ?Hat $hat = null): array
    {
        $recommended = $this->sizeFor($circumferenceCm);
        [$min, $max] = $this->rangeFor($recommended);

        return [
            'head_circumference_cm' => round($circumferenceCm, 1),
            'recommended_size' => $recommended,
            'size_range_cm' => ['min' => $min, 'max' => $max],
            'available_sizes' => Hat::SIZES,
            'hat_size' => $hat?->size,
            'hat_fits' => $hat === null ? null : $this->fits($hat->size, $circumferenceCm),
            'note' => $this->fitNote($circumferenceCm, $hat),
        ];
    }
}
