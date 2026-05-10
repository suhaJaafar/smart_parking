<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;

class LocationController extends Controller
{
        /**
            * Display a listing of the resource.
            */
            public function index()
            {
                // --- IGNORE ---
            }

            /**
            * Store a newly created resource in storage.
            */
            public function store(LocationRequest $request)
            {
                $location = Location::create($request->validated());
                return new LocationResource($location);
            }

            /**
            * Display the specified resource.
            */
            public function show(string $id)
            {
                // --- IGNORE ---
            }

            /**
            * Update the specified resource in storage.
            */
            public function update(LocationRequest $request, string $id)
            {
                // --- IGNORE ---
            }

            /**
            * Remove the specified resource from storage.
            */
            public function destroy(string $id)
            {
                // --- IGNORE ---
            }
}
