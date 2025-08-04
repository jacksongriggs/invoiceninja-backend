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

namespace App\Http\Controllers\Bank;

use App\Http\Controllers\BaseController;
use App\Helpers\Bank\UpBank\UpBank;
use App\Helpers\Bank\UpBank\Transformer\AccountTransformer;
use App\Jobs\Bank\ProcessBankTransactionsUpBank;
use App\Models\BankIntegration;
use App\Models\BankTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class UpBankController extends BaseController
{
    /**
     * Connect to UP Bank and fetch available accounts
     */
    public function connect(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'access_token' => 'required|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid access token'], 400);
        }
        
        $company = auth()->user()->company();
        
        // Create temporary bank integration to validate token
        $tempIntegration = new BankIntegration();
        $tempIntegration->up_access_token = encrypt($request->access_token);
        
        $upBank = new UpBank($tempIntegration);
        
        if (!$upBank->validateToken()) {
            return response()->json(['error' => 'Invalid UP Bank access token'], 401);
        }
        
        try {
            // Fetch accounts
            $accounts = $upBank->getAccounts();
            $transformer = new AccountTransformer();
            
            $transformedAccounts = $transformer->transform($accounts);
            
            return response()->json([
                'accounts' => $transformedAccounts,
                'access_token' => encrypt($request->access_token), // Return encrypted token for later use
            ]);
        } catch (\Exception $e) {
            Log::error('UP Bank connection failed: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch UP Bank accounts'], 500);
        }
    }
    
    /**
     * Store a connected UP Bank account
     */
    public function storeAccount(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account_id' => 'required|string',
            'access_token' => 'required|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid request parameters'], 400);
        }
        
        $company = auth()->user()->company();
        $user = auth()->user();
        
        // Check if this account is already connected
        $existing = BankIntegration::where('company_id', $company->id)
            ->where('up_account_id', $request->account_id)
            ->where('integration_type', BankIntegration::INTEGRATION_TYPE_UPBANK)
            ->first();
            
        if ($existing) {
            return response()->json(['error' => 'This UP Bank account is already connected'], 409);
        }
        
        // Create bank integration
        $bankIntegration = new BankIntegration();
        $bankIntegration->company_id = $company->id;
        $bankIntegration->account_id = $company->account_id;
        $bankIntegration->user_id = $user->id;
        $bankIntegration->integration_type = BankIntegration::INTEGRATION_TYPE_UPBANK;
        $bankIntegration->provider_name = 'up_bank';
        $bankIntegration->bank_account_id = $request->account_id;
        $bankIntegration->up_account_id = $request->account_id;
        $bankIntegration->up_access_token = $request->access_token; // Already encrypted from connect endpoint
        $bankIntegration->from_date = now()->subDays(90)->format('Y-m-d'); // Get 90 days of history
        $bankIntegration->auto_sync = true;
        $bankIntegration->disabled_upstream = false;
        
        try {
            // Fetch account details
            $upBank = new UpBank($bankIntegration);
            $account = $upBank->getAccount($request->account_id);
            
            if (!$account) {
                return response()->json(['error' => 'Account not found'], 404);
            }
            
            $transformer = new AccountTransformer();
            $transformed = $transformer->transform($account);
            
            // Update bank integration with account details
            $bankIntegration->bank_account_name = $transformed['account_name'];
            $bankIntegration->bank_account_type = $transformed['account_type'];
            $bankIntegration->bank_account_status = $transformed['account_status'];
            $bankIntegration->bank_account_number = $transformed['account_number'];
            $bankIntegration->balance = $transformed['current_balance'];
            $bankIntegration->currency = $transformed['account_currency'];
            $bankIntegration->nickname = $transformed['nickname'];
            
            // Store UP account type
            $accountType = $account['attributes']['accountType'] ?? null;
            if ($accountType) {
                $bankIntegration->up_account_type = $accountType;
            }
            
            $bankIntegration->save();
            
            // Register webhook for real-time updates
            $this->registerWebhook($bankIntegration);
            
            // Queue initial transaction sync
            ProcessBankTransactionsUpBank::dispatch($bankIntegration, $bankIntegration->from_date);
            
            return response()->json([
                'bank_integration' => $bankIntegration,
                'message' => 'UP Bank account connected successfully. Transactions are being imported in the background.',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to store UP Bank account: ' . $e->getMessage());
            
            // Clean up if something went wrong
            if ($bankIntegration->exists) {
                $bankIntegration->delete();
            }
            
            return response()->json(['error' => 'Failed to connect UP Bank account'], 500);
        }
    }
    
    /**
     * Disconnect an UP Bank account
     */
    public function disconnect(Request $request, $id)
    {
        $company = auth()->user()->company();
        
        $bankIntegration = BankIntegration::where('id', $id)
            ->where('company_id', $company->id)
            ->where('integration_type', BankIntegration::INTEGRATION_TYPE_UPBANK)
            ->first();
            
        if (!$bankIntegration) {
            return response()->json(['error' => 'Bank integration not found'], 404);
        }
        
        try {
            // Delete webhook if it exists
            if ($bankIntegration->webhook_id) {
                $upBank = new UpBank($bankIntegration);
                $upBank->deleteWebhook($bankIntegration->webhook_id);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete UP Bank webhook: ' . $e->getMessage());
        }
        
        // Soft delete the integration
        $bankIntegration->delete();
        
        return response()->json(['message' => 'UP Bank account disconnected successfully']);
    }
    
    /**
     * Refresh transactions for an UP Bank account
     */
    public function refresh(Request $request, $id)
    {
        $company = auth()->user()->company();
        
        $bankIntegration = BankIntegration::where('id', $id)
            ->where('company_id', $company->id)
            ->where('integration_type', BankIntegration::INTEGRATION_TYPE_UPBANK)
            ->first();
            
        if (!$bankIntegration) {
            return response()->json(['error' => 'Bank integration not found'], 404);
        }
        
        // Queue transaction sync
        ProcessBankTransactionsUpBank::dispatch($bankIntegration);
        
        return response()->json(['message' => 'Transaction refresh queued successfully']);
    }
    
    /**
     * Handle UP Bank webhook events
     */
    public function webhook(Request $request, $integrationId)
    {
        // Find the bank integration
        $bankIntegration = BankIntegration::where('id', $integrationId)
            ->where('integration_type', BankIntegration::INTEGRATION_TYPE_UPBANK)
            ->first();
            
        if (!$bankIntegration) {
            Log::warning('UP Bank webhook received for unknown integration: ' . $integrationId);
            return response()->json(['error' => 'Integration not found'], 404);
        }
        
        // TODO: Verify webhook signature when UP Bank implements it
        // For now, we'll accept all webhooks from the correct endpoint
        
        $data = $request->input('data');
        
        if (!$data) {
            return response()->json(['error' => 'Invalid webhook data'], 400);
        }
        
        try {
            $eventType = $data['attributes']['eventType'] ?? null;
            
            Log::info('UP Bank webhook received', [
                'integration_id' => $integrationId,
                'event_type' => $eventType,
            ]);
            
            switch ($eventType) {
                case 'TRANSACTION_CREATED':
                case 'TRANSACTION_SETTLED':
                case 'TRANSACTION_UPDATED':
                    // Queue transaction import for the account
                    ProcessBankTransactionsUpBank::dispatch($bankIntegration);
                    break;
                    
                case 'TRANSACTION_DELETED':
                    // Handle deleted transaction
                    $this->handleDeletedTransaction($bankIntegration, $data);
                    break;
                    
                case 'PING':
                    // Webhook test ping
                    Log::info('UP Bank webhook ping received for integration: ' . $integrationId);
                    break;
                    
                default:
                    Log::warning('Unknown UP Bank webhook event type: ' . $eventType);
            }
            
            return response()->json(['status' => 'success']);
            
        } catch (\Exception $e) {
            Log::error('UP Bank webhook processing failed: ' . $e->getMessage());
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
    
    /**
     * Register a webhook for the bank integration
     */
    private function registerWebhook(BankIntegration $bankIntegration)
    {
        try {
            $webhookUrl = config('ninja.app_url') . '/api/v1/upbank/webhook/' . $bankIntegration->id;
            
            $upBank = new UpBank($bankIntegration);
            $webhook = $upBank->registerWebhook($webhookUrl, 'Invoice Ninja - ' . $bankIntegration->nickname);
            
            if (isset($webhook['id'])) {
                $bankIntegration->webhook_id = $webhook['id'];
                $bankIntegration->save();
                
                Log::info('UP Bank webhook registered', [
                    'integration_id' => $bankIntegration->id,
                    'webhook_id' => $webhook['id'],
                ]);
            }
        } catch (\Exception $e) {
            // Webhook registration is optional, don't fail the connection
            Log::warning('Failed to register UP Bank webhook: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle a deleted transaction webhook
     */
    private function handleDeletedTransaction(BankIntegration $bankIntegration, array $webhookData)
    {
        try {
            $relationships = $webhookData['relationships'] ?? [];
            $transactionData = $relationships['transaction']['data'] ?? null;
            
            if (!$transactionData || !isset($transactionData['id'])) {
                Log::warning('UP Bank deleted transaction webhook missing transaction ID');
                return;
            }
            
            $transactionId = $transactionData['id'];
            
            // Find and delete the transaction
            $transaction = BankTransaction::where('bank_integration_id', $bankIntegration->id)
                ->where(function($query) use ($transactionId) {
                    $query->where('transaction_id', $transactionId)
                          ->orWhere('up_transaction_id', $transactionId);
                })
                ->first();
                
            if ($transaction) {
                $transaction->delete();
                Log::info('UP Bank transaction deleted', [
                    'transaction_id' => $transactionId,
                    'bank_transaction_id' => $transaction->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to handle deleted UP Bank transaction: ' . $e->getMessage());
        }
    }
}