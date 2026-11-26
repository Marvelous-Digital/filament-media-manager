<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The usage index: one row per (media, place that references it). Rebuilt by the host application
 * (see MediaUsage); the media library only reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('filament-media-manager.table_names');

        Schema::create($tables['usages'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('media_id')->constrained($tables['media'])->cascadeOnDelete();
            $table->foreignId('field_value_id')->nullable()->constrained($tables['field_values'])->cascadeOnDelete();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->string('source');
            $table->unsignedBigInteger('revision_id')->nullable();
            $table->string('locale')->nullable();
            $table->timestamps();

            $table->index('media_id');
            $table->index('field_value_id');
            $table->index(['owner_type', 'owner_id', 'source', 'revision_id']);
        });

        Schema::create($tables['usage_state'], function (Blueprint $table) {
            $table->id();
            $table->timestamp('indexed_at')->nullable();
        });
    }

    public function down(): void
    {
        $tables = config('filament-media-manager.table_names');

        Schema::dropIfExists($tables['usage_state']);
        Schema::dropIfExists($tables['usages']);
    }
};
