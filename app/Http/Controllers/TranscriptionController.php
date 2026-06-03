<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Transcription;
use Carbon\Carbon;
use Symfony\Component\Process\Process;

class TranscriptionController extends Controller
{
    /**
     * Upload an audio file, parse its filename, run python transcription script, and save to DB.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        @set_time_limit(600);

        $request->validate([
            'file' => 'required|file|mimes:mp3,wav,ogg,m4a,aac,flac|max:50000', // max 50MB
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();

        // Parse filename: 1061#20251028#170149#12624946.mp3
        $basename = pathinfo($originalName, PATHINFO_FILENAME);
        $parts = explode('#', $basename);

        if (count($parts) < 4) {
            return response()->json([
                'success' => false,
                'message' => 'El nombre del archivo no cumple con el formato requerido (AgentID#Fecha#Hora#Indice.mp3).'
            ], 422);
        }

        $agentId = $parts[0];
        $dateStr = $parts[1]; // YYYYMMDD
        $timeStr = $parts[2]; // HHMMSS
        $callIndex = $parts[3]; // Index number

        // Validate date and time string lengths
        if (strlen($dateStr) !== 8 || strlen($timeStr) !== 6) {
            return response()->json([
                'success' => false,
                'message' => 'Los segmentos de fecha (AAAAMMDD) u hora (HHMMSS) en el nombre del archivo no son válidos.'
            ], 422);
        }

        try {
            // Parse UTC DateTime using Carbon
            $utcDateTime = Carbon::createFromFormat('Ymd His', $dateStr . ' ' . $timeStr, 'UTC');
            
            // Convert to America/Argentina/Buenos_Aires (UTC-3)
            $argDateTime = $utcDateTime->copy()->setTimezone('America/Argentina/Buenos_Aires');

            $callDate = $utcDateTime->format('Y-m-d');
            $callTimeUtc = $utcDateTime->format('H:i:s');
            $callTimeArgentina = $argDateTime->format('H:i:s');
        } catch (\Throwable $e) {
            Log::error('Error parsing date/time from transcription file name', [
                'filename' => $originalName,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la fecha y hora del archivo. Formato esperado: AAAAMMDD y HHMMSS.'
            ], 422);
        }

        // Store file temporarily inside local disk
        $tempPath = $file->storeAs('tmp_audio', 'audio_' . time() . '_' . $originalName);
        $absolutePath = Storage::disk('local')->path($tempPath);

        $transcriptionText = '';

        try {
            $pythonPath = env('PYTHON_PATH', 'python');
            $scriptPath = base_path('app/Python/transcribe.py');

            // Asegurar que el directorio de caché de Whisper exista y sea escribible
            $whisperCachePath = storage_path('app/whisper_cache');
            if (!file_exists($whisperCachePath)) {
                @mkdir($whisperCachePath, 0775, true);
            }

            // Asegurar que el subproceso herede variables de entorno críticas de Windows
            // (como SystemRoot, windir) para evitar errores de sockets y DLLs (WinError 10106).
            $systemEnv = [
                'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
                'windir' => getenv('windir') ?: 'C:\\Windows',
                'PATH' => getenv('PATH'),
                'TEMP' => getenv('TEMP') ?: sys_get_temp_dir(),
                'TMP' => getenv('TMP') ?: sys_get_temp_dir(),
                'XDG_CACHE_HOME' => $whisperCachePath,
            ];
            $envVariables = array_merge($_SERVER, $_ENV, $systemEnv);

            // Set up process with a 300 second timeout for potentially large files
            $process = new Process([$pythonPath, $scriptPath, $absolutePath], null, $envVariables);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                Log::error("Fallo al ejecutar el script de transcripción Python", [
                    'filename' => $originalName,
                    'error' => $process->getErrorOutput(),
                    'exit_code' => $process->getExitCode()
                ]);
                $transcriptionText = "[Error de transcripción] El motor de transcripción reportó un error durante la ejecución. Verifique el entorno del servidor.";
            } else {
                $transcriptionText = trim($process->getOutput());
                if (!mb_check_encoding($transcriptionText, 'UTF-8')) {
                    $transcriptionText = mb_convert_encoding($transcriptionText, 'UTF-8', 'UTF-8');
                }
            }
        } catch (\Throwable $e) {
            Log::error("Excepción durante la ejecución de transcribe.py", [
                'filename' => $originalName,
                'error' => $e->getMessage()
            ]);
            $transcriptionText = "[Error de sistema] No se pudo ejecutar el script de transcripción. Detalle: " . $e->getMessage();
        } finally {
            // Always clean up the local temp file
            if (Storage::disk('local')->exists($tempPath)) {
                Storage::disk('local')->delete($tempPath);
            }
        }

        try {
            // Save to database
            $transcription = Transcription::create([
                'filename' => $originalName,
                'agent_id' => $agentId,
                'call_date' => $callDate,
                'call_time_utc' => $callTimeUtc,
                'call_time_argentina' => $callTimeArgentina,
                'call_index' => $callIndex,
                'transcription' => $transcriptionText,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Audio procesado y transcripción registrada con éxito.',
                'data' => $transcription
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Error saving transcription to database', [
                'filename' => $originalName,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar la transcripción en la base de datos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List and filter transcriptions.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = Transcription::query();

            // Filters
            if ($request->has('agent_id') && !empty($request->input('agent_id'))) {
                $query->where('agent_id', 'like', '%' . $request->input('agent_id') . '%');
            }

            if ($request->has('call_index') && !empty($request->input('call_index'))) {
                $query->where('call_index', 'like', '%' . $request->input('call_index') . '%');
            }

            if ($request->has('call_date') && !empty($request->input('call_date'))) {
                $query->whereDate('call_date', $request->input('call_date'));
            }

            // Order: newest calls first
            $transcriptions = $query->orderBy('call_date', 'desc')
                                    ->orderBy('call_time_utc', 'desc')
                                    ->paginate(15);

            return response()->json([
                'success' => true,
                'data' => $transcriptions
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching transcriptions', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las transcripciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a transcription.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $transcription = Transcription::find($id);

            if (!$transcription) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transcripción no encontrada.'
                ], 404);
            }

            $transcription->delete();

            return response()->json([
                'success' => true,
                'message' => 'Transcripción eliminada con éxito.'
            ]);
        } catch (\Throwable $e) {
            Log::error('Error deleting transcription', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la transcripción: ' . $e->getMessage()
            ], 500);
        }
    }
}
