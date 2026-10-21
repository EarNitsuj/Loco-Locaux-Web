<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('commune_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('event_source_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('event_url', 2048);

            $table->text('image_url')->nullable();

            $table->longText('raw_html');

            $table->json('normalized_json')
                ->nullable();

            $table->decimal('normalization_cost_usd', 10, 6)
                ->nullable();

            $table->unsignedInteger('normalization_input_tokens')->nullable();

            $table->unsignedInteger('normalization_output_tokens')->nullable();

            $table->string('normalization_model')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
