<?php

namespace App\Jobs;

use App\Models\Transcription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ProcessTranscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 600;

    protected $transcription;
    protected $tempPath;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\Transcription  $transcription
     * @param  string  $tempPath
     * @return void
     */
    public function __construct(Transcription $transcription, string $tempPath)
    {
        $this->transcription = $transcription;
        $this->tempPath = $tempPath;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Update status to processing
        $this->transcription->update(['status' => 'processing']);

        $absolutePath = Storage::disk('local')->path($this->tempPath);
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

            // Set up process with a 300 second timeout
            $process = new Process([$pythonPath, $scriptPath, $absolutePath], null, $envVariables);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                Log::error("Fallo al ejecutar el script de transcripción Python en segundo plano", [
                    'filename' => $this->transcription->filename,
                    'error' => $process->getErrorOutput(),
                    'exit_code' => $process->getExitCode()
                ]);
                $this->transcription->update([
                    'status' => 'failed',
                    'transcription' => "[Error de transcripción] El motor de transcripción reportó un error durante la ejecución.",
                ]);
            } else {
                $transcriptionText = trim($process->getOutput());
                if (!mb_check_encoding($transcriptionText, 'UTF-8')) {
                    $transcriptionText = mb_convert_encoding($transcriptionText, 'UTF-8', 'UTF-8');
                }
                
                $this->transcription->update([
                    'status' => 'completed',
                    'transcription' => $transcriptionText,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("Excepción durante la ejecución de transcribe.py en segundo plano", [
                'filename' => $this->transcription->filename,
                'error' => $e->getMessage()
            ]);
            $this->transcription->update([
                'status' => 'failed',
                'transcription' => "[Error de sistema] No se pudo ejecutar el script de transcripción. Detalle: " . $e->getMessage(),
            ]);
        } finally {
            // Always clean up the local temp file once the job completes
            if (Storage::disk('local')->exists($this->tempPath)) {
                Storage::disk('local')->delete($this->tempPath);
            }
        }
    }
}
