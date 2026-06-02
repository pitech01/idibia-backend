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
        Schema::table('doctors', function (Blueprint $table) {
            if (!Schema::hasColumn('doctors', 'consultation_fee')) {
                $table->decimal('consultation_fee', 10, 2)->default(0)->after('specialty');
            }
            if (!Schema::hasColumn('doctors', 'consultation_duration')) {
                $table->integer('consultation_duration')->default(30)->after('consultation_fee'); // in minutes
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            //
        });
    }
};
