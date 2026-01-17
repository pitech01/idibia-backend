<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MedicalRecordController extends Controller
{
    public function index(Request $request)
    {
        $records = \App\Models\MedicalRecord::where('patient_id', $request->user()->id)
            ->orderBy('record_date', 'desc')
            ->get();
        return response()->json($records);
    }

    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required',
            'record_date' => 'required|date',
            'doctor_name' => 'required',
            'file' => 'nullable|file|max:10240', // 10MB max
            'title' => 'nullable|string'
        ]);

        $path = null;
        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('records', 'public');
        }

        $title = $request->title ?: ($request->type . ' from ' . $request->doctor_name);

        $record = \App\Models\MedicalRecord::create([
            'patient_id' => $request->user()->id,
            'type' => $request->type,
            'title' => $title,
            'doctor_name' => $request->doctor_name,
            'record_date' => $request->record_date,
            'file_path' => $path,
            'status' => 'Pending', // Default status
            'description' => $request->description,
        ]);

        return response()->json($record, 201);
    }
}
