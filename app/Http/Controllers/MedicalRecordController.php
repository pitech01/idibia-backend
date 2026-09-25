<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MedicalRecord;
use App\Models\Appointment;

class MedicalRecordController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            $targetPatientId = null;
            if ($request->has('patient_id')) {
                $reqId = (int) $request->input('patient_id');
                if (in_array($user->role, ['doctor', 'admin', 'super_admin'])) {
                    $targetPatientId = $reqId;
                } else {
                    $targetPatientId = $user->id;
                }
            } elseif ($user->role === 'patient') {
                $targetPatientId = $user->id;
            }

            $query = MedicalRecord::with(['patient', 'doctor.doctor']);
            if ($targetPatientId) {
                $query->where('patient_id', $targetPatientId);
            } elseif ($user->role === 'doctor') {
                $query->where('doctor_id', $user->id);
            }

            $records = $query->orderBy('record_date', 'desc')
                ->latest()
                ->get()
                ->map(function ($rec) {
                    $rec->file_url = $rec->file_path ? url('/api/medical-records/' . $rec->id . '/file') : null;
                    return $rec;
                });

            // Also include appointments as clinical consultation encounters if viewing specific patient
            if ($targetPatientId) {
                $existingTitles = $records->pluck('title')->toArray();
                $appointments = Appointment::with(['doctor.doctor'])
                    ->where('patient_id', $targetPatientId)
                    ->whereIn('status', ['completed', 'ongoing', 'confirmed'])
                    ->latest()
                    ->get();

                $appointmentRecords = collect();
                foreach ($appointments as $appt) {
                    $docName = $appt->doctor ? $appt->doctor->name : ($appt->doctor_name ?? 'Attending Doctor');
                    $docSpecialty = ($appt->doctor && $appt->doctor->doctor) ? $appt->doctor->doctor->specialty : 'General Physician';
                    $title = 'Consultation with Dr. ' . $docName . ' (' . $docSpecialty . ')';

                    if (!in_array($title, $existingTitles)) {
                        $appointmentRecords->push((object)[
                            'id' => 100000 + $appt->id,
                            'patient_id' => $targetPatientId,
                            'doctor_id' => $appt->doctor_id,
                            'doctor_name' => 'Dr. ' . $docName,
                            'type' => 'Clinical Note',
                            'title' => $title,
                            'description' => $appt->reason ? "Reason for visit: " . $appt->reason : "Clinical consultation encounter conducted via Idibia Telehealth.",
                            'file_path' => null,
                            'file_url' => null,
                            'record_date' => $appt->appointment_date ? $appt->appointment_date->format('Y-m-d') : now()->format('Y-m-d'),
                            'status' => ucfirst($appt->status),
                            'facility' => 'Idibia Virtual Health Clinic',
                            'is_consultation' => true,
                            'appointment_id' => $appt->id
                        ]);
                    }
                }

                $allRecords = $records->concat($appointmentRecords)->sortByDesc('record_date')->values();
                return response()->json($allRecords);
            }

            return response()->json($records);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MedicalRecord Error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load records: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|string',
            'record_date' => 'required|date',
            'doctor_name' => 'nullable|string',
            'doctor_id' => 'nullable|exists:users,id',
            'facility' => 'nullable|string',
            'file' => 'nullable|file|max:15360', // 15MB max
            'title' => 'nullable|string',
            'description' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        $path = null;
        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('records', 'public');
        }

        $doctorName = $request->doctor_name;
        if (!$doctorName && $request->doctor_id) {
            $docUser = \App\Models\User::find($request->doctor_id);
            if ($docUser) {
                $doctorName = 'Dr. ' . $docUser->name;
            }
        }
        if (!$doctorName) {
            $doctorName = 'Attending Physician';
        }

        $title = $request->title ?: ($request->type . ' - ' . $doctorName);

        $record = MedicalRecord::create([
            'patient_id' => $request->user()->id,
            'doctor_id' => $request->doctor_id,
            'type' => $request->type,
            'title' => $title,
            'doctor_name' => $doctorName,
            'record_date' => $request->record_date,
            'file_path' => $path,
            'status' => $request->status ?: 'Reviewed',
            'facility' => $request->facility ?: 'Idibia Health Network',
            'description' => $request->description,
        ]);

        $record->file_url = $path ? url('/api/medical-records/' . $record->id . '/file') : null;

        return response()->json($record, 201);
    }

    public function getFile(Request $request, $id)
    {
        $record = MedicalRecord::findOrFail($id);

        if (!$record->file_path) {
            return response()->json(['message' => 'No file attached to this medical record'], 404);
        }

        $fullPath = storage_path('app/public/' . $record->file_path);
        if (!file_exists($fullPath)) {
            $fullPath = storage_path('app/' . $record->file_path);
        }
        if (!file_exists($fullPath)) {
            $fullPath = public_path('storage/' . $record->file_path);
        }

        if (!file_exists($fullPath)) {
            return response()->json(['message' => 'File not found on storage server'], 404);
        }

        $mimeType = @mime_content_type($fullPath) ?: 'application/octet-stream';
        $fileName = basename($fullPath);

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => '*'
        ]);
    }
}
