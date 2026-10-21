<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('event_source_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Current canonical Event this source publication belongs to.
             *
             * Nullable because a SourceEvent may exist before it has been
             * fetched / normalized / attached to an Event.
             *
             * IMPORTANT:
             * Do not make this unique. Later, multiple SourceEvents may point
             * to the same canonical Event.
             */
            $table->foreignId('event_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            /*
             * Original ID supplied by the source/API when available.
             *
             * Examples:
             * Tribe event ID
             * Geneva Tourism event ID
             * AllInOne event ID
             */
            $table->string('external_id')
                ->nullable();

            /*
             * Stable SHA-256 identity for this event within this source.
             *
             * This answers:
             * "Is this the same source event?"
             *
             * It must NOT depend on changing event content.
             */
            $table->char('source_key', 64);

            /*
             * Most recent discovery/reference fingerprint.
             *
             * This can change if the listing/reference information changes,
             * while source_key remains stable.
             */
            $table->char('reference_hash', 64)
                ->nullable();

            $table->text('canonical_url')
                ->nullable();

            /*
             * Hash of stable/canonicalized RawEvent evidence.
             *
             * This answers:
             * "Has the source event content changed?"
             */
            $table->char('content_hash', 64)
                ->nullable();

            /*
             * HTTP conditional-request metadata.
             */
            $table->string('etag')
                ->nullable();

            $table->string('last_modified')
                ->nullable();

            $table->string('status')
                ->default('active')
                ->index();

            $table->timestamp('first_seen_at')
                ->nullable();

            $table->timestamp('last_seen_at')
                ->nullable()
                ->index();

            $table->timestamp('last_fetched_at')
                ->nullable();

            $table->timestamp('last_changed_at')
                ->nullable();

            $table->timestamp('missing_since_at')
                ->nullable();

            $table->timestamps();

            $table->unique(
                ['event_source_id', 'source_key'],
                'source_event_source_key_unique'
            );

            $table->index(
                ['event_source_id', 'external_id'],
                'source_event_external_id_index'
            );
        });

        Schema::table('crawl_run_event_results', function (Blueprint $table): void {
            $table->foreignId('source_event_id')
                ->nullable()
                ->after('event_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_run_event_results', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_event_id');
        });

        Schema::dropIfExists('source_events');
    }
};
