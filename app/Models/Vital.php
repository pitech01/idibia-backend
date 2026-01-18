<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vital extends Model
{
    protected $fillable = ['patient_id', 'type', 'value', 'unit', 'status'];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}
