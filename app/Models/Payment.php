<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'appointment_id',
        'doctor_id',
        'reference',
        'amount',
        'status',
        'paid_at',
        'type',
        'method',
        'description'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected $casts = [
        'paid_at' => 'datetime',
    ];
}
