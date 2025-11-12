<?php

namespace Database\Seeders;

use App\Models\Gift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class GiftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::where('email', 'admin@ksu-ceria.test')->first();
        $members = User::members()->active()->get();

        if (!$admin || $members->isEmpty()) {
            $this->command->warn('Required data not found. Run UserSeeder first.');
            return;
        }

        // 1. Holiday gifts (Hari Raya) - All members
        $this->command->info('Distributing holiday gifts...');
        $result = Gift::distributeToMembers(
            'holiday',
            'Paket Lebaran 2024',
            500000,
            Carbon::create(2024, 4, 10)->format('Y-m-d'),
            $admin->id
        );
        $this->command->info("  -> {$result['members_count']} members, Total: Rp " . number_format($result['total_value'], 0));

        // Mark all as distributed
        foreach ($result['gifts'] as $gift) {
            $gift->markAsDistributed($admin->id, 'Paket berisi sembako dan THR');
        }

        // 2. Birthday gifts - Some members
        $this->command->info('Distributing birthday gifts...');
        foreach ($members->take(2) as $member) {
            $gift = Gift::create([
                'user_id' => $member->id,
                'gift_type' => 'birthday',
                'gift_name' => 'Voucher Ulang Tahun',
                'gift_value' => 200000,
                'distribution_date' => Carbon::now()->subMonths(rand(1, 6)),
                'status' => 'distributed',
                'distributed_by' => $admin->id,
                'notes' => 'Selamat ulang tahun!',
            ]);
        }
        $this->command->info("  -> 2 members, Total: Rp 400,000");

        // 3. Achievement awards - Top performers
        $this->command->info('Distributing achievement awards...');
        if ($members->count() > 0) {
            $topMember = $members->first();
            $gift = Gift::create([
                'user_id' => $topMember->id,
                'gift_type' => 'achievement',
                'gift_name' => 'Penghargaan Anggota Terbaik 2024',
                'gift_value' => 1000000,
                'distribution_date' => Carbon::create(2024, 12, 31)->format('Y-m-d'),
                'status' => 'distributed',
                'distributed_by' => $admin->id,
                'notes' => 'Atas dedikasi dan kontribusi luar biasa',
            ]);
            $this->command->info("  -> 1 member, Total: Rp 1,000,000");
        }

        // 4. Special event - Gathering
        $this->command->info('Distributing special event gifts...');
        $result = Gift::distributeToMembers(
            'special_event',
            'Hampers Family Gathering 2024',
            300000,
            Carbon::create(2024, 8, 17)->format('Y-m-d'),
            $admin->id
        );
        foreach ($result['gifts'] as $gift) {
            $gift->markAsDistributed($admin->id, 'Hadiah acara kebersamaan');
        }
        $this->command->info("  -> {$result['members_count']} members, Total: Rp " . number_format($result['total_value'], 0));

        // 5. Loyalty rewards - Long-term members
        $this->command->info('Distributing loyalty rewards...');
        foreach ($members->take(1) as $member) {
            $gift = Gift::create([
                'user_id' => $member->id,
                'gift_type' => 'loyalty',
                'gift_name' => 'Bonus Loyalitas 5 Tahun',
                'gift_value' => 750000,
                'distribution_date' => Carbon::now()->subMonths(2)->format('Y-m-d'),
                'status' => 'distributed',
                'distributed_by' => $admin->id,
                'notes' => 'Terima kasih atas kesetiaan 5 tahun bersama koperasi',
            ]);
        }
        $this->command->info("  -> 1 member, Total: Rp 750,000");

        // 6. Pending gifts for upcoming event
        $this->command->info('Creating pending gifts...');
        $result = Gift::distributeToMembers(
            'holiday',
            'Paket Natal & Tahun Baru 2025',
            600000,
            Carbon::create(2024, 12, 25)->format('Y-m-d'),
            $admin->id
        );
        $this->command->info("  -> {$result['members_count']} members (pending), Total: Rp " . number_format($result['total_value'], 0));

        $this->command->info('Gift seeding completed!');
    }
}