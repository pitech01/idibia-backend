<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('doctor_ratings')) {
            Schema::create('doctor_ratings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('appointment_id')->constrained('appointments')->onDelete('cascade');
                $table->foreignId('patient_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('doctor_id')->constrained('users')->onDelete('cascade');
                $table->unsignedTinyInteger('rating'); // 1 to 5
                $table->text('comment')->nullable();
                $table->json('tags')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('doctors')) {
            Schema::table('doctors', function (Blueprint $table) {
                if (!Schema::hasColumn('doctors', 'rating')) {
                    $table->decimal('rating', 3, 2)->default(5.00)->after('status');
                }
                if (!Schema::hasColumn('doctors', 'reviews_count')) {
                    $table->unsignedInteger('reviews_count')->default(0)->after('rating');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_ratings');

        if (Schema::hasTable('doctors')) {
            Schema::table('doctors', function (Blueprint $table) {
                if (Schema::hasColumn('doctors', 'reviews_count')) {
                    $table->dropColumn('reviews_count');
                }
                if (Schema::hasColumn('doctors', 'rating')) {
                    $table->dropColumn('rating');
                }
            });
        }
    }
};
