<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DesignFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DesignFileController extends Controller
{
    /**
     * Store a design upload — either a multipart `file` (image, max 4MB) or
     * a base64 `data_url` (e.g. from the Design Studio's canvas
     * `toDataURL()`). We have no persistent disk on Render, so the bytes
     * are stored in the database and served back via a public route (see
     * routes/web.php) so Printful can fetch them by URL.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => ['required', 'image', 'max:4096'],
            ]);

            $file = $request->file('file');

            $designFile = DesignFile::create([
                'filename' => $file->getClientOriginalName() ?: 'design.png',
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'data' => file_get_contents($file->getRealPath()),
                'byte_size' => $file->getSize(),
            ]);
        } else {
            $validated = $request->validate([
                'data_url' => ['required', 'string'],
            ]);

            [$mime, $binary] = $this->decodeDataUrl($validated['data_url']);

            if ($binary === null) {
                throw ValidationException::withMessages([
                    'data_url' => 'Could not decode the provided data URL.',
                ]);
            }

            $designFile = DesignFile::create([
                'filename' => 'design.'.$this->extensionForMime($mime),
                'mime' => $mime,
                'data' => $binary,
                'byte_size' => strlen($binary),
            ]);
        }

        return response()->json([
            'id' => $designFile->id,
            'url' => route('design-files.show', ['designFile' => $designFile->id]),
        ], 201);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    protected function decodeDataUrl(string $dataUrl): array
    {
        if (! preg_match('/^data:([a-zA-Z0-9\/\+\.\-]+);base64,(.+)$/', $dataUrl, $matches)) {
            return ['application/octet-stream', null];
        }

        $mime = $matches[1];
        $binary = base64_decode($matches[2], true);

        return [$mime, $binary === false ? null : $binary];
    }

    protected function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }
}
