<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Feature\Bank;

use Tests\TestCase;
use App\Models\Expense;
use App\Models\Invoice;
use Tests\MockAccountData;
use App\Factory\InvoiceFactory;
use App\Models\BankTransaction;
use App\Factory\InvoiceItemFactory;
use App\Factory\BankIntegrationFactory;
use App\Factory\BankTransactionFactory;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class BankTransactionTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();

        $this->withoutMiddleware(
            ThrottleRequests::class
        );
    }

    public function testBankIntegrationFilters()
    {
        BankTransaction::where('company_id', $this->company->id)
        ->cursor()->each(function ($bt) {
            $bt->forceDelete();
        });

        $bi = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi->bank_account_name = "Bank1";
        $bi->save();

        $bt = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt->bank_integration_id = $bi->id;
        $bt->status_id = BankTransaction::STATUS_UNMATCHED;
        $bt->description = 'Fuel';
        $bt->amount = 10;
        $bt->currency_code = $this->client->currency()->code;
        $bt->date = now()->format('Y-m-d');
        $bt->transaction_id = 1234567890;
        $bt->category_id = 10000003;
        $bt->base_type = 'DEBIT';
        $bt->save();


        $bi2 = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi2->bank_account_name = "Bank2";
        $bi2->save();

        $bt = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt->bank_integration_id = $bi2->id;
        $bt->status_id = BankTransaction::STATUS_UNMATCHED;
        $bt->description = 'Fuel';
        $bt->amount = 20;
        $bt->currency_code = $this->client->currency()->code;
        $bt->date = now()->format('Y-m-d');
        $bt->transaction_id = 1234567890;
        $bt->category_id = 10000003;
        $bt->base_type = 'DEBIT';
        $bt->save();

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->getJson('/api/v1/bank_transactions');

        $response->assertStatus(200);

        $arr = $response->json();

        $transaction_count = count($arr['data']);

        $this->assertGreaterThan(1, $transaction_count);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->getJson('/api/v1/bank_transactions?bank_integration_ids='.$bi->hashed_id);

        $response->assertStatus(200);

        $arr = $response->json();

        $transaction_count = count($arr['data']);

        $this->assertCount(1, $arr['data']);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->getJson('/api/v1/bank_transactions?bank_integration_ids='.$bi2->hashed_id);

        $response->assertStatus(200);

        $arr = $response->json();

        $transaction_count = count($arr['data']);

        $this->assertCount(1, $arr['data']);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->getJson('/api/v1/bank_transactions?bank_integration_ids='.$bi2->hashed_id.",".$bi->hashed_id);

        $response->assertStatus(200);

        $arr = $response->json();

        $transaction_count = count($arr['data']);

        $this->assertCount(2, $arr['data']);

        $bi2->delete();

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->getJson('/api/v1/bank_transactions?active_banks=true');

        $response->assertStatus(200);

        $arr = $response->json();

        $this->assertCount(1, $arr['data']);

    }

    public function testLinkMultipleExpensesWithDeleteToTransaction()
    {
        $data = [];

        $bi = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi->save();

        $bt = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt->bank_integration_id = $bi->id;
        $bt->status_id = BankTransaction::STATUS_UNMATCHED;
        $bt->description = 'Fuel';
        $bt->amount = 10;
        $bt->currency_code = $this->client->currency()->code;
        $bt->date = now()->format('Y-m-d');
        $bt->transaction_id = 1234567890;
        $bt->category_id = 10000003;
        $bt->base_type = 'DEBIT';
        $bt->save();

        $this->expense->vendor_id = $this->vendor->id;
        $this->expense->save();

        $data = [];

        $data['transactions'][] = [
            'id' => $bt->hashed_id,
            'expense_id' => $this->expense->hashed_id
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/bank_transactions/match', $data);

        $response->assertStatus(200);

        $this->assertEquals($this->expense->refresh()->transaction_id, $bt->id);
        $this->assertEquals($this->expense->hashed_id, $bt->refresh()->expense_id);
        $this->assertEquals($bt->id, $this->expense->transaction_id);
        $this->assertEquals($this->vendor->id, $bt->vendor_id);
        $this->assertEquals(BankTransaction::STATUS_CONVERTED, $bt->status_id);


        $e = Expense::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $data = [];

        $data['transactions'][] = [
            'id' => $bt->hashed_id,
            'expense_id' => $e->hashed_id
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/bank_transactions/match', $data);

        $response->assertStatus(200);

        $this->assertEquals("{$this->expense->hashed_id},{$e->hashed_id}", $bt->fresh()->expense_id);

        $e2 = Expense::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $data = [];

        $data['transactions'][] = [
            'id' => $bt->hashed_id,
            'expense_id' => $e2->hashed_id
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/bank_transactions/match', $data);

        $response->assertStatus(200);

        $this->assertNotNull($e2->refresh()->transaction_id);

        $this->assertEquals("{$this->expense->hashed_id},{$e->hashed_id},{$e2->hashed_id}", $bt->fresh()->expense_id);

        $expense_repo = app('App\Repositories\ExpenseRepository');

        $expense_repo->delete($e2);

        $this->assertEquals("{$this->expense->hashed_id},{$e->hashed_id}", $bt->fresh()->expense_id);

    }



    public function testLinkMultipleExpensesToTransaction()
    {
        $data = [];

        $bi = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi->save();

        $bt = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt->bank_integration_id = $bi->id;
        $bt->status_id = BankTransaction::STATUS_UNMATCHED;
        $bt->description = 'Fuel';
        $bt->amount = 10;
        $bt->currency_code = $this->client->currency()->code;
        $bt->date = now()->format('Y-m-d');
        $bt->transaction_id = 1234567890;
        $bt->category_id = 10000003;
        $bt->base_type = 'DEBIT';
        $bt->save();

        $this->expense->vendor_id = $this->vendor->id;
        $this->expense->save();

        $data = [];

        $data['transactions'][] = [
            'id' => $bt->hashed_id,
            'expense_id' => $this->expense->hashed_id
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/bank_transactions/match', $data);

        $response->assertStatus(200);

        $this->assertEquals($this->expense->refresh()->transaction_id, $bt->id);
        $this->assertEquals($this->expense->hashed_id, $bt->refresh()->expense_id);
        $this->assertEquals($this->vendor->id, $bt->vendor_id);
        $this->assertEquals(BankTransaction::STATUS_CONVERTED, $bt->status_id);


        $e = Expense::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $data = [];

        $data['transactions'][] = [
            'id' => $bt->hashed_id,
            'expense_id' => $e->hashed_id
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/bank_transactions/match', $data);

        $response->assertStatus(200);

        $this->assertEquals("{$this->expense->hashed_id},{$e->hashed_id}", $bt->fresh()->expense_id);

    }


    public function testBankTransactionBulkActions()
    {
        $data = [
            'ids' => [$this->bank_integration->hashed_id],
            'action' => 'archive'
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/bank_transactions/bulk', $data)
          ->assertStatus(200);

        $data = [
            'ids' => [$this->bank_integration->hashed_id],
            'action' => 'restore'
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/bank_transactions/bulk', $data)
          ->assertStatus(200);

        $data = [
            'ids' => [$this->bank_integration->hashed_id],
            'action' => 'delete'
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/bank_transactions/bulk', $data)
          ->assertStatus(200);
    }

    public function testLinkExpenseToTransaction()
    {
        $data = [];

        $bi = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi->save();

        $bt = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt->bank_integration_id = $bi->id;
        $bt->status_id = BankTransaction::STATUS_UNMATCHED;
        $bt->description = 'Fuel';
        $bt->amount = 10;
        $bt->currency_code = $this->client->currency()->code;
        $bt->date = now()->format('Y-m-d');
        $bt->transaction_id = 1234567890;
        $bt->category_id = 10000003;
        $bt->base_type = 'DEBIT';
        $bt->save();

        $this->expense->vendor_id = $this->vendor->id;
        $this->expense->save();

        $data = [];

        $data['transactions'][] = [
            'id' => $bt->hashed_id,
            'expense_id' => $this->expense->hashed_id
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/bank_transactions/match', $data);

        $response->assertStatus(200);

        $this->assertEquals($this->expense->refresh()->transaction_id, $bt->id);
        $this->assertEquals($this->expense->hashed_id, $bt->refresh()->expense_id);
        $this->assertEquals($this->vendor->id, $bt->vendor_id);
        $this->assertEquals(BankTransaction::STATUS_CONVERTED, $bt->status_id);
    }

    public function testLinkingManuallyPaidInvoices()
    {
        $invoice = InvoiceFactory::create($this->company->id, $this->user->id);
        $invoice->client_id = $this->client->id;
        $invoice->status_id = Invoice::STATUS_SENT;
        $invoice->number = "InvoiceMatchingNumber123";
        $line_items = [];

        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 325;
        $item->type_id = 1;

        $line_items[] = $item;

        $invoice->line_items = $line_items;

        $invoice = $invoice->calc()->getInvoice();

        $invoice->service()->markPaid();

        $p = $invoice->payments->first();


        $bi = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi->save();

        $bt = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt->bank_integration_id = $bi->id;
        $bt->status_id = BankTransaction::STATUS_UNMATCHED;
        $bt->description = 'InvoiceMatchingNumber123';
        $bt->amount = 325;
        $bt->currency_code = $this->client->currency()->code;
        $bt->date = now()->format('Y-m-d');
        $bt->transaction_id = 1234567890;
        $bt->category_id = 10000003;
        $bt->base_type = 'CREDIT';
        $bt->save();

        $data = [];

        $data['transactions'][] = [
            'id' => $bt->hashed_id,
            'payment_id' => $p->hashed_id
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/bank_transactions/match', $data);

        $response->assertStatus(200);

        $this->assertEquals($p->refresh()->transaction_id, $bt->id);
        $this->assertEquals($bt->refresh()->payment_id, $p->id);
        $this->assertEquals(BankTransaction::STATUS_CONVERTED, $bt->status_id);
        $this->assertEquals($invoice->hashed_id, $bt->invoice_ids);
    }


    public function testLinkPaymentToTransaction()
    {
        $data = [];

        $bi = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi->save();

        $bt = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt->bank_integration_id = $bi->id;
        $bt->status_id = BankTransaction::STATUS_UNMATCHED;
        $bt->description = 'Fuel';
        $bt->amount = 10;
        $bt->currency_code = $this->client->currency()->code;
        $bt->date = now()->format('Y-m-d');
        $bt->transaction_id = 1234567890;
        $bt->category_id = 10000003;
        $bt->base_type = 'CREDIT';
        $bt->save();

        $data = [];

        $data['transactions'][] = [
            'id' => $bt->hashed_id,
            'payment_id' => $this->payment->hashed_id
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/bank_transactions/match', $data);

        $response->assertStatus(200);

        $this->assertEquals($this->payment->refresh()->transaction_id, $bt->id);
        $this->assertEquals($bt->refresh()->payment_id, $this->payment->id);
        $this->assertEquals(BankTransaction::STATUS_CONVERTED, $bt->status_id);
    }


    public function testMatchBankTransactionsValidationShouldFail()
    {
        $data = [];

        $data['transactions'][] = [
            'bad_key' => 10,
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/bank_transactions/match', $data);

        $response->assertStatus(422);
    }


    public function testMatchBankTransactionValidationShouldPass()
    {
        if (config('ninja.testvars.travis') !== false) {
            $this->markTestSkipped('Skip test for Github Actions');
        }

        $data = [];

        $bi = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi->save();

        $bt = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt->bank_integration_id = $bi->id;
        $bt->description = 'Fuel';
        $bt->amount = 10;
        $bt->currency_code = $this->client->currency()->code;
        $bt->date = now()->format('Y-m-d');
        $bt->transaction_id = 1234567890;
        $bt->category_id = 10000003;
        $bt->base_type = 'DEBIT';
        $bt->save();

        $data = [];

        $data['transactions'][] = [
            'id' => $bt->hashed_id,
        ];

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/bank_transactions/match', $data);

        $response->assertStatus(200);
    }

    public function testLinkBankTransactions()
    {
        $bi = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi->save();
        
        // Create two bank transactions
        $bt1 = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt1->bank_integration_id = $bi->id;
        $bt1->status_id = BankTransaction::STATUS_UNMATCHED;
        $bt1->amount = 100;
        $bt1->date = now()->format('Y-m-d');
        $bt1->save();
        
        $bt2 = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt2->bank_integration_id = $bi->id;
        $bt2->status_id = BankTransaction::STATUS_UNMATCHED;
        $bt2->amount = -100;
        $bt2->date = now()->format('Y-m-d');
        $bt2->save();
        
        // Test linking via API
        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson("/api/v1/bank_transactions/{$bt1->hashed_id}/link", [
            'target_transaction_id' => $bt2->hashed_id
        ]);
        
        $response->assertStatus(200);
        
        // Verify both transactions are linked
        $this->assertEquals($bt2->id, $bt1->fresh()->linked_transaction_id);
        $this->assertEquals($bt1->id, $bt2->fresh()->linked_transaction_id);
        $this->assertEquals(BankTransaction::STATUS_LINKED, $bt1->fresh()->status_id);
        $this->assertEquals(BankTransaction::STATUS_LINKED, $bt2->fresh()->status_id);
    }

    public function testUnlinkBankTransaction()
    {
        $bi = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi->save();
        
        // Create linked transactions
        $bt1 = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt1->bank_integration_id = $bi->id;
        $bt1->amount = 100;
        $bt1->save();
        
        $bt2 = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt2->bank_integration_id = $bi->id;
        $bt2->amount = -100;
        $bt2->save();
        
        // Link them
        $bt1->linkToTransaction($bt2->id);
        
        // Test unlinking
        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson("/api/v1/bank_transactions/{$bt1->hashed_id}/unlink");
        
        $response->assertStatus(200);
        
        // Verify both are unlinked
        $this->assertNull($bt1->fresh()->linked_transaction_id);
        $this->assertNull($bt2->fresh()->linked_transaction_id);
        $this->assertEquals(BankTransaction::STATUS_UNMATCHED, $bt1->fresh()->status_id);
        $this->assertEquals(BankTransaction::STATUS_UNMATCHED, $bt2->fresh()->status_id);
    }

    public function testCannotLinkAlreadyLinkedTransaction()
    {
        $bi = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi->save();
        
        // Create three transactions
        $bt1 = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt1->bank_integration_id = $bi->id;
        $bt1->save();
        
        $bt2 = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt2->bank_integration_id = $bi->id;
        $bt2->save();
        
        $bt3 = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt3->bank_integration_id = $bi->id;
        $bt3->save();
        
        // Link first two
        $bt1->linkToTransaction($bt2->id);
        
        // Try to link third to first (should fail)
        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson("/api/v1/bank_transactions/{$bt3->hashed_id}/link", [
            'target_transaction_id' => $bt1->hashed_id
        ]);
        
        $response->assertStatus(422);
    }

    public function testCannotLinkToSelf()
    {
        $bi = BankIntegrationFactory::create($this->company->id, $this->user->id, $this->account->id);
        $bi->save();
        
        $bt = BankTransactionFactory::create($this->company->id, $this->user->id);
        $bt->bank_integration_id = $bi->id;
        $bt->save();
        
        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson("/api/v1/bank_transactions/{$bt->hashed_id}/link", [
            'target_transaction_id' => $bt->hashed_id
        ]);
        
        $response->assertStatus(422);
    }

}
