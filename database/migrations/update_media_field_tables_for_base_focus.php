<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * A base focus point on each field value, which variations follow unless they have their own
 * (custom_focus). Conversions already moved off the centre, or using their own image, keep
 * their focus, so nothing shifts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('filament-media-manager.table_names');

        Schema::table($tables['field_values'], function (Blueprint $table) {
            $table->float('focus_x')->default(0.5);
            $table->float('focus_y')->default(0.5);
        });

        Schema::table($tables['conversions'], function (Blueprint $table) {
            $table->boolean('custom_focus')->default(false);
        });

        DB::table($tables['conversions'])
            ->where(fn (Builder $query) => $query
                ->whereNotNull('media_id')
                ->orWhere('focus_x', '!=', 0.5)
                ->orWhere('focus_y', '!=', 0.5))
            ->update(['custom_focus' => true]);
    }

    public function down(): void
    {
        $tables = config('filament-media-manager.table_names');

        Schema::table($tables['conversions'], function (Blueprint $table) {
            $table->dropColumn('custom_focus');
        });

        Schema::table($tables['field_values'], function (Blueprint $table) {
            $table->dropColumn(['focus_x', 'focus_y']);
        });
    }
};
