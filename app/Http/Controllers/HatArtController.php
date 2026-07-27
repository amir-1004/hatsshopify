<?php

namespace App\Http\Controllers;

use App\Services\HatArtService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HatArtController extends Controller
{
    /**
     * Render generated hat artwork for a style/color pair.
     *
     * Both inputs are whitelisted by the service (style against a known
     * list, color against names/hex), so the SVG we emit never contains
     * caller-supplied markup.
     */
    public function show(Request $request, HatArtService $art, string $style): Response
    {
        $svg = $art->render($style, (string) $request->query('color', ''));

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
