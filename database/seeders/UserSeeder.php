<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Admin',
                'email' => 'admin@example.com',
                'password' => 'password123',
            ],
            [
                'name' => 'Rahim',
                'email' => 'rahim@example.com',
                'password' => 'password123',
            ],
            [
                'name' => 'Karim',
                'email' => 'karim@example.com',
                'password' => 'password123',
            ],
            [
                'name' => 'Hasan',
                'email' => 'hasan@example.com',
                'password' => 'password123',
            ],
            [
                'name' => 'Sakib',
                'email' => 'sakib@example.com',
                'password' => 'password123',
            ],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make($user['password']),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
