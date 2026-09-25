<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('prescriptions')) {
            Schema::create('prescriptions', function (Blueprint $table) {
                $table->id();
                $table->string('prescription_number')->unique();
                $table->foreignId('patient_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('doctor_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
                $table->string('diagnosis')->nullable();
                $table->text('clinical_notes')->nullable();
                $table->decimal('total_amount', 10, 2)->default(0.00);
                $table->enum('payment_status', ['unpaid', 'paid', 'refunded'])->default('unpaid');
                $table->enum('status', ['issued', 'dispensed', 'cancelled'])->default('issued');
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('prescription_items')) {
            Schema::create('prescription_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('prescription_id')->constrained('prescriptions')->onDelete('cascade');
                $table->string('medication_name');
                $table->string('dosage_form')->default('Tablet'); // Tablet, Capsule, Syrup, Injection, Cream
                $table->string('strength')->nullable(); // e.g., 500mg, 10ml
                $table->string('frequency'); // e.g., Twice daily, 8-hourly
                $table->string('duration'); // e.g., 5 days, 1 week
                $table->text('instructions')->nullable(); // e.g., Take after meals
                $table->integer('quantity')->default(1);
                $table->decimal('unit_price', 10, 2)->default(0.00);
                $table->decimal('total_price', 10, 2)->default(0.00);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
    }
};
