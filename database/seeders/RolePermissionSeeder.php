<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // bersihkan cache permission
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Roles (guard web)
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user  = Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);

        // Permissions
        $perms = [
            'view categories',
            'create categories',
            'update categories',
            'delete categories',
        ];
        foreach ($perms as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        // admin dapat semua
        $admin->syncPermissions($perms);

        // user biasa: hanya view
        $user->syncPermissions(['view categories']);
    }
}
