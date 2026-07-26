<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'shopify_order_id',
    'hat_id',
    'customer_email',
    'customer_name',
    'order_number',
    'total_price',
    'currency',
    'status',
    'quantity',
    'order_data',
])]
class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_data' => 'array',
            'total_price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Hat, $this>
     */
    public function hat(): BelongsTo
    {
        return $this->belongsTo(Hat::class);
    }
}
