<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Jobs\Bank;

use App\Helpers\Bank\UpBank\UpBank;
use App\Helpers\Bank\UpBank\Transformer\TransactionTransformer;
use App\Models\BankIntegration;
use App\Models\BankTransaction;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBankTransactionsUpBank implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;
    
    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300;
    
    /**
     * Create a new job instance.
     */
    public function __construct(
        private BankIntegration $bankIntegration,
        private ?string $fromDate = null
    ) {}
    
    /**
     * Execute the job.
     */
    public function handle()
    {
        if ($this->bankIntegration->integration_type !== BankIntegration::INTEGRATION_TYPE_UPBANK) {
            Log::warning('ProcessBankTransactionsUpBank called for non-UP Bank integration', [
                'integration_id' => $this->bankIntegration->id,
                'integration_type' => $this->bankIntegration->integration_type,
            ]);
            return;
        }
        
        if ($this->bankIntegration->disabled_upstream) {
            Log::info('UP Bank integration is disabled upstream', [
                'integration_id' => $this->bankIntegration->id,
            ]);
            return;
        }
        
        Log::info('Processing UP Bank transactions', [
            'integration_id' => $this->bankIntegration->id,
            'from_date' => $this->fromDate,
        ]);
        
        $upBank = new UpBank($this->bankIntegration);
        
        // Update account balance
        $this->updateAccountBalance($upBank);
        
        // Fetch and process transactions
        $this->processTransactions($upBank);
        
        // Skip matching service for now - not implemented yet
        Log::info('UP Bank transaction processing completed', [
            'integration_id' => $this->bankIntegration->id,
        ]);
    }
    
    /**
     * Update the account balance
     */
    private function updateAccountBalance(UpBank $upBank): void
    {
        try {
            $balance = $upBank->getBalance($this->bankIntegration->up_account_id);
            
            $this->bankIntegration->balance = isset($balance['valueInBaseUnits']) 
                ? $balance['valueInBaseUnits'] / 100 
                : 0;
            $this->bankIntegration->currency = $balance['currencyCode'] ?? 'AUD';
            $this->bankIntegration->save();
            
            Log::info('UP Bank balance updated', [
                'integration_id' => $this->bankIntegration->id,
                'balance' => $this->bankIntegration->balance,
                'currency' => $this->bankIntegration->currency,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update UP Bank balance', [
                'integration_id' => $this->bankIntegration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Process transactions from UP Bank
     */
    private function processTransactions(UpBank $upBank): void
    {
        // Determine the date range for fetching transactions
        $fromDate = $this->fromDate ?: $this->bankIntegration->from_date;
        
        if (!$fromDate) {
            // Default to 30 days ago if no from_date is set
            $fromDate = now()->subDays(30)->format('Y-m-d');
        }
        
        // UP Bank expects ISO 8601 format with timezone
        $fromDateTime = Carbon::parse($fromDate)->startOfDay()->toIso8601String();
        
        $filters = [
            'filter[since]' => $fromDateTime,
            'filter[status]' => 'SETTLED', // Only get settled transactions
            'page[size]' => 100,
        ];
        
        try {
            // Fetch transactions for the specific account
            $transactions = $upBank->getTransactions(
                $this->bankIntegration->up_account_id, 
                $filters
            );
            
            Log::info('Fetched UP Bank transactions', [
                'integration_id' => $this->bankIntegration->id,
                'count' => count($transactions),
                'from_date' => $fromDate,
            ]);
            
            $transformer = new TransactionTransformer();
            $processedCount = 0;
            $skippedCount = 0;
            
            foreach ($transactions as $transaction) {
                // Check if transaction already exists
                $existingTransaction = BankTransaction::where('bank_integration_id', $this->bankIntegration->id)
                    ->where(function($query) use ($transaction) {
                        $query->where('transaction_id', $transaction['id'])
                              ->orWhere('up_transaction_id', $transaction['id']);
                    })
                    ->first();
                    
                if ($existingTransaction) {
                    $skippedCount++;
                    continue;
                }
                
                // Transform and create new transaction
                $transformedTransaction = $transformer->transform($transaction, $this->bankIntegration->id);
                
                // Create BankTransaction record
                $bankTransaction = new BankTransaction();
                $bankTransaction->fill($transformedTransaction);
                $bankTransaction->save();
                
                $processedCount++;
                
                Log::debug('Created UP Bank transaction', [
                    'integration_id' => $this->bankIntegration->id,
                    'transaction_id' => $transaction['id'],
                    'amount' => $transformedTransaction['amount'],
                    'description' => $transformedTransaction['description'],
                ]);
            }
            
            Log::info('UP Bank transaction processing completed', [
                'integration_id' => $this->bankIntegration->id,
                'processed' => $processedCount,
                'skipped' => $skippedCount,
                'total' => count($transactions),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to process UP Bank transactions', [
                'integration_id' => $this->bankIntegration->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }
}