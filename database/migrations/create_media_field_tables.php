<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('filament-media-manager.table_names');

        Schema::create($tables['field_values'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('media_id')->constrained($tables['media'])->cascadeOnDelete();
            $table->string('alt_text')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create($tables['conversions'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('media_field_value_id')->constrained($tables['field_values'])->cascadeOnDelete();
            $table->string('variation');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->float('focus_x')->default(0.5);
            $table->float('focus_y')->default(0.5);
            $table->float('zoom')->default(1.0);
            $table->string('path')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('media_id')->nullable()->constrained($tables['media'])->nullOnDelete();
            $table->string('alt_text')->nullable();
            $table->timestamps();

            $table->unique(['media_field_value_id', 'variation']);
        });
    }

    public function down(): void
    {
        $tables = config('filament-media-manager.table_names');

        Schema::dropIfExists($tables['conversions']);
        Schema::dropIfExists($tables['field_values']);
    }
};
