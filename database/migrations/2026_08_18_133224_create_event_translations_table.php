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
        Schema::create('event_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('locale', 10);

            $table->json('normalized_json');

            $table->decimal('translation_cost_usd', 10, 6)
                ->nullable();

            $table->unsignedInteger('translation_input_tokens')
                ->nullable();

            $table->unsignedInteger('translation_output_tokens')
                ->nullable();

            $table->string('translation_model')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'event_id',
                'locale',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_translations');
    }
};
