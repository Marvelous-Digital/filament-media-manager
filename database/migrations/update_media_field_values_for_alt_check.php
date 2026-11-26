<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set on a placement whose library image was replaced everywhere: it keeps its alt text, but an editor
 * should check it. Cleared by confirming or editing the alt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('filament-media-manager.table_names.field_values'), function (Blueprint $table) {
            $table->boolean('alt_check_required')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table(config('filament-media-manager.table_names.field_values'), function (Blueprint $table) {
            $table->dropColumn('alt_check_required');
        });
    }
};
