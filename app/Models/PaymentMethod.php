<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'method_type',
        'provider',
        'last4',
        'brand',
        'exp_month',
        'exp_year',
        'authorization_code',
        'bank_name',
        'account_name',
        'is_default'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
