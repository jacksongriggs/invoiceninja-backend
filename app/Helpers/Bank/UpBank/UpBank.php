<?php

namespace App\Helpers\Bank\UpBank;

use App\Helpers\Bank\BankDataService;
use App\Models\BankIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpBank
{
    private string $apiUrl = 'https://api.up.com.au/api/v1';
    private string $accessToken;
    private BankIntegration $bankIntegration;
    
    public function __construct(BankIntegration $bankIntegration)
    {
        $this->bankIntegration = $bankIntegration;
        if ($bankIntegration->up_access_token) {
            $this->accessToken = decrypt($bankIntegration->up_access_token);
        }
    }
    
    /**
     * Validate the UP Bank access token
     */
    public function validateToken(): bool
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->get("{$this->apiUrl}/util/ping");
                
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('UP Bank token validation failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all UP Bank accounts
     */
    public function getAccounts(): array
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->apiUrl}/accounts");
            
        if (!$response->successful()) {
            throw new \Exception('Failed to fetch UP Bank accounts: ' . $response->body());
        }
        
        return $response->json()['data'] ?? [];
    }
    
    /**
     * Get a specific account by ID
     */
    public function getAccount(string $accountId): array
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->apiUrl}/accounts/{$accountId}");
            
        if (!$response->successful()) {
            throw new \Exception('Failed to fetch UP Bank account: ' . $response->body());
        }
        
        return $response->json()['data'] ?? [];
    }
    
    /**
     * Get transactions for an account
     */
    public function getTransactions(string $accountId, array $filters = []): array
    {
        $defaultFilters = [
            'page[size]' => 100,
        ];
        
        $filters = array_merge($defaultFilters, $filters);
        
        $response = Http::withToken($this->accessToken)
            ->get("{$this->apiUrl}/accounts/{$accountId}/transactions", $filters);
            
        if (!$response->successful()) {
            throw new \Exception('Failed to fetch UP Bank transactions: ' . $response->body());
        }
        
        $data = $response->json();
        $transactions = $data['data'] ?? [];
        
        // Handle pagination if needed
        while (isset($data['links']['next']) && $data['links']['next']) {
            $response = Http::withToken($this->accessToken)
                ->get($data['links']['next']);
                
            if (!$response->successful()) {
                break;
            }
            
            $data = $response->json();
            $transactions = array_merge($transactions, $data['data'] ?? []);
        }
        
        return $transactions;
    }
    
    /**
     * Get all transactions (from all accounts)
     */
    public function getAllTransactions(array $filters = []): array
    {
        $defaultFilters = [
            'page[size]' => 100,
        ];
        
        $filters = array_merge($defaultFilters, $filters);
        
        $response = Http::withToken($this->accessToken)
            ->get("{$this->apiUrl}/transactions", $filters);
            
        if (!$response->successful()) {
            throw new \Exception('Failed to fetch UP Bank transactions: ' . $response->body());
        }
        
        $data = $response->json();
        $transactions = $data['data'] ?? [];
        
        // Handle pagination if needed
        while (isset($data['links']['next']) && $data['links']['next']) {
            $response = Http::withToken($this->accessToken)
                ->get($data['links']['next']);
                
            if (!$response->successful()) {
                break;
            }
            
            $data = $response->json();
            $transactions = array_merge($transactions, $data['data'] ?? []);
        }
        
        return $transactions;
    }
    
    /**
     * Get account balance
     */
    public function getBalance(string $accountId): array
    {
        $account = $this->getAccount($accountId);
        return $account['attributes']['balance'] ?? [];
    }
    
    /**
     * Register a webhook for real-time updates
     */
    public function registerWebhook(string $url, string $description = 'Invoice Ninja Integration'): array
    {
        $response = Http::withToken($this->accessToken)
            ->post("{$this->apiUrl}/webhooks", [
                'data' => [
                    'type' => 'webhooks',
                    'attributes' => [
                        'url' => $url,
                        'description' => $description,
                    ]
                ]
            ]);
            
        if (!$response->successful()) {
            throw new \Exception('Failed to register UP Bank webhook: ' . $response->body());
        }
        
        return $response->json()['data'] ?? [];
    }
    
    /**
     * Delete a webhook
     */
    public function deleteWebhook(string $webhookId): bool
    {
        $response = Http::withToken($this->accessToken)
            ->delete("{$this->apiUrl}/webhooks/{$webhookId}");
            
        return $response->successful();
    }
    
    /**
     * List all webhooks
     */
    public function listWebhooks(): array
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->apiUrl}/webhooks");
            
        if (!$response->successful()) {
            throw new \Exception('Failed to list UP Bank webhooks: ' . $response->body());
        }
        
        return $response->json()['data'] ?? [];
    }
    
    /**
     * Ping a webhook to test it
     */
    public function pingWebhook(string $webhookId): bool
    {
        $response = Http::withToken($this->accessToken)
            ->post("{$this->apiUrl}/webhooks/{$webhookId}/ping");
            
        return $response->successful();
    }
    
    /**
     * Get categories from UP Bank
     */
    public function getCategories(): array
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->apiUrl}/categories");
            
        if (!$response->successful()) {
            throw new \Exception('Failed to fetch UP Bank categories: ' . $response->body());
        }
        
        return $response->json()['data'] ?? [];
    }
    
    /**
     * Get a specific category
     */
    public function getCategory(string $categoryId): array
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->apiUrl}/categories/{$categoryId}");
            
        if (!$response->successful()) {
            throw new \Exception('Failed to fetch UP Bank category: ' . $response->body());
        }
        
        return $response->json()['data'] ?? [];
    }
}