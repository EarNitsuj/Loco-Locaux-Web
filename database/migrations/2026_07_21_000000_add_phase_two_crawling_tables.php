<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_runs', function (Blueprint $table): void {
            $table->id();

            $table->string('status')
                ->default('pending')
                ->index();

            $table->json('options')->nullable();

            $table->unsignedInteger('sources_total')->default(0);
            $table->unsignedInteger('sources_completed')->default(0);

            $table->unsignedInteger('events_created')->default(0);
            $table->unsignedInteger('events_updated')->default(0);
            $table->unsignedInteger('events_unchanged')->default(0);
            $table->unsignedInteger('events_skipped')->default(0);
            $table->unsignedInteger('events_failed')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->text('failure_reason')->nullable();

            $table->timestamps();
        });

        Schema::create('crawl_run_sources', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('crawl_run_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('event_source_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('status')
                ->default('pending')
                ->index();

            $table->unsignedInteger('references_discovered')->default(0);
            $table->unsignedInteger('references_queued')->default(0);

            $table->unsignedInteger('events_created')->default(0);
            $table->unsignedInteger('events_updated')->default(0);
            $table->unsignedInteger('events_unchanged')->default(0);
            $table->unsignedInteger('events_skipped')->default(0);
            $table->unsignedInteger('events_failed')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->unique(
                ['crawl_run_id', 'event_source_id'],
                'crawl_run_source_unique'
            );
        });

        Schema::create('crawl_run_event_results', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('crawl_run_source_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('event_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('status')->index();

            $table->char('reference_hash', 64);

            $table->text('event_reference_url');

            $table->string('external_id')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(
                ['crawl_run_source_id', 'reference_hash'],
                'crawl_event_result_ref_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_run_event_results');
        Schema::dropIfExists('crawl_run_sources');
        Schema::dropIfExists('crawl_runs');
    }
};
