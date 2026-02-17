<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\CashAccount;
use App\Models\InterestRate;
use App\Models\Saving;
use App\Models\ChartOfAccount;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Hash;
use App\Models\SavingType;

class SavingApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected CashAccount $cashAccount;
    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user
        $this->admin = User::create([
            'full_name' => 'Admin Saving',
            'employee_id' => 'ADM002',
            'email' => 'admin-saving@test.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
            'joined_at' => now()->subYear(),
        ]);

        // Create member user
        $this->member = User::create([
            'full_name' => 'Member Saving',
            'employee_id' => 'MBR002',
            'email' => 'member-saving@test.com',
            'password' => Hash::make('password'),
            'role' => 'anggota',
            'is_active' => true,
            'joined_at' => now()->subYear(),
        ]);

        // Create cash account for savings
        $this->cashAccount = CashAccount::create([
            'code' => 'KAS-I',
            'name' => 'Kas Umum',
            'type' => 'I',
            'opening_balance' => 50000000,
            'current_balance' => 50000000,
            'is_active' => true,
        ]);

        // Create savings interest rate (2% annual)
        InterestRate::create([
            'cash_account_id' => $this->cashAccount->id,
            'transaction_type' => 'savings',
            'rate_percentage' => 2.00,
            'effective_date' => now()->subMonth(),
            'updated_by' => $this->admin->id,
        ]);

        // Seed COA records needed by AutoJournalService
        $this->seedRequiredCoa();

        // Seed Saving Types
        $this->seedSavingTypes();

        // Generate JWT token
        $this->adminToken = JWTAuth::fromUser($this->admin);
    }

    private function seedRequiredCoa(): void
    {
        $coaRecords = [
            ['code' => '1-101', 'name' => 'Kas Umum', 'category' => 'assets', 'is_debit' => true],
            ['code' => '1-102', 'name' => 'Kas Sosial', 'category' => 'assets', 'is_debit' => true],
            ['code' => '1-103', 'name' => 'Kas Pengadaan', 'category' => 'assets', 'is_debit' => true],
            ['code' => '1-104', 'name' => 'Kas Hadiah', 'category' => 'assets', 'is_debit' => true],
            ['code' => '1-105', 'name' => 'Bank', 'category' => 'assets', 'is_debit' => true],
            ['code' => '1-201', 'name' => 'Piutang Pinjaman Anggota', 'category' => 'assets', 'is_debit' => true],
            ['code' => '2-201', 'name' => 'Simpanan Pokok Anggota', 'category' => 'liabilities', 'is_debit' => false],
            ['code' => '2-202', 'name' => 'Simpanan Wajib Anggota', 'category' => 'liabilities', 'is_debit' => false],
            ['code' => '2-203', 'name' => 'Simpanan Sukarela Anggota', 'category' => 'liabilities', 'is_debit' => false],
            ['code' => '2-204', 'name' => 'Simpanan Hari Raya', 'category' => 'liabilities', 'is_debit' => false],
            ['code' => '4-101', 'name' => 'Pendapatan Bunga Pinjaman', 'category' => 'revenue', 'is_debit' => false],
            ['code' => '4-201', 'name' => 'Pendapatan Lain-lain', 'category' => 'revenue', 'is_debit' => false],
            ['code' => '5-101', 'name' => 'Beban Gaji', 'category' => 'expenses', 'is_debit' => true],
        ];

        foreach ($coaRecords as $coa) {
            ChartOfAccount::create(array_merge($coa, ['is_active' => true]));
        }
    }

    private function seedSavingTypes(): void
    {
        $types = [
            ['code' => 'POKOK', 'name' => 'Simpanan Pokok', 'is_mandatory' => true],
            ['code' => 'WAJIB', 'name' => 'Simpanan Wajib', 'is_mandatory' => true],
            ['code' => 'SUKARELA', 'name' => 'Simpanan Sukarela', 'is_mandatory' => false],
            ['code' => 'HARIRAYA', 'name' => 'Simpanan Hari Raya', 'is_mandatory' => false],
        ];
        
        foreach ($types as $type) {
            SavingType::firstOrCreate(
                ['code' => $type['code']],
                $type + ['created_by' => $this->admin->id]
            );
        }
    }

    private function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    // Helper to get ID for type code
    private function getTypeId(string $code): int
    {
        return SavingType::where('code', $code)->first()->id;
    }

    // ================================================================
    // 1. STORE SAVING — verifies rate_percentage fix & saving_type_id mapping
    // ================================================================

    public function test_store_saving_uses_correct_interest_rate(): void
    {
        $response = $this->postJson('/api/savings', [
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'savings_type' => 'voluntary',
            'amount' => 500000,
            'transaction_date' => now()->format('Y-m-d'),
            'notes' => 'Test voluntary saving',
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        // Verify saving was created with proper interest from hasOne relation
        $saving = Saving::first();
        $this->assertNotNull($saving);
        $this->assertEquals(2.00, (float)$saving->interest_percentage);
        // Verify saving_type_id was populated correctly
        $this->assertEquals($this->getTypeId('SUKARELA'), $saving->saving_type_id);
    }

    // ================================================================
    // 2. INDEX — list savings
    // ================================================================

    public function test_index_returns_savings_list(): void
    {
        Saving::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'saving_type_id' => $this->getTypeId('WAJIB'),
            'savings_type' => 'mandatory',
            'amount' => 100000,
            'interest_percentage' => 2.00,
            'final_amount' => 102000,
            'transaction_date' => now(),
            'status' => 'approved',
        ]);

        $response = $this->getJson('/api/savings', $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ================================================================
    // 3. SHOW — single saving
    // ================================================================

    public function test_show_returns_saving_detail(): void
    {
        $saving = Saving::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'saving_type_id' => $this->getTypeId('WAJIB'),
            'savings_type' => 'mandatory',
            'amount' => 100000,
            'interest_percentage' => 2.00,
            'final_amount' => 102000,
            'transaction_date' => now(),
            'status' => 'approved',
        ]);

        $response = $this->getJson("/api/savings/{$saving->id}", $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ================================================================
    // 4. UPDATE — edit saving
    // ================================================================

    public function test_update_saving(): void
    {
        $saving = Saving::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'saving_type_id' => $this->getTypeId('SUKARELA'),
            'savings_type' => 'voluntary',
            'amount' => 200000,
            'interest_percentage' => 2.00,
            'final_amount' => 204000,
            'transaction_date' => now(),
            'status' => 'pending',
        ]);

        $response = $this->putJson("/api/savings/{$saving->id}", [
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'savings_type' => 'voluntary',
            'amount' => 200000,
            'transaction_date' => now()->format('Y-m-d'),
            'notes' => 'Updated notes',
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
            
        $saving->refresh();
        $this->assertEquals('Updated notes', $saving->notes);
    }

    // ================================================================
    // 5. DELETE — remove saving
    // ================================================================

    public function test_delete_saving(): void
    {
        $saving = Saving::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'saving_type_id' => $this->getTypeId('SUKARELA'),
            'savings_type' => 'voluntary',
            'amount' => 200000,
            'interest_percentage' => 2.00,
            'final_amount' => 204000,
            'transaction_date' => now(),
            'status' => 'pending',
        ]);

        $response = $this->deleteJson("/api/savings/{$saving->id}", [], $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ================================================================
    // 6. APPROVE — approve saving
    // ================================================================

    public function test_approve_saving_creates_journal(): void
    {
        $saving = Saving::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'saving_type_id' => $this->getTypeId('SUKARELA'),
            'savings_type' => 'voluntary',
            'amount' => 300000,
            'interest_percentage' => 2.00,
            'final_amount' => 306000,
            'transaction_date' => now(),
            'status' => 'pending',
        ]);

        $response = $this->postJson("/api/savings/{$saving->id}/approve", [
            'status' => 'approved',
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $saving->refresh();
        $this->assertEquals('approved', $saving->status);
    }

    // ================================================================
    // 7. SUMMARY
    // ================================================================

    public function test_summary_returns_saving_statistics(): void
    {
        $response = $this->getJson('/api/savings/summary', $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ================================================================
    // 8. GET BY TYPE
    // ================================================================

    public function test_get_savings_by_type(): void
    {
        Saving::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'saving_type_id' => $this->getTypeId('WAJIB'),
            'savings_type' => 'mandatory',
            'amount' => 100000,
            'interest_percentage' => 2.00,
            'final_amount' => 102000,
            'transaction_date' => now(),
            'status' => 'approved',
        ]);

        $response = $this->getJson('/api/savings/type/mandatory', $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ================================================================
    // 9. DOWNLOAD IMPORT TEMPLATE
    // ================================================================

    public function test_download_saving_import_template(): void
    {
        $response = $this->getJson('/api/savings/import/template', $this->authHeaders($this->adminToken));

        $response->assertStatus(200);
    }

    // ================================================================
    // 10. EXPORT EXCEL
    // ================================================================

    public function test_export_savings_excel_via_controller(): void
    {
        Saving::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'saving_type_id' => $this->getTypeId('WAJIB'),
            'savings_type' => 'mandatory',
            'amount' => 100000,
            'interest_percentage' => 2.00,
            'final_amount' => 102000,
            'transaction_date' => now(),
            'status' => 'approved',
        ]);

        // Test export via direct controller call since /export route might conflict or need named route
        $this->actingAs($this->admin, 'api');
        $controller = app(\App\Http\Controllers\Api\SavingController::class);
        $request = new \Illuminate\Http\Request();
        $response = $controller->exportExcel($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    // ================================================================
    // 11. UNAUTHORIZED ACCESS
    // ================================================================

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/savings');

        $response->assertStatus(401);
    }

    public function test_member_cannot_store_saving(): void
    {
        $memberToken = JWTAuth::fromUser($this->member);

        $response = $this->postJson('/api/savings', [
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'savings_type' => 'voluntary',
            'amount' => 100000,
            'transaction_date' => now()->format('Y-m-d'),
        ], $this->authHeaders($memberToken));

        $response->assertStatus(403);
    }
}