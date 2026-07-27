<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'color',
    'style',
    'description',
    'image_url',
    'price',
    'printful_product_id',
    'printful_variant_id',
    'design_file_id',
    'mockup_urls',
])]
class Hat extends Model
{
    /** @use HasFactory<\Database\Factories\HatFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'mockup_urls' => 'array',
        ];
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The Design Studio upload (or rendered text design) currently applied
     * to this hat's Printful mockup/order, if any.
     *
     * @return BelongsTo<DesignFile, $this>
     */
    public function designFile(): BelongsTo
    {
        return $this->belongsTo(DesignFile::class);
    }
}
