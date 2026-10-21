<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_runs', function (Blueprint $table): void {
            $table->string('trigger')
                ->default('manual')
                ->after('status')
                ->index();

            $table->string('lock_owner')
                ->nullable()
                ->after('trigger');
        });

        Schema::table('crawl_run_sources', function (Blueprint $table): void {
            /*
             * snapshot_requested:
             * The caller intended to enumerate the complete source.
             *
             * snapshot_complete:
             * NULL  = not yet evaluated
             * true  = crawler proved discovery was exhaustive
             * false = crawler proved it was limited/partial
             */
            $table->boolean('snapshot_requested')
                ->default(false)
                ->after('status');

            $table->boolean('snapshot_complete')
                ->nullable()
                ->after('snapshot_requested');

            $table->text('snapshot_incomplete_reason')
                ->nullable()
                ->after('snapshot_complete');
        });
    }

    public function down(): void
    {
        Schema::table('crawl_run_sources', function (Blueprint $table): void {
            $table->dropColumn([
                'snapshot_requested',
                'snapshot_complete',
                'snapshot_incomplete_reason',
            ]);
        });

        Schema::table('crawl_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'trigger',
                'lock_owner',
            ]);
        });
    }
};
