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
        if (!Schema::hasTable('availabilities')) {
            Schema::create('availabilities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('doctor_id')->constrained('users')->onDelete('cascade');
                $table->string('day'); // Monday, Tuesday, etc.
                $table->boolean('is_available')->default(true);
                $table->time('start_time')->default('09:00:00');
                $table->time('end_time')->default('17:00:00');
                $table->timestamps();
                
                $table->unique(['doctor_id', 'day']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('availabilities');
    }
};
