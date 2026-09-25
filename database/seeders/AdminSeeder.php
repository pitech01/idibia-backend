<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Super Admin
        if (!User::where('email', 'admin@dibia.com')->exists()) {
            User::create([
                'name' => 'Super Admin',
                'email' => 'admin@dibia.com',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]);
            $this->command?->info('Admin 1 created: admin@dibia.com / password');
        } else {
            $this->command?->info('Admin 1 (admin@dibia.com) already exists. Skipped to protect existing data.');
        }

        // Create Second Admin
        if (!User::where('email', 'staff@dibia.com')->exists()) {
            User::create([
                'name' => 'Staff Admin',
                'email' => 'staff@dibia.com',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]);
            $this->command?->info('Admin 2 created: staff@dibia.com / password');
        } else {
            $this->command?->info('Admin 2 (staff@dibia.com) already exists. Skipped to protect existing data.');
        }

        // Create Super Admin
        if (!User::where('email', 'superadmin@dibia.com')->exists()) {
            User::create([
                'name' => 'Super Admin',
                'email' => 'superadmin@dibia.com',
                'password' => Hash::make('password'),
                'role' => 'super-admin',
                'email_verified_at' => now(),
            ]);
            $this->command?->info('Super Admin created: superadmin@dibia.com / password');
        } else {
            $this->command?->info('Super Admin (superadmin@dibia.com) already exists. Skipped to protect existing data.');
        }
    }
}
