<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin default
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'              => 'Administrator',
                'username'          => 'admin',
                'password'          => bcrypt('password'), // ganti di production
                'email_verified_at' => now(),
            ]
        );
        $admin->assignRole('admin');

        // User biasa contoh
        $user = User::firstOrCreate(
            ['email' => 'user@example.com'],
            [
                'name'              => 'User Biasa',
                'username'          => 'user',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $user->assignRole('user');
    }
}
