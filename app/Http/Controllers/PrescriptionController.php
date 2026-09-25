<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrescriptionController extends Controller
{
    /**
     * List all prescriptions for the current authenticated user.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'patient') {
            $prescriptions = Prescription::with(['doctor.doctor', 'items', 'appointment'])
                ->where('patient_id', $user->id)
                ->latest()
                ->get();
        } elseif ($user->role === 'doctor') {
            $prescriptions = Prescription::with(['patient.patient', 'items', 'appointment'])
                ->where('doctor_id', $user->id)
                ->latest()
                ->get();
        } else {
            $prescriptions = Prescription::with(['doctor.doctor', 'patient.patient', 'items'])
                ->latest()
                ->get();
        }

        return response()->json($prescriptions);
    }

    /**
     * Store a newly created prescription (issued by doctor).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'doctor' && !in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Only verified medical practitioners can generate e-prescriptions.'], 403);
        }

        $validated = $request->validate([
            'patient_id' => 'required|exists:users,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'diagnosis' => 'required|string|max:500',
            'clinical_notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.medication_name' => 'required|string|max:255',
            'items.*.dosage_form' => 'nullable|string|max:100',
            'items.*.strength' => 'nullable|string|max:100',
            'items.*.frequency' => 'required|string|max:100',
            'items.*.duration' => 'required|string|max:100',
            'items.*.instructions' => 'nullable|string|max:500',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $prescriptionNumber = 'RX-' . strtoupper(date('ymd')) . '-' . strtoupper(Str::random(5));

            $totalAmount = 0.00;
            foreach ($validated['items'] as $item) {
                $qty = (int) ($item['quantity'] ?? 1);
                $unitPrice = (float) ($item['unit_price'] ?? 0.00);
                $totalAmount += ($qty * $unitPrice);
            }

            $prescription = Prescription::create([
                'prescription_number' => $prescriptionNumber,
                'patient_id' => $validated['patient_id'],
                'doctor_id' => $user->id,
                'appointment_id' => $validated['appointment_id'] ?? null,
                'diagnosis' => $validated['diagnosis'],
                'clinical_notes' => $validated['clinical_notes'] ?? null,
                'total_amount' => $totalAmount,
                'payment_status' => $totalAmount > 0 ? 'unpaid' : 'paid',
                'status' => 'issued',
                'paid_at' => $totalAmount > 0 ? null : now(),
            ]);

            foreach ($validated['items'] as $item) {
                $qty = (int) ($item['quantity'] ?? 1);
                $unitPrice = (float) ($item['unit_price'] ?? 0.00);
                $totalPrice = $qty * $unitPrice;

                $prescription->items()->create([
                    'medication_name' => $item['medication_name'],
                    'dosage_form' => $item['dosage_form'] ?? 'Tablet',
                    'strength' => $item['strength'] ?? null,
                    'frequency' => $item['frequency'],
                    'duration' => $item['duration'],
                    'instructions' => $item['instructions'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                ]);
            }

            // Also mirror as a Medical Record entry for patient chart
            MedicalRecord::create([
                'patient_id' => $validated['patient_id'],
                'type' => 'Prescription',
                'title' => 'E-Prescription: ' . $validated['diagnosis'],
                'doctor_name' => $user->name,
                'record_date' => now()->toDateString(),
                'status' => 'Active',
                'description' => 'Prescription #' . $prescriptionNumber . ' issued with ' . count($validated['items']) . ' medication(s).',
            ]);

            DB::commit();

            return response()->json($prescription->load(['patient.patient', 'doctor.doctor', 'items', 'appointment']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to generate e-prescription: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Show detailed e-prescription.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $prescription = Prescription::with(['patient.patient', 'doctor.doctor', 'items', 'appointment'])->findOrFail($id);

        if ((int) $prescription->patient_id !== (int) $user->id && 
            (int) $prescription->doctor_id !== (int) $user->id && 
            !in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Unauthorized to view this prescription.'], 403);
        }

        return response()->json($prescription);
    }

    /**
     * Pay for prescription medications (via Wallet balance or online Paystack payment).
     */
    public function pay(Request $request, $id)
    {
        $user = $request->user();
        $prescription = Prescription::with('items')->findOrFail($id);

        if ((int) $prescription->patient_id !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($prescription->payment_status === 'paid') {
            return response()->json(['message' => 'Prescription medication is already paid for.', 'prescription' => $prescription]);
        }

        $method = $request->input('method', 'wallet'); // 'wallet' or 'paystack'

        DB::beginTransaction();
        try {
            if ($method === 'wallet') {
                $patient = $user->patient;
                if (!$patient || (float) $patient->wallet_balance < (float) $prescription->total_amount) {
                    return response()->json(['message' => 'Insufficient wallet balance to pay for this medication.'], 400);
                }

                $patient->wallet_balance -= $prescription->total_amount;
                $patient->save();

                $user->payments()->create([
                    'amount' => $prescription->total_amount,
                    'reference' => 'RX-' . strtoupper(Str::random(10)),
                    'status' => 'success',
                    'type' => 'debit',
                    'method' => 'wallet',
                    'description' => 'Medication Payment for ' . $prescription->prescription_number,
                    'paid_at' => now(),
                ]);
            }

            $prescription->update([
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Medication paid successfully. Official E-Prescription is now unlocked for download.',
                'prescription' => $prescription->fresh()->load(['doctor.doctor', 'items', 'appointment'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Payment processing failed: ' . $e->getMessage()], 500);
        }
    }
}
