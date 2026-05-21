<?php

namespace App\Http\Controllers;

use App\Services\OwnerStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Space-owner facing endpoints.
 *
 * All actions are scoped to the authenticated user. Route-level middleware
 * (`role:SPACE_OWNER,SUPER_ADMIN`) gates access; a SUPER_ADMIN hitting this
 * endpoint will see stats for whichever parks they personally own (typically
 * none — superadmins normally consult `/api/admin/stats` instead).
 */
class OwnerController extends Controller
{
    public function __construct(
        private readonly OwnerStatsService $stats,
    ) {}

    /** Aggregated portfolio statistics for the signed-in space owner. */
    public function stats(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->stats->dashboard($request->user()),
        ]);
    }
}
