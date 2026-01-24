<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DoctorController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'specialty' => 'required|string',
            'experience_years' => 'required|integer',
            'license_number' => 'required|string',
            'issuing_authority' => 'required|string',
            'practice_type' => 'nullable|string',
            'workplace_name' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'consultation_type' => 'nullable|string',
            'bio' => 'nullable|string',
            'license_document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB
            'id_document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB
        ]);

        $user = $request->user();

        // Handle File Uploads
        $licensePath = null;
        if ($request->hasFile('license_document')) {
            $licensePath = $request->file('license_document')->store('doctors/licenses', 'public');
        }

        $idPath = null;
        if ($request->hasFile('id_document')) {
            $idPath = $request->file('id_document')->store('doctors/ids', 'public');
        }

        $doctor = \App\Models\Doctor::updateOrCreate(
            ['user_id' => $user->id],
            [
                'specialty' => $validated['specialty'],
                'experience_years' => $validated['experience_years'],
                'license_number' => $validated['license_number'],
                'issuing_authority' => $validated['issuing_authority'],
                'practice_type' => $validated['practice_type'] ?? null,
                'workplace_name' => $validated['workplace_name'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'consultation_type' => $validated['consultation_type'] ?? 'both',
                'bio' => $validated['bio'] ?? null,
                'license_document_path' => $licensePath,
                'id_document_path' => $idPath,
                'status' => 'pending_approval'
            ]
        );

        return response()->json(['message' => 'Doctor profile submitted for review.', 'doctor' => $doctor]);
    }
}
