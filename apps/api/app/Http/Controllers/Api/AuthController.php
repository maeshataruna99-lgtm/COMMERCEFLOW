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
use OpenApi\Attributes as OA;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

#[OA\Info(
    version: '1.0.0',
    title: 'CommerceFlow API',
    description: 'E-Commerce, Inventory, and Order Management platform API.',
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'apiKey',
    in: 'header',
    name: 'Authorization',
    description: 'Enter token in format (Bearer <token>)',
)]
class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/register',
        summary: 'Register a new customer account',
        tags: ['Auth'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', example: 'Budi'),
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'budi@example.com'),
            new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
            new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'password123'),
        ]),
    )]
    #[OA\Response(response: 201, description: 'Registered successfully', content: new OA\JsonContent())]
    #[OA\Response(response: 422, description: 'Validation error')]
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

    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Login and obtain a JWT token',
        tags: ['Auth'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'budi@example.com'),
            new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
        ]),
    )]
    #[OA\Response(response: 200, description: 'Logged in successfully')]
    #[OA\Response(response: 422, description: 'Invalid credentials')]
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

    #[OA\Post(
        path: '/api/v1/auth/refresh',
        summary: 'Refresh an expired JWT token',
        tags: ['Auth'],
        security: [['sanctum' => []]],
    )]
    #[OA\Response(response: 200, description: 'Token refreshed')]
    #[OA\Response(response: 401, description: 'Token refresh failed')]
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

    #[OA\Post(
        path: '/api/v1/auth/logout',
        summary: 'Logout and invalidate the current token',
        tags: ['Auth'],
        security: [['sanctum' => []]],
    )]
    #[OA\Response(response: 200, description: 'Logged out successfully')]
    public function logout(): JsonResponse
    {
        JWTAuth::parseToken()->invalidate();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
            'data' => null,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/me',
        summary: 'Get the authenticated user profile',
        tags: ['Auth'],
        security: [['sanctum' => []]],
    )]
    #[OA\Response(response: 200, description: 'Current user with roles and permissions')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
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

    #[OA\Get(
        path: '/api/v1/me/menus',
        summary: 'Get the dynamic menu tree for the authenticated user',
        tags: ['Auth'],
        security: [['sanctum' => []]],
    )]
    #[OA\Response(response: 200, description: 'Permission-filtered menu tree')]
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