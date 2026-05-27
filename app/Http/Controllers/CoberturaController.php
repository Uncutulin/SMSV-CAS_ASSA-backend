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
        // Map alias parameters to match target DB schema names
        if ($request->has('poliza') && !$request->has('numero_poliza')) {
            $request->merge(['numero_poliza' => $request->input('poliza')]);
        }
        if ($request->has('template') && !$request->has('templateid')) {
            $request->merge(['templateid' => $request->input('template')]);
        }
        if ($request->has('adjunto') && !$request->has('attach1')) {
            $request->merge(['attach1' => $request->input('adjunto')]);
        }
        if ($request->has('archivo') && !$request->has('src_file')) {
            $request->merge(['src_file' => $request->input('archivo')]);
        }
        if ($request->has('grupo') && !$request->has('batch_id')) {
            $request->merge(['batch_id' => $request->input('grupo')]);
        }
        if ($request->has('lote') && !$request->has('batch_id')) {
            $request->merge(['batch_id' => $request->input('lote')]);
        }

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
            'src_file' => 'nullable|string|max:255',
            'batch_id' => 'nullable|string|max:255',
        ]);

        // Query stage_landing.dopler_confirmacion_raw in pgsql_gestion database to find matching record
        try {
            $db = DB::connection('pgsql_gestion');

            $query = $db->table('stage_landing.dopler_confirmacion_raw')
                ->where('email', $validated['email']);

            if (!empty($validated['dni'])) {
                $query->where('dni', $validated['dni']);
            }
            if (!empty($validated['numero_poliza'])) {
                $query->where('numero_poliza', $validated['numero_poliza']);
            }
            if (!empty($validated['templateid'])) {
                $query->where('templateid', $validated['templateid']);
            }
            if (!empty($validated['attach1'])) {
                $query->where('attach1', $validated['attach1']);
            }
            if (!empty($validated['src_file'])) {
                $cleanSrcFile = $validated['src_file'];
                $extension = strtolower(pathinfo($cleanSrcFile, PATHINFO_EXTENSION));
                if (in_array($extension, ['csv', 'txt'])) {
                    $cleanSrcFile = pathinfo($cleanSrcFile, PATHINFO_FILENAME);
                }
                $query->where('src_file', $cleanSrcFile);
            }
            if (!empty($validated['batch_id'])) {
                $query->where('batch_id', $validated['batch_id']);
            }

            $record = $query->first();

            if (!$record) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró ningún registro pendiente de cobertura que coincida con los datos proporcionados.'
                ], 404);
            }

            $isAlreadyAccepted = $record->poliza_confirmada === true ||
                $record->poliza_confirmada === 1 ||
                $record->poliza_confirmada === '1' ||
                $record->poliza_confirmada === 'true';

            if ($isAlreadyAccepted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Esta cobertura ya fue aceptada previamente.',
                    'data' => [
                        'created_at' => $record->confirmado_at ?? now()->toIso8String(),
                        'nombre' => $record->nombre ?? $record->name,
                        'dni' => $record->dni,
                        'numero_poliza' => $record->numero_poliza,
                        'fecha_vigencia' => $record->vigencia,
                        'src_file' => $record->src_file,
                        'batch_id' => $record->batch_id
                    ],
                    'already_accepted' => true
                ], 200);
            }

            // Update the record to mark it as confirmed in PostgreSQL
            $db->table('stage_landing.dopler_confirmacion_raw')
                ->where('id_raw', $record->id_raw)
                ->update([
                    'poliza_confirmada' => true,
                    'confirmado_at' => now()
                ]);

            // Retrieve updated timestamp
            $updatedRecord = $db->table('stage_landing.dopler_confirmacion_raw')
                ->where('id_raw', $record->id_raw)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Cobertura aceptada y registrada correctamente.',
                'data' => [
                    'created_at' => $updatedRecord->confirmado_at ?? now()->toIso8String(),
                    'nombre' => $updatedRecord->nombre ?? $updatedRecord->name,
                    'dni' => $updatedRecord->dni,
                    'numero_poliza' => $updatedRecord->numero_poliza,
                    'fecha_vigencia' => $updatedRecord->vigencia,
                    'src_file' => $updatedRecord->src_file,
                    'batch_id' => $updatedRecord->batch_id
                ]
            ], 201);

        } catch (\Throwable $e) {
            Log::error("Error processing store confirmation in pgsql_gestion", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar la conformidad en la base de datos: ' . $e->getMessage()
            ], 500);
        }
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

        $cleanName = pathinfo($originalName, PATHINFO_FILENAME);
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

                    // Update the src_file to the clean filename without extension
                    $db->table('stage_landing.dopler_confirmacion_raw')
                        ->where('batch_id', $batchId)
                        ->update(['src_file' => $cleanName]);
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
        $cleanName = pathinfo($originalFileName, PATHINFO_FILENAME);
        
        $db->transaction(function() use ($filePath, $batchId, $cleanName, $db, &$rowsAffected) {
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
                        'src_file' => $cleanName,
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
