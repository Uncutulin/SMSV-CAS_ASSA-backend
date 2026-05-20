<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\User;

class AdminController extends Controller
{
    private $baseUrl = 'https://api.smsvsegurosbi.com.ar/api';

    /**
     * Get a fresh BI token by logging in with backend credentials.
     * Caches the token in the cache store for 50 minutes to avoid
     * repeated logins on every request.
     */
    private function getBiToken(): ?string
    {
        $cacheKey = 'bi_admin_token';
        
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return \Illuminate\Support\Facades\Cache::get($cacheKey);
        }

        $response = Http::withoutVerifying()->post("{$this->baseUrl}/login", [
            'email'    => env('BI_EMAIL'),
            'password' => env('BI_PASSWORD'),
        ]);

        if (!$response->successful() || !isset($response->json()['access_token'])) {
            \Illuminate\Support\Facades\Log::error('Failed to get BI admin token', ['body' => $response->body()]);
            return null;
        }

        $token = $response->json()['access_token'];
        \Illuminate\Support\Facades\Cache::put($cacheKey, $token, now()->addMinutes(50));
        return $token;
    }

    public function getUsers(Request $request)
    {
        $biToken = $this->getBiToken();

        if (!$biToken) {
            return response()->json(['message' => 'No se pudo autenticar con el servidor BI'], 500);
        }

        $response = Http::withoutVerifying()->withToken($biToken)->get("{$this->baseUrl}/users");

        if (!$response->successful()) {
            // Token may have expired - flush cache and retry once
            \Illuminate\Support\Facades\Cache::forget('bi_admin_token');
            $biToken = $this->getBiToken();
            if ($biToken) {
                $response = Http::withoutVerifying()->withToken($biToken)->get("{$this->baseUrl}/users");
            }
            if (!$response->successful()) {
                return response()->json(['message' => 'Error fetching users from BI', 'detail' => $response->body()], 500);
            }
        }

        $biData = $response->json();
        // Support both paginated { data: [...] } and plain array responses
        $biUsers = isset($biData['data']) ? $biData['data'] : $biData;

        // 2. Fetch local users
        $localUsers = User::all()->keyBy('email');

        // 3. Merge
        $merged = [];
        foreach ($biUsers as $biUser) {
            $email = $biUser['email'] ?? '';
            $local = $localUsers->get($email);

            if ($local) {
                $biUser['local_role'] = $local->local_role;
                $biUser['status'] = 'activo';
            } else {
                $biUser['local_role'] = null;
                $biUser['status'] = 'desactivado';
            }
            $merged[] = $biUser;
        }

        return response()->json($merged);
    }

    public function getRoles(Request $request)
    {
        // El endpoint de roles en BI es público usualmente, o podemos pasar token por si acaso
        $response = Http::withoutVerifying()->get("{$this->baseUrl}/roles");
        return response()->json($response->json(), $response->status());
    }

    public function createUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'apellido' => 'required|string|max:255',
            'dni' => 'required|string|max:20',
            'email' => 'required|string|email|max:255',
            'roles' => 'required|array',
            'local_role' => 'nullable|string|in:Admin,Legales'
        ]);

        // Registrar en BI
        $biResponse = Http::withoutVerifying()->post("{$this->baseUrl}/register", [
            'name' => $request->name,
            'apellido' => $request->apellido,
            'dni' => $request->dni,
            'email' => $request->email,
            'roles' => $request->roles
        ]);

        if (!$biResponse->successful()) {
            return response()->json($biResponse->json(), $biResponse->status());
        }

        $data = $biResponse->json();
        $extUser = $data['user'];

        // Guardar localmente y asignar rol local
        $localUser = User::firstOrCreate(
            ['email' => $extUser['email']],
            [
                'name' => $extUser['name'],
                'apellido' => $extUser['apellido'],
                'dni' => $extUser['dni'],
                'local_role' => $request->local_role
            ]
        );

        if (!$localUser->wasRecentlyCreated) {
            $localUser->update([
                'name' => $extUser['name'],
                'apellido' => $extUser['apellido'],
                'dni' => $extUser['dni'],
                'local_role' => $request->local_role
            ]);
        }

        $extUser['local_role'] = $localUser->local_role;
        $extUser['status'] = 'activo';

        return response()->json([
            'message' => 'Usuario registrado exitosamente',
            'user' => $extUser
        ], 201);
    }

    public function updateRole(Request $request, $email)
    {
        $request->validate([
            'role' => 'nullable|string|in:Admin,Legales'
        ]);

        // We find or create the local user just in case they were never logged in
        $localUser = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $request->input('name', 'Usuario'),
                'local_role' => $request->role,
            ]
        );

        if (!$localUser->wasRecentlyCreated) {
            $localUser->local_role = $request->role;
            $localUser->save();
        }

        return response()->json([
            'message' => 'Rol actualizado',
            'user' => $localUser
        ]);
    }
}
