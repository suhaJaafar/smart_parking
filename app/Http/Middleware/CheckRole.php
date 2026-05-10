<?php

namespace App\Http\Middleware;

use App\Enums\RoleTypes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $allowed = array_map(
            fn (string $name) => constant(RoleTypes::class . "::{$name}")->value,
            $roles
        );

        $userRoles = $user->roles()->pluck('role')->map(
            fn ($role) => $role instanceof RoleTypes ? $role->value : (int) $role,
        )->all();

        if (empty(array_intersect($allowed, $userRoles))) {
            return response()->json(['message' => 'Forbidden. Insufficient role.'], 403);
        }

        return $next($request);
    }
}
