<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_events', function (Blueprint $table): void {
            $table->id();

            /*
             * The Event currently selected as the public/authoritative
             * representation of this real-world event.
             */
            $table->foreignId('primary_event_id')
                ->nullable()
                ->constrained('events')
                ->nullOnDelete();

            $table->timestamps();
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('canonical_event_id')
                ->nullable()
                ->after('event_source_id')
                ->constrained('canonical_events')
                ->nullOnDelete();

            /*
             * Useful for auditing automatic duplicate decisions.
             *
             * Examples:
             * self
             * deterministic
             * manual
             */
            $table->string('canonical_match_method')
                ->nullable()
                ->after('canonical_event_id');

            $table->decimal(
                'canonical_match_score',
                5,
                4
            )
                ->nullable()
                ->after('canonical_match_method');

            $table->index(
                ['commune_id', 'canonical_event_id'],
                'events_commune_canonical_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex(
                'events_commune_canonical_index'
            );

            $table->dropConstrainedForeignId(
                'canonical_event_id'
            );

            $table->dropColumn([
                'canonical_match_method',
                'canonical_match_score',
            ]);
        });

        Schema::dropIfExists('canonical_events');
    }
};
