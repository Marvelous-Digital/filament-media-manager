<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('filament-media-manager.table_names');

        Schema::create($tables['folders'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained($tables['folders'])->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['parent_id', 'name']);
        });

        Schema::create($tables['media'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained($tables['folders'])->nullOnDelete();
            $table->string('name');
            $table->string('file_name');
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type');
            $table->string('type');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('thumbnail_status')->default('pending');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $tables = config('filament-media-manager.table_names');

        Schema::dropIfExists($tables['media']);
        Schema::dropIfExists($tables['folders']);
    }
};
