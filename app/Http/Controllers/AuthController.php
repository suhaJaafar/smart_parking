<?php

namespace App\Http\Controllers;

use App\Enums\RoleTypes;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\RegisterResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;


//  source code for login/register methods are from : "https://medium.com/@rokisheik/jwt-authentication-in-laravel-03dd9be4a21a"

class AuthController extends Controller
{
    // Register a new user
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        $user = User::create([
            'name'         => $data['name'],
            'email'        => $data['email'],
            'phone_number' => $data['phone_number'] ?? null,
            'password'     => Hash::make($data['password']),
        ]);

        $defaultRole = Role::firstOrCreate(['role' => RoleTypes::USER->value]);
        $user->roles()->sync([$defaultRole->id]);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'User registered successfully',
            'user'    => new RegisterResource($user->load('roles')),
            'token'   => $token,
        ], 201);
    }

    // Login and generate a JWT
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        if (! $token = JWTAuth::attempt($credentials)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        return response()->json([
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => new RegisterResource(auth()->user()),
        ]);
    }
    // Get authenticated user details
    public function user()
    {
        return response()->json(auth()->user());
    }

    // Logout the user
    // public function logout()
    // {
    //     auth()->logout();

    //     return response()->json(['message' => 'Successfully logged out']);
    // }
}
