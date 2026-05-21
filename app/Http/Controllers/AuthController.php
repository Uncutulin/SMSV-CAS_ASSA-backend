<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    private $baseUrl = 'https://api.smsvsegurosbi.com.ar/api';

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Check local user existence and status first
        $localUser = \App\Models\User::where('email', $request->email)->first();
        if (!$localUser) {
            return response()->json([
                'message' => 'Usuario no registrado localmente en este sistema.'
            ], 403);
        }

        if ($localUser->status !== 'activo') {
            return response()->json([
                'message' => 'Su cuenta está desactivada. Por favor, contacte a un administrador.'
            ], 403);
        }

        try {
            $response = Http::withoutVerifying()->post("{$this->baseUrl}/login", [
                'email' => $request->email,
                'password' => $request->password,
                'device_id' => $request->device_id,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Si requiere 2FA o setup de 2FA, solo devolvemos los datos, no creamos usuario local todavía
                if (isset($data['two_factor']) || isset($data['requires_2fa_setup'])) {
                    return response()->json($data, $response->status());
                }

                return $this->processSuccessfulLogin($data);
            }

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Login proxy error: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
            return response()->json([
                'message' => 'Error al conectar con el servidor de autenticación.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function processSuccessfulLogin($data)
    {
        $extUser = $data['user'];

        // Find local user
        $localUser = \App\Models\User::where('email', $extUser['email'])->first();

        if (!$localUser) {
            return response()->json([
                'message' => 'Usuario no registrado localmente en este sistema.'
            ], 403);
        }

        if ($localUser->status !== 'activo') {
            return response()->json([
                'message' => 'Su cuenta está desactivada. Por favor, contacte a un administrador.'
            ], 403);
        }

        // Update avatar if it changed or any other basic info
        $localUser->name = $extUser['name'] ?? $localUser->name;
        $localUser->apellido = $extUser['apellido'] ?? $localUser->apellido;
        $localUser->dni = $extUser['dni'] ?? $localUser->dni;
        if ($localUser->avatar_url !== ($extUser['avatar_url'] ?? null)) {
            $localUser->avatar_url = $extUser['avatar_url'] ?? null;
        }
        $localUser->save();

        // Create Sanctum Token locally
        $localToken = $localUser->createToken('auth_token')->plainTextToken;

        // Store the BI access token in the cache keyed by the local user's ID
        if (isset($data['access_token'])) {
            \Illuminate\Support\Facades\Cache::put('bi_token_' . $localUser->id, $data['access_token'], now()->addMinutes(120));
        }

        // Append local_role to response data
        $data['user']['local_role'] = $localUser->local_role;
        $data['local_access_token'] = $localToken;

        return response()->json($data);
    }

    public function forgotPassword(Request $request)
    {
        $response = Http::withoutVerifying()->post("{$this->baseUrl}/forgot-password", $request->all());
        return response()->json($response->json(), $response->status());
    }

    public function twoFactorChallenge(Request $request)
    {
        $token = $request->bearerToken();
        $response = Http::withoutVerifying()->withToken($token)->post("{$this->baseUrl}/two-factor-challenge", $request->all());
        
        if ($response->successful() && isset($response->json()['user'])) {
            return $this->processSuccessfulLogin($response->json());
        }
        return response()->json($response->json(), $response->status());
    }

    public function confirm2FAAndLogin(Request $request)
    {
        $token = $request->bearerToken();
        $response = Http::withoutVerifying()->withToken($token)->post("{$this->baseUrl}/confirm-two-factor-and-login", $request->all());
        
        if ($response->successful() && isset($response->json()['user'])) {
            return $this->processSuccessfulLogin($response->json());
        }
        return response()->json($response->json(), $response->status());
    }

    public function enableTwoFactor(Request $request)
    {
        $token = $request->bearerToken();
        $response = Http::withoutVerifying()->withToken($token)->post("{$this->baseUrl}/user/two-factor-authentication");
        return response()->json($response->json(), $response->status());
    }

    public function getTwoFactorQrCode(Request $request)
    {
        $token = $request->bearerToken();
        $response = Http::withoutVerifying()->withToken($token)->get("{$this->baseUrl}/user/two-factor-qr-code");
        return response()->json($response->json(), $response->status());
    }

    public function getTwoFactorSecretKey(Request $request)
    {
        $token = $request->bearerToken();
        $response = Http::withoutVerifying()->withToken($token)->get("{$this->baseUrl}/user/two-factor-secret-key");
        return response()->json($response->json(), $response->status());
    }
}
