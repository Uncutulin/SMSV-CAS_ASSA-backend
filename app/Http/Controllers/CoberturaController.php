<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\CoberturaAceptada;

class CoberturaController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|max:255',
            'name' => 'nullable|string|max:255',
            'templateid' => 'nullable|string|max:255',
            'attach1' => 'nullable|string|max:255',
            'attach2' => 'nullable|string|max:255',
            'attach3' => 'nullable|string|max:255',
            'attach4' => 'nullable|string|max:255',
            'nombre' => 'nullable|string|max:255',
            'dni' => 'nullable|string|max:50',
            'numero_poliza' => 'nullable|string|max:100',
            'fecha_vigencia' => 'nullable|string|max:100',
        ]);

        // Check if coverage is already accepted based on dni, numero_poliza, templateid, and attach1
        $existing = CoberturaAceptada::where('dni', $validated['dni'] ?? null)
            ->where('numero_poliza', $validated['numero_poliza'] ?? null)
            ->where('templateid', $validated['templateid'] ?? null)
            ->where('attach1', $validated['attach1'] ?? null)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Esta cobertura ya fue aceptada previamente.',
                'data' => $existing,
                'already_accepted' => true
            ], 200);
        }

        $cobertura = CoberturaAceptada::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Cobertura aceptada y registrada correctamente.',
            'data' => $cobertura
        ], 201);
    }

    public function index()
    {
        $coberturas = CoberturaAceptada::orderBy('created_at', 'desc')->get();
        return response()->json([
            'success' => true,
            'data' => $coberturas
        ]);
    }
}
