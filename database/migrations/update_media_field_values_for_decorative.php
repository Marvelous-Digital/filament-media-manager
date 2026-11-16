<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A decorative image gets an empty alt text wherever it is shown, so screen readers skip it. The
 * typed alt texts are kept for when the flag is turned off again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('filament-media-manager.table_names.field_values'), function (Blueprint $table) {
            $table->boolean('decorative')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table(config('filament-media-manager.table_names.field_values'), function (Blueprint $table) {
            $table->dropColumn('decorative');
        });
    }
};
