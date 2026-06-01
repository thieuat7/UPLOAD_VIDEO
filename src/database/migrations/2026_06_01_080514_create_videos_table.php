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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('original_path')->nullable(); // Đường dẫn video gốc lưu tạm trên ổ local
            $table->string('hls_path')->nullable();      // Đường dẫn file playlist .m3u8 trên MinIO
            $table->string('status')->default('pending'); // Trạng thái: pending, processing, completed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
