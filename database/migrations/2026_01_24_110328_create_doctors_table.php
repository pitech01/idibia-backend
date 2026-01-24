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
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('specialty')->nullable();
            $table->string('sub_specialty')->nullable();
            $table->integer('experience_years')->nullable();
            $table->string('license_number')->nullable();
            $table->string('issuing_authority')->nullable();
            $table->string('practice_type')->nullable(); // Hospital, Clinic, Independent
            $table->string('workplace_name')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('consultation_type')->default('both'); // virtual, physical, both
            $table->text('bio')->nullable();
            $table->string('license_document_path')->nullable();
            $table->string('id_document_path')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->string('status')->default('pending_approval'); // pending_approval, active, rejected
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
