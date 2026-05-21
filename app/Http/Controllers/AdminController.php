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
        // 1. Fetch all local users with their roles
        $localUsers = User::with('roles')->get();

        $response = [];
        foreach ($localUsers as $user) {
            $response[] = [
                'id' => $user->id,
                'name' => $user->name,
                'apellido' => $user->apellido,
                'dni' => $user->dni,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'local_role' => $user->local_role,
                'local_roles' => $user->roles->pluck('name')->toArray(),
                'status' => $user->status ?? 'desactivado',
            ];
        }

        return response()->json($response);
    }

    public function getRoles(Request $request)
    {
        $response = Http::withoutVerifying()->get("{$this->baseUrl}/roles");
        return response()->json($response->json(), $response->status());
    }

    public function getLocalRoles(Request $request)
    {
        return response()->json(\App\Models\Role::orderBy('name')->get());
    }

    public function createUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'apellido' => 'required|string|max:255',
            'dni' => 'required|string|max:20',
            'email' => 'required|string|email|max:255',
            'roles' => 'required|array',
            'local_roles' => 'nullable|array',
            'local_roles.*' => 'string|exists:roles,name'
        ]);

        $user = $request->user();
        $biToken = \Illuminate\Support\Facades\Cache::get('bi_token_' . $user->id);

        // Registrar en BI
        $pendingRequest = Http::withoutVerifying();
        if ($biToken) {
            $pendingRequest = $pendingRequest->withToken($biToken);
        }

        $biResponse = $pendingRequest->post("{$this->baseUrl}/register", [
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

        $localRoles = $request->input('local_roles', []);
        $status = count($localRoles) > 0 ? 'activo' : 'desactivado';

        // Guardar localmente
        $localUser = User::firstOrCreate(
            ['email' => $extUser['email']],
            [
                'name' => $extUser['name'],
                'apellido' => $extUser['apellido'],
                'dni' => $extUser['dni'],
                'status' => $status
            ]
        );

        if (!$localUser->wasRecentlyCreated) {
            $localUser->update([
                'name' => $extUser['name'],
                'apellido' => $extUser['apellido'],
                'dni' => $extUser['dni']
            ]);
        }

        // Sync local roles in DB
        $roleIds = \App\Models\Role::whereIn('name', $localRoles)->pluck('id')->toArray();
        $localUser->roles()->sync($roleIds);

        // Save local_role column and status
        $localUser->update([
            'local_role' => implode(',', $localRoles),
            'status' => $status
        ]);

        $extUser['local_role'] = $localUser->local_role;
        $extUser['local_roles'] = $localRoles;
        $extUser['status'] = $localUser->status;

        return response()->json([
            'message' => 'Usuario registrado exitosamente',
            'user' => $extUser
        ], 201);
    }

    public function updateRole(Request $request, $email)
    {
        $request->validate([
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
            'status' => 'nullable|string|in:activo,desactivado'
        ]);

        $localUser = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $request->input('name', 'Usuario'),
            ]
        );

        $rolesInput = $request->input('roles', []);
        $roleIds = \App\Models\Role::whereIn('name', $rolesInput)->pluck('id')->toArray();
        $localUser->roles()->sync($roleIds);

        $localUser->local_role = implode(',', $rolesInput);
        
        if ($request->has('status')) {
            $localUser->status = $request->input('status');
        } else {
            // Automatically set status to active if they have local roles, deactivated otherwise
            $localUser->status = count($rolesInput) > 0 ? 'activo' : 'desactivado';
        }
        
        $localUser->save();

        return response()->json([
            'message' => 'Roles y estado locales actualizados',
            'user' => $localUser,
            'local_roles' => $rolesInput,
            'status' => $localUser->status
        ]);
    }

    /**
     * Import users from the external BI API.
     * All imported users are created in local database in a "desactivado" (deactivated) state by default.
     */
    public function importUsers(Request $request)
    {
        $biToken = $this->getBiToken();

        if (!$biToken) {
            return response()->json(['message' => 'No se pudo obtener el token de administrador para la API de BI.'], 500);
        }

        $response = Http::withoutVerifying()->withToken($biToken)->get("{$this->baseUrl}/users");

        if (!$response->successful()) {
            return response()->json([
                'message' => 'Error al obtener usuarios de la API externa',
                'detail' => $response->body()
            ], $response->status());
        }

        $biData = $response->json();
        // Support both paginated { data: [...] } and plain array responses
        $biUsers = isset($biData['data']) ? $biData['data'] : $biData;

        if (!is_array($biUsers)) {
            return response()->json(['message' => 'Respuesta inesperada de la API externa de BI.'], 500);
        }

        $importedCount = 0;
        $updatedCount = 0;

        foreach ($biUsers as $biUser) {
            $email = $biUser['email'] ?? '';
            if (!$email) {
                continue;
            }

            $localUser = User::where('email', $email)->first();

            if ($localUser) {
                // Update basic details if they changed locally
                $localUser->update([
                    'name' => $biUser['name'] ?? $localUser->name,
                    'apellido' => $biUser['apellido'] ?? $localUser->apellido,
                    'dni' => $biUser['dni'] ?? $localUser->dni,
                    'avatar_url' => $biUser['avatar_url'] ?? $localUser->avatar_url,
                ]);
                $updatedCount++;
            } else {
                // Create as deactivated by default
                User::create([
                    'email' => $email,
                    'name' => $biUser['name'] ?? 'Usuario',
                    'apellido' => $biUser['apellido'] ?? '',
                    'dni' => $biUser['dni'] ?? null,
                    'avatar_url' => $biUser['avatar_url'] ?? null,
                    'status' => 'desactivado',
                    'local_role' => '',
                ]);
                $importedCount++;
            }
        }

        return response()->json([
            'message' => 'Importación completada con éxito',
            'imported' => $importedCount,
            'updated' => $updatedCount
        ]);
    }
}
