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
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('doctor_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('type'); // lab, prescription, note, scan
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path')->nullable(); // For uploaded files
            $table->string('doctor_name')->nullable(); // For external doctors or manual entry
            $table->date('record_date');
            $table->string('status')->default('Normal'); // Normal, Abnormal, Pending
            $table->string('facility')->nullable(); // Hospital or Lab name
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_records');
    }
};
