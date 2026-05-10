<?php

namespace App\Http\Controllers;

use App\Enums\RoleTypes;
use App\Models\Role;
use App\Models\User;
use App\Http\Resources\UserResource;
use App\Http\Requests\UserRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
        /**
        * Display a listing of the resource.
        */
        public function index()
        {
            $users = User::with('roles')->paginate(10);
            return UserResource::collection($users);
        }

        /**
        * Store a newly created user by SUPER_ADMIN.
        */
        public function store(UserRequest $request)
    {
        $data = $request->validated();

        // Pull roles out before mass-assigning — it's a relation, not a column.
        $roles = $data['roles'] ?? [RoleTypes::USER->value];
        unset($data['roles']);

        $data['password'] = Hash::make($data['password']);

        // db run transaction to ensure all actions succeed or fail together,if one of them fails so rollback all the actions
        $user = DB::transaction(function () use ($data, $roles) {
            $user = User::create($data);
            $user->roles()->sync($this->resolveRoleIds($roles));
            return $user;
        });

        return new UserResource($user->load('roles'));
    }

        /**
        * Display the specified resource with roles.
        */
        public function show(string $id)
        {
            $user = User::with('roles')->findOrFail($id);
            return new UserResource($user);
        }

        /**
        * Update the specified resource in storage.
        */
        public function update(UserRequest $request, string $id)
        {
            $user = User::findOrFail($id);
            $data = $request->validated();

            if (array_key_exists('password', $data)) {
                $data['password'] = Hash::make($data['password']);
            }

            $roles = $data['roles'] ?? null;
            unset($data['roles']);

            DB::transaction(function () use ($user, $data, $roles) {
                $user->update($data);

                if ($roles !== null) {
                    $user->roles()->sync($this->resolveRoleIds($roles));
                }
            });

            return new UserResource($user->load('roles'));
        }

        /**
        * Remove the specified resource from storage.
        */
        public function destroy(string $id)
        {
            $user = User::findOrFail($id);
            $user->delete();
            return response()->noContent();
        }



    /**
     * this helper method by AI
     * Convert enum integer values (e.g. [2, 3]) into Role table UUIDs
     * for the role_user pivot.
     */
    private function resolveRoleIds(array $enumValues): array
    {
        return array_map(
            fn (int $value) => Role::firstOrCreate(['role' => $value])->id,
            $enumValues,
        );
    }
}
