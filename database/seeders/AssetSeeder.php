<?php

namespace Database\Seeders;

use App\Models\Asset;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AssetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating assets...');

        // 1. Tanah (tidak ada penyusutan)
        $asset1 = Asset::create([
            'asset_code' => Asset::generateAssetCode('land'),
            'asset_name' => 'Tanah Kantor Koperasi',
            'category' => 'land',
            'acquisition_cost' => 500000000,
            'acquisition_date' => Carbon::create(2020, 1, 1),
            'useful_life_months' => 0, // Tanah tidak disusutkan
            'residual_value' => 0,
            'depreciation_per_month' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 500000000,
            'location' => 'Jl. Koperasi No. 1, Ajibarang',
            'status' => 'active',
            'notes' => 'Tanah lokasi kantor koperasi seluas 500 m²',
        ]);
        $this->command->info("  ✓ {$asset1->asset_code} - {$asset1->asset_name}");

        // 2. Bangunan
        $asset2 = Asset::create([
            'asset_code' => Asset::generateAssetCode('building'),
            'asset_name' => 'Gedung Kantor Koperasi',
            'category' => 'building',
            'acquisition_cost' => 300000000,
            'acquisition_date' => Carbon::create(2020, 6, 1),
            'useful_life_months' => 240, // 20 tahun
            'residual_value' => 50000000,
            'depreciation_per_month' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 300000000,
            'location' => 'Jl. Koperasi No. 1, Ajibarang',
            'status' => 'active',
            'notes' => 'Gedung 2 lantai untuk operasional koperasi',
        ]);
        $asset2->depreciation_per_month = $asset2->calculateDepreciationPerMonth();
        $asset2->save();
        $this->command->info("  ✓ {$asset2->asset_code} - {$asset2->asset_name}");

        // 3. Kendaraan - Mobil
        $asset3 = Asset::create([
            'asset_code' => Asset::generateAssetCode('vehicle'),
            'asset_name' => 'Toyota Avanza G 2022',
            'category' => 'vehicle',
            'acquisition_cost' => 250000000,
            'acquisition_date' => Carbon::create(2022, 3, 15),
            'useful_life_months' => 96, // 8 tahun
            'residual_value' => 50000000,
            'depreciation_per_month' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 250000000,
            'location' => 'Kantor Koperasi',
            'status' => 'active',
            'notes' => 'Kendaraan operasional untuk kegiatan lapangan',
        ]);
        $asset3->depreciation_per_month = $asset3->calculateDepreciationPerMonth();
        $asset3->save();
        $this->command->info("  ✓ {$asset3->asset_code} - {$asset3->asset_name}");

        // 4. Kendaraan - Motor
        $asset4 = Asset::create([
            'asset_code' => Asset::generateAssetCode('vehicle'),
            'asset_name' => 'Honda Beat 2023',
            'category' => 'vehicle',
            'acquisition_cost' => 18000000,
            'acquisition_date' => Carbon::create(2023, 1, 10),
            'useful_life_months' => 60, // 5 tahun
            'residual_value' => 3000000,
            'depreciation_per_month' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 18000000,
            'location' => 'Kantor Koperasi',
            'status' => 'active',
            'notes' => 'Motor untuk aktivitas harian staff',
        ]);
        $asset4->depreciation_per_month = $asset4->calculateDepreciationPerMonth();
        $asset4->save();
        $this->command->info("  ✓ {$asset4->asset_code} - {$asset4->asset_name}");

        // 5. Peralatan - Komputer
        $asset5 = Asset::create([
            'asset_code' => Asset::generateAssetCode('equipment'),
            'asset_name' => 'Komputer Set (5 unit)',
            'category' => 'equipment',
            'acquisition_cost' => 30000000,
            'acquisition_date' => Carbon::create(2023, 7, 1),
            'useful_life_months' => 48, // 4 tahun
            'residual_value' => 3000000,
            'depreciation_per_month' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 30000000,
            'location' => 'Kantor Koperasi - Ruang Admin',
            'status' => 'active',
            'notes' => 'Desktop PC untuk staff administrasi',
        ]);
        $asset5->depreciation_per_month = $asset5->calculateDepreciationPerMonth();
        $asset5->save();
        $this->command->info("  ✓ {$asset5->asset_code} - {$asset5->asset_name}");

        // 6. Peralatan - AC
        $asset6 = Asset::create([
            'asset_code' => Asset::generateAssetCode('equipment'),
            'asset_name' => 'AC Split 1.5 PK (3 unit)',
            'category' => 'equipment',
            'acquisition_cost' => 15000000,
            'acquisition_date' => Carbon::create(2022, 8, 1),
            'useful_life_months' => 72, // 6 tahun
            'residual_value' => 1500000,
            'depreciation_per_month' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 15000000,
            'location' => 'Kantor Koperasi',
            'status' => 'active',
        ]);
        $asset6->depreciation_per_month = $asset6->calculateDepreciationPerMonth();
        $asset6->save();
        $this->command->info("  ✓ {$asset6->asset_code} - {$asset6->asset_name}");

        // 7. Inventaris - Meja Kursi
        $asset7 = Asset::create([
            'asset_code' => Asset::generateAssetCode('inventory'),
            'asset_name' => 'Meja & Kursi Kantor (Set Lengkap)',
            'category' => 'inventory',
            'acquisition_cost' => 25000000,
            'acquisition_date' => Carbon::create(2020, 6, 15),
            'useful_life_months' => 60, // 5 tahun
            'residual_value' => 2500000,
            'depreciation_per_month' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 25000000,
            'location' => 'Kantor Koperasi',
            'status' => 'active',
            'notes' => 'Furniture kantor untuk 10 staff',
        ]);
        $asset7->depreciation_per_month = $asset7->calculateDepreciationPerMonth();
        $asset7->save();
        $this->command->info("  ✓ {$asset7->asset_code} - {$asset7->asset_name}");

        // 8. Equipment - Printer
        $asset8 = Asset::create([
            'asset_code' => Asset::generateAssetCode('equipment'),
            'asset_name' => 'Printer Multifungsi Canon',
            'category' => 'equipment',
            'acquisition_cost' => 8000000,
            'acquisition_date' => Carbon::create(2023, 9, 1),
            'useful_life_months' => 36, // 3 tahun
            'residual_value' => 500000,
            'depreciation_per_month' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 8000000,
            'location' => 'Kantor Koperasi - Ruang Admin',
            'status' => 'active',
        ]);
        $asset8->depreciation_per_month = $asset8->calculateDepreciationPerMonth();
        $asset8->save();
        $this->command->info("  ✓ {$asset8->asset_code} - {$asset8->asset_name}");

        $this->command->info('Asset seeding completed!');
        $this->command->info('Total assets: 8');
        $this->command->info('Total value: Rp ' . number_format(Asset::sum('acquisition_cost'), 0, ',', '.'));
    }
}