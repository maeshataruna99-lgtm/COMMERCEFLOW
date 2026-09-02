<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password_hash' => $data['password'],
            ]);

            $customer = Role::where('name', 'customer')->first();
            if ($customer) {
                $user->roles()->attach($customer);
            }

            Cart::create(['user_id' => $user->id]);

            return $user;
        });

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'success' => true,
            'message' => 'Registered successfully',
            'data' => [
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'bearer',
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! $token = JWTAuth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $user = Auth::guard('api')->user();

        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully',
            'data' => [
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'bearer',
            ],
        ]);
    }

    public function refresh(): JsonResponse
    {
        try {
            $token = JWTAuth::parseToken()->refresh();
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed',
                'error' => ['code' => 'INVALID_REFRESH_TOKEN'],
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed',
            'data' => ['access_token' => $token, 'token_type' => 'bearer'],
        ]);
    }

    public function logout(): JsonResponse
    {
        JWTAuth::parseToken()->invalidate();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
            'data' => null,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name'),
                    'permissions' => $user->permissionNames(),
                ],
            ],
        ]);
    }

    public function menus(Request $request): JsonResponse
    {
        $user = $request->user();
        $permissions = $user->permissionNames()->all();

        $menus = Menu::query()
            ->whereNull('parent_id')
            ->orderBy('sort')
            ->get()
            ->map(function (Menu $menu) use ($permissions) {
                return $this->buildMenu($menu, $permissions);
            })
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'data' => ['menus' => $menus],
        ]);
    }

    private function buildMenu(Menu $menu, array $permissions): ?array
    {
        $required = $menu->permissions->pluck('name')->all();

        if ($required !== [] && count(array_intersect($required, $permissions)) === 0) {
            return null;
        }

        $children = $menu->children
            ->sortBy('sort')
            ->map(fn (Menu $child) => $this->buildMenu($child, $permissions))
            ->filter()
            ->values();

        return [
            'id' => $menu->id,
            'name' => $menu->name,
            'route' => $menu->route,
            'children' => $children,
        ];
    }
}