<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communes', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->unsignedBigInteger('population')->nullable();
            $table->text('official_website_url')->nullable();
            $table->timestamps();
        });

        Schema::create('event_sources', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('commune_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            $table->string('name');
            $table->text('listing_url');
            $table->string('adapter')->nullable();
            $table->json('configuration')->nullable();

            $table->boolean('is_active')
                ->default(true)
                ->index();

            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('last_success_at')->nullable();

            $table->unsignedInteger('consecutive_failures')
                ->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sources');
        Schema::dropIfExists('communes');
    }
};
