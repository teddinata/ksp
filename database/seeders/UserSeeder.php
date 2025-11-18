<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Admin
        User::create([
            'full_name' => 'System Administrator',
            'employee_id' => 'ADMIN001',
            'email' => 'admin@ksu-ceria.test',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
            'is_active' => true,
            'status' => 'active',
            'phone_number' => '081234567890',
            'work_unit' => 'IT Department',
            'position' => 'System Admin',
            'joined_at' => now(),
        ]);

        // Create Manager
        User::create([
            'full_name' => 'Manager Koperasi',
            'employee_id' => 'MGR001',
            'email' => 'manager@ksu-ceria.test',
            'password' => Hash::make('manager123'),
            'role' => 'manager',
            'is_active' => true,
            'status' => 'active',
            'phone_number' => '081234567891',
            'work_unit' => 'Finance',
            'position' => 'Finance Manager',
            'joined_at' => now(),
        ]);

        // Create Members (Anggota)
        $members = [
            [
                'full_name' => 'Budi Santoso',
                'employee_id' => 'EMP001',
                'email' => 'budi@ksu-ceria.test',
                'work_unit' => 'Medical Records',
                'position' => 'Staff',
            ],
            [
                'full_name' => 'Siti Aminah',
                'employee_id' => 'EMP002',
                'email' => 'siti@ksu-ceria.test',
                'work_unit' => 'Nursing',
                'position' => 'Nurse',
            ],
            [
                'full_name' => 'Ahmad Fauzi',
                'employee_id' => 'EMP003',
                'email' => 'ahmad@ksu-ceria.test',
                'work_unit' => 'Pharmacy',
                'position' => 'Pharmacist',
            ],
        ];

        foreach ($members as $index => $member) {
            User::create([
                'full_name' => $member['full_name'],
                'employee_id' => $member['employee_id'],
                'email' => $member['email'],
                'password' => Hash::make('password123'),
                'role' => 'anggota',
                'is_active' => true,
                'status' => 'active', 
                'phone_number' => '08123456789' . $index,
                'address' => 'Jl. Contoh No. ' . ($index + 1) . ', Ajibarang',
                'work_unit' => $member['work_unit'],
                'position' => $member['position'],
                'joined_at' => now()->subMonths(rand(1, 12)),
            ]);
        }
    }
}