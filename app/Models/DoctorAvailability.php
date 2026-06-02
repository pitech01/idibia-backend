<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoctorAvailability extends Model
{
    protected $fillable = [
        'doctor_id',
        'day',
        'start_time', // '09:00:00'
        'end_time',   // '17:00:00'
        'is_available'
    ];

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }
}
