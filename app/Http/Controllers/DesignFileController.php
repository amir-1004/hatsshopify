<?php

namespace App\Http\Controllers;

use App\Models\DesignFile;
use Illuminate\Http\Response;

class DesignFileController extends Controller
{
    /**
     * Stream a stored design file's bytes with the correct content type.
     * Public and cacheable so Printful's mockup generator / order API can
     * fetch it by URL — we have no persistent disk to serve it from
     * otherwise.
     */
    public function show(DesignFile $designFile): Response
    {
        return response($designFile->data, 200, [
            'Content-Type' => $designFile->mime,
            'Content-Length' => (string) $designFile->byte_size,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
