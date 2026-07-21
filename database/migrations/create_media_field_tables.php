<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('alt_text')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('media_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_field_value_id')->constrained('media_field_values')->cascadeOnDelete();
            $table->string('variation');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->float('focus_x')->default(0.5);
            $table->float('focus_y')->default(0.5);
            $table->string('path')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['media_field_value_id', 'variation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_conversions');
        Schema::dropIfExists('media_field_values');
    }
};
