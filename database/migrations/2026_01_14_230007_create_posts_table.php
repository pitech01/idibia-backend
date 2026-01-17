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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('category'); // 'General Health', 'Nutrition', etc.
            $table->enum('type', ['article', 'video'])->default('article');
            $table->text('description')->nullable(); // Short excerpt
            $table->longText('content'); // Full HTML or text content
            $table->string('image_url')->nullable();
            $table->string('time_to_read')->nullable(); // e.g. "5 min read" or "5:45"
            $table->boolean('is_featured')->default(false);
            $table->string('author_name')->default('Dr. Idibia');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
