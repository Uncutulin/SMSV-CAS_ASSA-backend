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
        $user = $request->user();
        $biToken = \Illuminate\Support\Facades\Cache::get('bi_token_' . $user->id);

        if (!$biToken) {
            return response()->json(['message' => 'No se pudo obtener el token de BI para el usuario logueado. Por favor, vuelva a iniciar sesión.'], 401);
        }

        $response = Http::withoutVerifying()->withToken($biToken)->get("{$this->baseUrl}/users");

        if (!$response->successful()) {
            return response()->json(['message' => 'Error al obtener usuarios de BI', 'detail' => $response->body()], $response->status());
        }

        $biData = $response->json();
        // Support both paginated { data: [...] } and plain array responses
        $biUsers = isset($biData['data']) ? $biData['data'] : $biData;

        // 2. Fetch local users with roles relation
        $localUsers = User::with('roles')->get()->keyBy('email');

        // 3. Merge
        $merged = [];
        foreach ($biUsers as $biUser) {
            $email = $biUser['email'] ?? '';
            $local = $localUsers->get($email);

            if ($local) {
                $biUser['local_role'] = $local->local_role;
                $biUser['local_roles'] = $local->roles->pluck('name')->toArray();
                $biUser['status'] = 'activo';
            } else {
                $biUser['local_role'] = null;
                $biUser['local_roles'] = [];
                $biUser['status'] = 'desactivado';
            }
            $merged[] = $biUser;
        }

        return response()->json($merged);
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
            'local_roles.*' => 'string|in:Admin,Legales'
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

        // Guardar localmente
        $localUser = User::firstOrCreate(
            ['email' => $extUser['email']],
            [
                'name' => $extUser['name'],
                'apellido' => $extUser['apellido'],
                'dni' => $extUser['dni']
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
        $localRoles = $request->input('local_roles', []);
        $roleIds = \App\Models\Role::whereIn('name', $localRoles)->pluck('id')->toArray();
        $localUser->roles()->sync($roleIds);

        // Save local_role column as comma-separated string for backwards compatibility
        $localUser->update([
            'local_role' => implode(',', $localRoles)
        ]);

        $extUser['local_role'] = $localUser->local_role;
        $extUser['local_roles'] = $localRoles;
        $extUser['status'] = 'activo';

        return response()->json([
            'message' => 'Usuario registrado exitosamente',
            'user' => $extUser
        ], 201);
    }

    public function updateRole(Request $request, $email)
    {
        $request->validate([
            'roles' => 'nullable|array',
            'roles.*' => 'string|in:Admin,Legales'
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
        $localUser->save();

        return response()->json([
            'message' => 'Roles locales actualizados',
            'user' => $localUser,
            'local_roles' => $rolesInput
        ]);
    }
}
