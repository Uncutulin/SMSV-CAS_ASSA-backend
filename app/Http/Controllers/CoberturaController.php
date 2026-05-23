<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        try {
            $coberturas = DB::connection('pgsql_gestion')
                ->table('stage_landing.dopler_confirmacion_raw')
                ->select([
                    'id_raw AS id',
                    'batch_id',
                    'email',
                    'name',
                    'templateid',
                    'attach1',
                    'attach2',
                    'attach3',
                    'attach4',
                    'nombre',
                    'dni',
                    'numero_poliza',
                    'vigencia AS fecha_vigencia',
                    'src_file',
                    'src_system',
                    'ingested_at AS created_at',
                    'poliza_confirmada',
                    'confirmado_at'
                ])
                ->orderBy('ingested_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $coberturas
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching coberturas from PGSQL', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener coberturas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function uploadCsv(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt'
        ]);

        $file = $request->file('file');
        
        // Generate batch_id
        $batchId = (int)(microtime(true) * 1000);
        
        // Save file locally to process
        $tempPath = $file->storeAs('tmp_coberturas', 'cobertura_' . $batchId . '.csv');
        $absolutePath = \Illuminate\Support\Facades\Storage::disk('local')->path($tempPath);
        $originalName = $file->getClientOriginalName();

        $rowsAffected = 0;
        $fallbackUsed = false;

        try {
            // Check connection first
            $db = DB::connection('pgsql_gestion');
            
            // Try using stored procedure first
            try {
                $result = $db->select(
                    'SELECT stage_landing.load_dopler_confirmacion_csv(?, ?)',
                    [$absolutePath, $batchId]
                );
                
                if (!empty($result)) {
                    $prop = 'load_dopler_confirmacion_csv';
                    $rowsAffected = $result[0]->$prop ?? 0;
                }
            } catch (\Throwable $spException) {
                // If stored procedure fails (e.g. file is remote, permission issue, etc.),
                // we fallback to manual parsing in PHP.
                Log::warning('PostgreSQL stored procedure failed, falling back to manual PHP parsing', [
                    'error' => $spException->getMessage(),
                    'path' => $absolutePath
                ]);
                
                $fallbackUsed = true;
                
                // Parse CSV and insert line-by-line in a transaction
                $rowsAffected = $this->parseAndInsertCsv($absolutePath, $batchId, $originalName, $db);
            }

            // Cleanup local file
            if (file_exists($absolutePath)) {
                unlink($absolutePath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Archivo CSV procesado con éxito.',
                'batch_id' => $batchId,
                'rows_affected' => $rowsAffected,
                'fallback_used' => $fallbackUsed
            ], 200);

        } catch (\Throwable $e) {
            // Cleanup local file on error
            if (file_exists($absolutePath)) {
                unlink($absolutePath);
            }

            Log::error('Error processing coberturas CSV', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo CSV: ' . $e->getMessage()
            ], 500);
        }
    }

    private function parseAndInsertCsv($filePath, $batchId, $originalFileName, $db)
    {
        $rowsAffected = 0;
        
        $db->transaction(function() use ($filePath, $batchId, $originalFileName, $db, &$rowsAffected) {
            if (($handle = fopen($filePath, "r")) !== FALSE) {
                // Read headers
                $headers = fgetcsv($handle, 0, ";");
                if ($headers === false) {
                    throw new \Exception("El archivo CSV está vacío o es inválido.");
                }

                // Clean UTF-8 BOM if present and lower-case
                $headers = array_map(function($h) {
                    $h = preg_replace('/[\x{00EF}\x{00BB}\x{00BF}]/u', '', $h);
                    return strtolower(trim($h));
                }, $headers);

                $columns = [
                    'email', 'name', 'templateid', 'attach1', 'attach2',
                    'attach3', 'attach4', 'nombre', 'dni', 'numero_poliza', 'vigencia'
                ];

                if (!in_array('email', $headers)) {
                    throw new \Exception("El archivo CSV debe contener la columna 'email'.");
                }

                while (($data = fgetcsv($handle, 0, ";")) !== FALSE) {
                    // Combine headers with data, pad if data is shorter
                    $row = array_combine(
                        $headers, 
                        array_slice(array_pad($data, count($headers), null), 0, count($headers))
                    );

                    $insertData = [
                        'batch_id' => $batchId,
                        'src_file' => $originalFileName,
                        'src_system' => 'DOPLER',
                        'ingested_at' => now(),
                    ];

                    foreach ($columns as $col) {
                        $val = $row[$col] ?? null;
                        
                        // Treat empty string as null
                        if ($val === '') {
                            $val = null;
                        }

                        // Validate UUID format for templateid
                        if ($col === 'templateid' && $val !== null) {
                            if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $val)) {
                                $val = null;
                            }
                        }

                        $insertData[$col] = $val;
                    }

                    $db->table('stage_landing.dopler_confirmacion_raw')->insert($insertData);
                    $rowsAffected++;
                }
                fclose($handle);
            } else {
                throw new \Exception("No se pudo abrir el archivo CSV para lectura.");
            }
        });

        return $rowsAffected;
    }
}
