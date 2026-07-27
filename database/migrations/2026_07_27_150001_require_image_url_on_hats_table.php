<?php

use App\Services\HatArtService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A hat product with no picture of the hat is not a product. Backfill
     * anything blank with generated art matching the hat's own style and
     * color, then make the column NOT NULL so it can't happen again.
     */
    public function up(): void
    {
        $blank = DB::table('hats')
            ->select('id', 'style', 'color')
            ->where(function ($query) {
                $query->whereNull('image_url')->orWhereRaw("TRIM(image_url) = ''");
            })
            ->get();

        foreach ($blank as $hat) {
            DB::table('hats')->where('id', $hat->id)->update([
                'image_url' => HatArtService::urlFor($hat->style ?? '', $hat->color ?? ''),
            ]);
        }

        Schema::table('hats', function (Blueprint $table) {
            $table->string('image_url')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations. Backfilled art is left in place — it's valid
     * data, just no longer mandatory.
     */
    public function down(): void
    {
        Schema::table('hats', function (Blueprint $table) {
            $table->string('image_url')->nullable()->change();
        });
    }
};
