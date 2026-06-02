<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payment_methods', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('user_id')->constrained()->onDelete('cascade');
            $blueprint->string('method_type'); // card, bank_account
            $blueprint->string('provider'); // paystack
            $blueprint->string('last4')->nullable();
            $blueprint->string('brand')->nullable(); // visa, mastercard
            $blueprint->string('exp_month')->nullable();
            $blueprint->string('exp_year')->nullable();
            $blueprint->string('authorization_code')->nullable(); // For Paystack recurring
            $blueprint->string('bank_name')->nullable();
            $blueprint->string('account_name')->nullable();
            $blueprint->boolean('is_default')->default(false);
            $blueprint->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('payment_methods');
    }
};
