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
        // 1. Add distribution flag to appointments
        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('earnings_distributed')->default(false)->after('payment_reference');
        });

        // 2. Add wallet balance to doctors
        Schema::table('doctors', function (Blueprint $table) {
            $table->decimal('wallet_balance', 15, 2)->default(0.00)->after('status');
        });

        // 3. Add wallet balance to users (primarily for Admin collection)
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('wallet_balance', 15, 2)->default(0.00)->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('earnings_distributed');
        });

        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('wallet_balance');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wallet_balance');
        });
    }
};
