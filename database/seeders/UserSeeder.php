<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One account per role at Beirut, and one per role at Paris (B13a). Password 'password' — development only.
 * The Beirut accounts come first: seeders that need "the" agent of a role take the first one.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['Rami Nassar', 'reservation@arcs.test', Role::Reservation, 'BEY'],
            ['Maya Khoury', 'checkin@arcs.test', Role::Checkin, 'BEY'],
            ['Joe Saliba', 'gate@arcs.test', Role::Gate, 'BEY'],
            ['Hiba Daher', 'manager@arcs.test', Role::Manager, 'BEY'],
            ['Claire Martin (Paris reservation)', 'reservation.cdg@arcs.test', Role::Reservation, 'CDG'],
            ['Julien Petit (Paris check-in)', 'checkin.cdg@arcs.test', Role::Checkin, 'CDG'],
            ['Sophie Bernard (Paris gate)', 'gate.cdg@arcs.test', Role::Gate, 'CDG'],
            ['Antoine Dubois (Paris manager)', 'manager.cdg@arcs.test', Role::Manager, 'CDG'],
        ];

        foreach ($users as [$name, $email, $role, $station]) {
            User::create([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'role' => $role,
                'airport_code' => $station,
            ]);
        }
    }
}
