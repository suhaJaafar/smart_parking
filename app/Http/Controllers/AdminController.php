<?php

namespace App\Http\Controllers;

use App\Services\AdminStatsService;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    public function __construct(
        private readonly AdminStatsService $stats,
    ) {}

    /** Aggregated platform statistics for the admin dashboard. */
    public function stats(): JsonResponse
    {
        return response()->json(['data' => $this->stats->dashboard()]);
    }
}
