<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_events', function (Blueprint $table): void {
            $table->char('normalized_content_hash', 64)
                ->nullable()
                ->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('source_events', function (Blueprint $table): void {
            $table->dropColumn('normalized_content_hash');
        });
    }
};
