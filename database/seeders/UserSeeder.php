<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Administrator', 'username' => 'admin', 'password' => bcrypt('password'), 'email_verified_at' => now()]
        );
        $admin->assignRole('admin'); // guard web

        $viewer = User::firstOrCreate(
            ['email' => 'user@example.com'],
            ['name' => 'User Biasa', 'username' => 'user', 'password' => bcrypt('password'), 'email_verified_at' => now()]
        );
        $viewer->assignRole('user'); // guard web
    }
}
