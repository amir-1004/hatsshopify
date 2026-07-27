<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A design image (upload or canvas render) stored directly in the database.
 * We have no persistent disk on Render, so this is what gets served back to
 * Printful (and the browser) via the public /design-files/{designFile} route.
 */
#[Fillable(['filename', 'mime', 'data', 'byte_size'])]
class DesignFile extends Model
{
    /** @use HasFactory<\Database\Factories\DesignFileFactory> */
    use HasFactory;

    /**
     * The image is stored base64-encoded in a text column: PDO can't bind
     * raw binary into Postgres bytea as a plain string parameter (fails
     * UTF-8 validation), and base64 text is portable across pgsql/sqlite.
     * Callers still read/write raw bytes — encoding is transparent here.
     */
    protected function data(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => $value === null ? null : base64_decode(
                is_resource($value) ? stream_get_contents($value) : $value
            ),
            set: fn (mixed $value) => $value === null ? null : base64_encode($value),
        );
    }
}
