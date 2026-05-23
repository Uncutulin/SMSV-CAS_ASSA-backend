<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class R2UploadController extends Controller
{
    /**
     * Generate presigned URLs for uploading files directly to Cloudflare R2.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPresignedUrls(Request $request)
    {
        // Validation: Expect a list of file objects containing 'path' and 'type'
        $validated = $request->validate([
            'files' => 'required|array|min:1|max:500', // Limit to 500 files per batch request
            'files.*.path' => 'required|string|max:1000',
            'files.*.type' => 'nullable|string|max:255',
        ]);

        try {
            $disk = Storage::disk('r2');
            
            // Check if R2 disk is configured properly
            $bucket = $disk->getConfig()['bucket'] ?? null;
            if (!$bucket) {
                return response()->json([
                    'success' => false,
                    'message' => 'El disco de R2 no está configurado correctamente en el servidor.'
                ], 500);
            }

            $client = $disk->getClient();
            $presignedUrls = [];

            foreach ($validated['files'] as $fileData) {
                $path = $fileData['path'];
                $contentType = $fileData['type'] ?? 'application/octet-stream';

                // Clean the path to avoid directory traversal
                $cleanPath = ltrim(str_replace(['..', '\\'], ['', '/'], $path), '/');

                // Get S3 PutObject command with ContentType to include it in signature
                $command = $client->getCommand('PutObject', [
                    'Bucket'      => $bucket,
                    'Key'         => $cleanPath,
                    'ContentType' => $contentType,
                ]);

                // Generate presigned request valid for 20 minutes
                $presignedRequest = $client->createPresignedRequest($command, '+20 minutes');
                
                $presignedUrls[] = [
                    'original_path' => $path,
                    'r2_path' => $cleanPath,
                    'url' => (string) $presignedRequest->getUri(),
                ];
            }

            return response()->json([
                'success' => true,
                'urls' => $presignedUrls
            ]);

        } catch (\Throwable $e) {
            Log::error('Error generating R2 presigned URLs', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al generar las URLs pre-firmadas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List all existing files inside a specific R2 folder/prefix.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function existingFiles(Request $request)
    {
        $request->validate([
            'prefix' => 'required|string|max:1000',
        ]);

        $prefix = $request->input('prefix');
        // Clean prefix to prevent directory traversal
        $cleanPrefix = ltrim(str_replace(['..', '\\'], ['', '/'], $prefix), '/');

        try {
            $disk = Storage::disk('r2');
            
            // List files within the prefix directory
            $files = $disk->files($cleanPrefix);

            return response()->json([
                'success' => true,
                'files' => $files
            ]);
        } catch (\Throwable $e) {
            Log::error('Error listing R2 existing files', [
                'prefix' => $cleanPrefix,
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al listar archivos existentes en R2: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate a temporary presigned URL to download a file from R2.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDownloadUrl(Request $request)
    {
        $request->validate([
            'key' => 'required|string|max:1000',
        ]);

        $key = $request->input('key');
        // Clean key
        $cleanKey = ltrim(str_replace(['..', '\\'], ['', '/'], $key), '/');

        try {
            $disk = Storage::disk('r2');
            $bucket = $disk->getConfig()['bucket'] ?? env('CLOUDFLARE_R2_BUCKET');
            $client = $disk->getClient();

            // Verify file exists before generating URL
            if (!$disk->exists($cleanKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo solicitado no existe en R2.'
                ], 404);
            }

            // Get S3 GetObject command
            $command = $client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key'    => $cleanKey,
            ]);

            // Presigned request valid for 5 minutes
            $presignedRequest = $client->createPresignedRequest($command, '+5 minutes');
            $url = (string) $presignedRequest->getUri();

            return response()->json([
                'success' => true,
                'url' => $url
            ]);
        } catch (\Throwable $e) {
            Log::error('Error generating R2 download URL', [
                'key' => $cleanKey,
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar URL de descarga: ' . $e->getMessage()
            ], 500);
        }
    }
}
