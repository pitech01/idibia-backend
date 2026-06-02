<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Availability extends Model
{
    protected $fillable = ['doctor_id', 'day', 'is_available', 'start_time', 'end_time'];

    public function user()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
