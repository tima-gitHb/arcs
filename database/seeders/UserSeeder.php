<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One account per role. Password 'password' — development only.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['Rami Nassar', 'reservation@arcs.test', Role::Reservation],
            ['Maya Khoury', 'checkin@arcs.test', Role::Checkin],
            ['Joe Saliba', 'gate@arcs.test', Role::Gate],
            ['Hiba Daher', 'manager@arcs.test', Role::Manager],
        ];

        foreach ($users as [$name, $email, $role]) {
            User::create([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'role' => $role,
            ]);
        }
    }
}
