<?php

namespace App\Http\Controllers;

use App\Enums\RoleTypes;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\WhatsAppRequestCodeRequest;
use App\Http\Requests\WhatsAppVerifyCodeRequest;
use App\Http\Resources\RegisterResource;
use App\Models\Role;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppNotifier;
use App\Services\WhatsApp\WhatsAppOtpService;
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
        $user = auth()->user();
        if ($user) {
            $user->load('roles');
        }
        return response()->json($user);
    }

    // Logout the user
    // public function logout()
    // {
    //     auth()->logout();

    //     return response()->json(['message' => 'Successfully logged out']);
    // }

    /**
     * WhatsApp OTP — step 1: dispatch a one-time code to the phone.
     *
     * Always returns 200 with the same shape regardless of whether the
     * phone is registered. This prevents user-enumeration: an attacker
     * can't tell registered phones from unregistered ones by response.
     * The cooldown is honored even when no message is sent so timing
     * attacks can't leak existence either.
     */
    public function requestWhatsAppCode(
        WhatsAppRequestCodeRequest $request,
        WhatsAppOtpService $otp,
        WhatsAppNotifier $notifier,
    ) {
        $phone = $request->normalizedPhone();

        $generic = response()->json([
            'message'             => 'If the number is registered, a code has been sent on WhatsApp.',
            'expires_in_seconds'  => WhatsAppOtpService::TTL_SECONDS,
            'cooldown_seconds'    => WhatsAppOtpService::RESEND_COOLDOWN_SECONDS,
        ]);

        if ($otp->isOnCooldown($phone)) {
            return $generic;
        }

        $user = $otp->resolveUser($phone);
        if (!$user) {
            return $generic;
        }

        $code = $otp->issue($phone);

        $notifier->send(
            $phone,
            "🔐 رمز الدخول إلى ParkIQ: *{$code}*\n"
            . "صالح لمدة 5 دقائق. لا تشاركه مع أحد."
        );

        return $generic;
    }

    /**
     * WhatsApp OTP — step 2: exchange a valid code for a JWT.
     */
    public function verifyWhatsAppCode(
        WhatsAppVerifyCodeRequest $request,
        WhatsAppOtpService $otp,
    ) {
        $phone = $request->normalizedPhone();
        $code  = (string) $request->input('code');

        $user = $otp->verify($phone, $code);

        if (!$user) {
            return response()->json(['error' => 'Invalid or expired code.'], 401);
        }

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => new RegisterResource($user->load('roles')),
        ]);
    }
}
