<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {

            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->decimal('processing_seconds', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {

            $table->dropColumn([
                'processing_started_at',
                'completed_at',
                'processing_seconds',
            ]);
        });
    }
};