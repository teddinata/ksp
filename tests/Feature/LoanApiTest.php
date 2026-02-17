<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\CashAccount;
use App\Models\InterestRate;
use App\Models\Loan;
use App\Models\Installment;
use App\Models\ChartOfAccount;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Hash;

class LoanApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected CashAccount $cashAccount;
    protected string $adminToken;
    protected string $memberToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user
        $this->admin = User::create([
            'full_name' => 'Admin Test',
            'employee_id' => 'ADM001',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
            'joined_at' => now()->subYear(),
        ]);

        // Create member user
        $this->member = User::create([
            'full_name' => 'Member Test',
            'employee_id' => 'MBR001',
            'email' => 'member@test.com',
            'password' => Hash::make('password'),
            'role' => 'anggota',
            'is_active' => true,
            'joined_at' => now()->subYear(),
        ]);

        // Create cash account for loans
        $this->cashAccount = CashAccount::create([
            'code' => 'KAS-I',
            'name' => 'Kas Umum',
            'type' => 'I',
            'opening_balance' => 100000000,
            'current_balance' => 100000000,
            'is_active' => true,
        ]);

        // Create loan interest rate (12% annual)
        InterestRate::create([
            'cash_account_id' => $this->cashAccount->id,
            'transaction_type' => 'loans',
            'rate_percentage' => 12.00,
            'effective_date' => now()->subMonth(),
            'updated_by' => $this->admin->id,
        ]);

        // Create COA records needed by AutoJournalService
        $this->seedRequiredCoa();

        // Generate JWT tokens
        $this->adminToken = JWTAuth::fromUser($this->admin);
        $this->memberToken = JWTAuth::fromUser($this->member);
    }

    /**
     * Seed only the COA records that AutoJournalService needs.
     */
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

    /**
     * Helper: make authenticated request headers.
     */
    private function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    // ================================================================
    // 1. LOAN SIMULATION — verifies rate_percentage fix
    // ================================================================

    public function test_simulate_loan_returns_valid_schedule(): void
    {
        $response = $this->postJson('/api/loans/simulate', [
            'cash_account_id' => $this->cashAccount->id,
            'principal_amount' => 10000000,
            'tenure_months' => 12,
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJsonStructure([
            'success',
            'data' => ['interest_percentage', 'monthly_installment'],
        ]);

        // Verify rate_percentage was fetched (the bug fix)
        $data = $response->json('data');
        $this->assertEquals(12.00, (float)$data['interest_percentage']);
        $this->assertGreaterThan(0, $data['monthly_installment']);
    }

    public function test_simulate_loan_with_missing_fields_returns_error(): void
    {
        $response = $this->postJson('/api/loans/simulate', [], $this->authHeaders($this->adminToken));

        // Controller wraps in try-catch returning 500 for validation errors
        $response->assertStatus(500);
    }

    // ================================================================
    // 2. STORE LOAN — verifies rate_percentage fix in store
    // ================================================================

    public function test_store_loan_creates_pending_loan(): void
    {
        $response = $this->postJson('/api/loans', [
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'principal_amount' => 5000000,
            'tenure_months' => 12,
            'application_date' => now()->format('Y-m-d'),
            'loan_purpose' => 'Biaya pendidikan anak',
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        // Verify loan was created with correct interest
        $loan = Loan::first();
        $this->assertNotNull($loan);
        $this->assertEquals('pending', $loan->status);
        $this->assertEquals(12.00, (float)$loan->interest_percentage);
        $this->assertGreaterThan(0, $loan->installment_amount);
    }

    public function test_store_loan_fails_for_non_member(): void
    {
        $response = $this->postJson('/api/loans', [
            'user_id' => $this->admin->id, // admin is not a member
            'cash_account_id' => $this->cashAccount->id,
            'principal_amount' => 5000000,
            'tenure_months' => 12,
            'application_date' => now()->format('Y-m-d'),
            'loan_purpose' => 'Testing',
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(422);
    }

    public function test_store_loan_fails_with_insufficient_balance(): void
    {
        // Set balance very low
        $this->cashAccount->update(['current_balance' => 1000]);

        $response = $this->postJson('/api/loans', [
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'principal_amount' => 5000000,
            'tenure_months' => 12,
            'application_date' => now()->format('Y-m-d'),
            'loan_purpose' => 'Testing balance check',
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(422);
    }

    // ================================================================
    // 3. INDEX — list loans
    // ================================================================

    public function test_index_returns_loans_list(): void
    {
        // Create a loan directly
        Loan::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'loan_number' => 'LN-20260001',
            'principal_amount' => 5000000,
            'interest_percentage' => 12.00,
            'tenure_months' => 12,
            'installment_amount' => 444241,
            'remaining_principal' => 5000000,
            'status' => 'pending',
            'application_date' => now(),
            'loan_purpose' => 'Test',
        ]);

        $response = $this->getJson('/api/loans', $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ================================================================
    // 4. SHOW — single loan
    // ================================================================

    public function test_show_returns_loan_detail(): void
    {
        $loan = Loan::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'loan_number' => 'LN-20260002',
            'principal_amount' => 3000000,
            'interest_percentage' => 12.00,
            'tenure_months' => 6,
            'installment_amount' => 517654,
            'remaining_principal' => 3000000,
            'status' => 'pending',
            'application_date' => now(),
            'loan_purpose' => 'Test show',
        ]);

        $response = $this->getJson("/api/loans/{$loan->id}", $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_show_nonexistent_loan_returns_error(): void
    {
        // Controller catches ModelNotFoundException and returns 500
        $response = $this->getJson('/api/loans/99999', $this->authHeaders($this->adminToken));

        $response->assertStatus(500);
    }

    // ================================================================
    // 5. UPDATE — requires all fields (same as store)
    // ================================================================

    public function test_update_loan_modifies_pending_loan(): void
    {
        // Create a second cash account so we can switch to it (avoiding duplicate loan validation)
        $cashAccount2 = CashAccount::create([
            'code'            => 'KAS-II',
            'name'            => 'Kas Pinjaman 2',
            'type'            => 'I',
            'opening_balance' => 100000000,
            'current_balance' => 100000000,
            'is_active'       => true,
        ]);

        InterestRate::create([
            'cash_account_id'  => $cashAccount2->id,
            'transaction_type' => 'loans',
            'rate_percentage'  => 10.00,
            'effective_date'   => now()->subMonth(),
            'updated_by'       => $this->admin->id,
        ]);

        // Create a loan on the original cash account
        $loan = Loan::create([
            'user_id'              => $this->member->id,
            'cash_account_id'      => $this->cashAccount->id,
            'loan_number'          => 'LN-20260003',
            'principal_amount'     => 5000000,
            'interest_percentage'  => 12.00,
            'tenure_months'        => 12,
            'installment_amount'   => 444241,
            'remaining_principal'  => 5000000,
            'status'               => 'pending',
            'application_date'     => now(),
            'loan_purpose'         => 'Original purpose',
        ]);

        // Update: move to the second cash account (no existing loan there)
        $response = $this->putJson("/api/loans/{$loan->id}", [
            'user_id'          => $this->member->id,
            'cash_account_id'  => $cashAccount2->id,
            'principal_amount' => 7000000,
            'tenure_months'    => 12,
            'application_date' => now()->format('Y-m-d'),
            'loan_purpose'     => 'Updated purpose',
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);

        $loan->refresh();
        $this->assertEquals('Updated purpose', $loan->loan_purpose);
        $this->assertEquals(7000000, (int) $loan->principal_amount);
    }

    // ================================================================
    // 6. DELETE — delete pending loan
    // ================================================================

    public function test_delete_loan_removes_pending_loan(): void
    {
        $loan = Loan::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'loan_number' => 'LN-20260004',
            'principal_amount' => 5000000,
            'interest_percentage' => 12.00,
            'tenure_months' => 12,
            'installment_amount' => 444241,
            'remaining_principal' => 5000000,
            'status' => 'pending',
            'application_date' => now(),
            'loan_purpose' => 'To delete',
        ]);

        $response = $this->deleteJson("/api/loans/{$loan->id}", [], $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ================================================================
    // 7. APPROVE — approve and disburse creates installments
    // ================================================================

    public function test_approve_and_disburse_loan_creates_installments_and_journal(): void
    {
        $loan = Loan::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'loan_number' => 'LN-20260005',
            'principal_amount' => 6000000,
            'interest_percentage' => 12.00,
            'tenure_months' => 12,
            'installment_amount' => 533124,
            'remaining_principal' => 6000000,
            'status' => 'pending',
            'application_date' => now(),
            'loan_purpose' => 'Approve test',
        ]);

        $response = $this->postJson("/api/loans/{$loan->id}/approve", [
            'status' => 'approved',
            'disburse' => true,
            'disbursement_date' => now()->format('Y-m-d'),
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify installments were created
        $loan->refresh();
        $this->assertContains($loan->status, ['approved', 'disbursed', 'active']);
        $this->assertGreaterThan(0, Installment::where('loan_id', $loan->id)->count());
    }

    public function test_approve_without_disburse(): void
    {
        $loan = Loan::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'loan_number' => 'LN-20260005B',
            'principal_amount' => 6000000,
            'interest_percentage' => 12.00,
            'tenure_months' => 12,
            'installment_amount' => 533124,
            'remaining_principal' => 6000000,
            'status' => 'pending',
            'application_date' => now(),
            'loan_purpose' => 'Approve only test',
        ]);

        $response = $this->postJson("/api/loans/{$loan->id}/approve", [
            'status' => 'approved',
            'disbursement_date' => now()->format('Y-m-d'),
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $loan->refresh();
        $this->assertEquals('approved', $loan->status);
        // No installments created without disburse=true
        $this->assertEquals(0, Installment::where('loan_id', $loan->id)->count());
    }

    public function test_reject_loan(): void
    {
        $loan = Loan::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'loan_number' => 'LN-20260006',
            'principal_amount' => 5000000,
            'interest_percentage' => 12.00,
            'tenure_months' => 12,
            'installment_amount' => 444241,
            'remaining_principal' => 5000000,
            'status' => 'pending',
            'application_date' => now(),
            'loan_purpose' => 'Reject test',
        ]);

        $response = $this->postJson("/api/loans/{$loan->id}/approve", [
            'status' => 'rejected',
            'rejection_reason' => 'Tidak memenuhi syarat',
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $loan->refresh();
        $this->assertEquals('rejected', $loan->status);
    }

    // ================================================================
    // 8. SUMMARY
    // ================================================================

    public function test_summary_returns_loan_statistics(): void
    {
        $response = $this->getJson('/api/loans/summary', $this->authHeaders($this->adminToken));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ================================================================
    // 9. EARLY SETTLEMENT PREVIEW
    // ================================================================

    public function test_early_settlement_preview(): void
    {
        // Create a disbursed loan with installments
        $loan = Loan::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'loan_number' => 'LN-20260007',
            'principal_amount' => 6000000,
            'interest_percentage' => 12.00,
            'tenure_months' => 12,
            'installment_amount' => 533124,
            'remaining_principal' => 5500000,
            'status' => 'disbursed',
            'application_date' => now()->subMonth(),
            'approval_date' => now()->subMonth(),
            'disbursement_date' => now()->subMonth(),
            'approved_by' => $this->admin->id,
            'loan_purpose' => 'Early settlement test',
        ]);

        // Create at least one paid installment
        Installment::create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->subWeek(),
            'payment_date' => now()->subWeek(),
            'principal_amount' => 500000,
            'interest_amount' => 60000,
            'total_amount' => 560000,
            'paid_amount' => 560000,
            'remaining_principal' => 5500000,
            'status' => 'paid',
            'payment_method' => 'cash',
        ]);

        // Create pending installments
        for ($i = 2; $i <= 12; $i++) {
            Installment::create([
                'loan_id' => $loan->id,
                'installment_number' => $i,
                'due_date' => now()->addMonths($i - 1),
                'principal_amount' => 500000,
                'interest_amount' => 55000,
                'total_amount' => 555000,
                'paid_amount' => 0,
                'remaining_principal' => 5500000 - (($i - 1) * 500000),
                'status' => 'pending',
            ]);
        }

        $response = $this->getJson(
            "/api/loans/{$loan->id}/early-settlement/preview",
            $this->authHeaders($this->adminToken)
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
            'data' => [
                'loan_number',
                'remaining_principal',
                'settlement_amount',
            ],
        ]);
    }

    // ================================================================
    // 10. DOWNLOAD IMPORT TEMPLATE
    // ================================================================

    public function test_download_loan_import_template(): void
    {
        $response = $this->getJson('/api/loans/import/template', $this->authHeaders($this->adminToken));

        $response->assertStatus(200);
    }

    // ================================================================
    // 11. EXPORT EXCEL — uses named route to avoid route conflict
    // ================================================================

    public function test_export_loans_excel_via_controller(): void
    {
        Loan::create([
            'user_id'              => $this->member->id,
            'cash_account_id'      => $this->cashAccount->id,
            'loan_number'          => 'LN-20260008',
            'principal_amount'     => 5000000,
            'interest_percentage'  => 12.00,
            'tenure_months'        => 12,
            'installment_amount'   => 444241,
            'remaining_principal'  => 5000000,
            'status'               => 'pending',
            'application_date'     => now(),
            'loan_purpose'         => 'Export test',
        ]);

        // Test export via direct controller call since /export route conflicts with /{id}
        $this->actingAs($this->admin, 'api');
        $controller = app(\App\Http\Controllers\Api\LoanController::class);
        $request = new \Illuminate\Http\Request();
        $response = $controller->exportExcel($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    // ================================================================
    // 12. ROLE AUTHORIZATION
    // ================================================================

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/loans');

        $response->assertStatus(401);
    }

    public function test_member_cannot_delete_loan(): void
    {
        $loan = Loan::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'loan_number' => 'LN-20260009',
            'principal_amount' => 5000000,
            'interest_percentage' => 12.00,
            'tenure_months' => 12,
            'installment_amount' => 444241,
            'remaining_principal' => 5000000,
            'status' => 'pending',
            'application_date' => now(),
            'loan_purpose' => 'Auth test',
        ]);

        $response = $this->deleteJson(
            "/api/loans/{$loan->id}",
        [],
            $this->authHeaders($this->memberToken)
        );

        $response->assertStatus(403);
    }

    // ================================================================
    // 13. DUPLICATE LOAN PREVENTION
    // ================================================================

    public function test_cannot_create_duplicate_loan_for_same_cash_account(): void
    {
        // Create an active loan
        Loan::create([
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'loan_number' => 'LN-20260010',
            'principal_amount' => 5000000,
            'interest_percentage' => 12.00,
            'tenure_months' => 12,
            'installment_amount' => 444241,
            'remaining_principal' => 5000000,
            'status' => 'active',
            'application_date' => now()->subMonth(),
            'loan_purpose' => 'Existing loan',
        ]);

        // Try to create another loan for same member + cash account
        $response = $this->postJson('/api/loans', [
            'user_id' => $this->member->id,
            'cash_account_id' => $this->cashAccount->id,
            'principal_amount' => 3000000,
            'tenure_months' => 6,
            'application_date' => now()->format('Y-m-d'),
            'loan_purpose' => 'Duplicate attempt',
        ], $this->authHeaders($this->adminToken));

        $response->assertStatus(422);
    }
}