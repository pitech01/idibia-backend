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
        Schema::create('vitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->onDelete('cascade'); // stored on user or patient? Patient model links to user. Usually vitals belong to patient. Let's link to users(id) where role=patient for simplicity as many other tables do, or link to patients(id).
            // Let's check Appointment/MedicalRecord. Appointment uses patient_id (User id). MedicalRecord uses patient_id (User id). 
            // So link to users.
            $table->string('type'); // e.g., 'blood_pressure', 'heart_rate', 'weight', 'blood_sugar'
            $table->string('value');
            $table->string('unit');
            $table->string('status')->default('Normal'); // Normal, High, Low
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vitals');
    }
};
