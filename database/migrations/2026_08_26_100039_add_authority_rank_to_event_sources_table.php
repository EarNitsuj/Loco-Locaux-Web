<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_sources', function (Blueprint $table): void {
            $table->unsignedSmallInteger('authority_rank')
                ->default(100)
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('event_sources', function (Blueprint $table): void {
            $table->dropColumn('authority_rank');
        });
    }
};
