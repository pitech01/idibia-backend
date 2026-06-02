<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'patient_id',
        'doctor_id',
        'appointment_date',
        'start_time',
        'end_time',
        'status',
        'type',
        'reason',
        'notes',
        'meeting_link',
        'amount',
        'payment_status',
        'payment_reference',
        'earnings_distributed',
        'duration',
        'cancellation_reason',
        'call_started_at',
        'call_ended_at',
        'call_status',
    ];

    protected $casts = [
        'call_started_at' => 'datetime',
        'call_ended_at' => 'datetime',
        'appointment_date' => 'date',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
