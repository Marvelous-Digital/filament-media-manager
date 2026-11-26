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
        $tables = $this->tables();

        Schema::create($tables['usages'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('media_id')->constrained($tables['media'])->cascadeOnDelete();
            $table->foreignId('field_value_id')->nullable()->constrained($tables['field_values'])->cascadeOnDelete();
            $table->string('owner_type');
            $table->string('owner_id', 64);
            $table->string('source', 16);
            $table->unsignedBigInteger('revision_id')->nullable();
            $table->string('locale')->nullable();
            $table->timestamps();

            $table->index('media_id');
            $table->index('field_value_id');
            // Named by hand: the generated name passes MySQL's 64 character identifier limit.
            $table->index(['owner_type', 'owner_id', 'source', 'revision_id'], $tables['usages'] . '_owner_index');
        });

        Schema::create($tables['usage_state'], function (Blueprint $table) {
            $table->id();
            $table->timestamp('indexed_at')->nullable();
        });
    }

    public function down(): void
    {
        $tables = $this->tables();

        Schema::dropIfExists($tables['usage_state']);
        Schema::dropIfExists($tables['usages']);
    }

    /**
     * A config published before this table existed replaces table_names whole, so every key falls back.
     *
     * @return array{media: string, field_values: string, usages: string, usage_state: string}
     */
    protected function tables(): array
    {
        $names = config('filament-media-manager.table_names', []);

        return [
            'media' => $names['media'] ?? 'media_manager_media',
            'field_values' => $names['field_values'] ?? 'media_manager_field_values',
            'usages' => $names['usages'] ?? 'media_manager_usages',
            'usage_state' => $names['usage_state'] ?? 'media_manager_usage_state',
        ];
    }
};
