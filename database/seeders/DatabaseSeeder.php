<?php

namespace Database\Seeders;

use App\Enums\RoleTypes;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Provisions a single SUPER_ADMIN account so the project is usable
     * out of the box. Credentials can be overridden via environment vars.
     */
    public function run(): void
    {
        // Ensure all role rows exist (one per RoleTypes enum case).
        foreach (RoleTypes::cases() as $role) {
            Role::firstOrCreate(['role' => $role->value]);
        }

        $email    = env('SUPER_ADMIN_EMAIL', 'superadmin@smartparking.local');
        $password = env('SUPER_ADMIN_PASSWORD', 'password');

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name'         => env('SUPER_ADMIN_NAME', 'Super Admin'),
                'password'     => Hash::make($password),
                'phone_number' => env('SUPER_ADMIN_PHONE', '0000000000'),
            ],
        );

        $superAdminRoleId = Role::where('role', RoleTypes::SUPER_ADMIN->value)->value('id');
        $admin->roles()->syncWithoutDetaching([$superAdminRoleId]);
    }
}
