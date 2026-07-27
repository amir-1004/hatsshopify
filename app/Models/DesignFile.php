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
     * Normalize the binary `data` column on read.
     *
     * Some PDO drivers (notably pgsql) hand back bytea columns as a stream
     * resource rather than a string; flatten that to a string so callers
     * can always treat `data` as raw bytes.
     */
    protected function data(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => is_resource($value) ? stream_get_contents($value) : $value,
        );
    }
}
