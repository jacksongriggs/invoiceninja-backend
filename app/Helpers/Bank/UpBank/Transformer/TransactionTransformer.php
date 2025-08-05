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

namespace App\Helpers\Bank\UpBank\Transformer;

use App\Models\BankTransaction;
use Carbon\Carbon;

class TransactionTransformer
{
    /**
     * Transform UP Bank transaction to Invoice Ninja format
     */
    public function transform(array $transaction, int $bankIntegrationId): array
    {
        $attributes = $transaction['attributes'] ?? [];
        $relationships = $transaction['relationships'] ?? [];
        
        return [
            'bank_integration_id' => $bankIntegrationId,
            'transaction_id' => $transaction['id'],
            'up_transaction_id' => $transaction['id'],
            'amount' => $this->parseAmount($attributes['amount'] ?? []),
            'currency_id' => $this->parseCurrencyId($attributes['amount'] ?? []),
            'category_id' => null,
            'category_type' => '',
            'base_type' => $this->parseBaseType($attributes),
            'date' => $this->parseDate($attributes['createdAt'] ?? null),
            'description' => $this->parseDescription($attributes),
        ];
    }
    
    /**
     * Parse amount from UP Bank format
     */
    protected function parseAmount(array $amount): float
    {
        $valueInBaseUnits = $amount['valueInBaseUnits'] ?? 0;
        $amountValue = $valueInBaseUnits / 100; // UP Bank stores in cents
        
        // For debits (money going out), store as positive amount
        // This makes it easier to convert to expenses later
        return abs($amountValue);
    }
    
    /**
     * Parse currency to currency ID
     */
    protected function parseCurrencyId(array $amount): ?int
    {
        $code = $amount['currencyCode'] ?? 'AUD';
        $currencies = app('currencies');
        
        foreach ($currencies as $currency) {
            if ($currency->code == $code) {
                return $currency->id;
            }
        }
        
        return 12; // Default to AUD
    }
    
    /**
     * Parse transaction category
     */
    protected function parseCategory(array $relationships): ?string
    {
        $category = $relationships['category']['data'] ?? null;
        return $category ? $category['id'] : null;
    }
    
    /**
     * Parse base transaction type (debit/credit)
     */
    protected function parseBaseType(array $attributes): string
    {
        $amount = $attributes['amount']['valueInBaseUnits'] ?? 0;
        return $amount < 0 ? 'DEBIT' : 'CREDIT';
    }
    
    /**
     * Parse transaction date
     */
    protected function parseDate(?string $createdAt): ?string
    {
        if (!$createdAt) {
            return null;
        }
        
        try {
            return Carbon::parse($createdAt)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Parse account ID from relationships
     */
    protected function parseAccountId(array $relationships): ?string
    {
        $account = $relationships['account']['data'] ?? null;
        return $account ? $account['id'] : null;
    }
    
    /**
     * Parse transaction description and metadata
     */
    protected function parseDescription(array $attributes): string
    {
        $parts = [];
        
        // Main description
        if (!empty($attributes['description'])) {
            $parts[] = $attributes['description'];
        }
        
        // Add raw text if different from description
        if (!empty($attributes['rawText']) && $attributes['rawText'] !== ($attributes['description'] ?? '')) {
            $parts[] = 'Raw: ' . $attributes['rawText'];
        }
        
        // Add message if available
        if (!empty($attributes['message'])) {
            $parts[] = 'Message: ' . $attributes['message'];
        }
        
        // Add foreign exchange details
        if (!empty($attributes['foreignAmount'])) {
            $foreignAmount = $attributes['foreignAmount'];
            if (is_array($foreignAmount)) {
                $foreignValue = isset($foreignAmount['valueInBaseUnits']) 
                    ? $foreignAmount['valueInBaseUnits'] / 100 
                    : null;
                $foreignCurrency = $foreignAmount['currencyCode'] ?? null;
                
                if ($foreignValue && $foreignCurrency) {
                    $parts[] = sprintf('Foreign: %s %s', $foreignCurrency, $foreignValue);
                }
            }
        }
        
        // Add card purchase method - FIX: Handle array properly
        if (!empty($attributes['cardPurchaseMethod'])) {
            $method = $attributes['cardPurchaseMethod'];
            if (is_array($method)) {
                $method = json_encode($method); // Convert array to JSON string
            }
            $parts[] = 'Method: ' . $method;
        }
        
        return implode(' | ', $parts);
    }
    
    
    /**
     * Get categories for a transaction (for matching)
     */
    public function getCategories(array $transaction): array
    {
        $relationships = $transaction['relationships'] ?? [];
        $categories = [];
        
        // UP Bank category
        if (isset($relationships['category']['data'])) {
            $categories[] = [
                'type' => 'up_bank',
                'id' => $relationships['category']['data']['id'],
                'name' => $relationships['category']['data']['attributes']['name'] ?? 'Unknown'
            ];
        }
        
        // Parent category if available
        if (isset($relationships['parentCategory']['data'])) {
            $categories[] = [
                'type' => 'up_bank_parent',
                'id' => $relationships['parentCategory']['data']['id'],
                'name' => $relationships['parentCategory']['data']['attributes']['name'] ?? 'Unknown'
            ];
        }
        
        return $categories;
    }
    
    /**
     * Extract merchant information
     */
    public function getMerchantInfo(array $transaction): ?array
    {
        $attributes = $transaction['attributes'] ?? [];
        
        $merchant = [];
        
        if (!empty($attributes['description'])) {
            $merchant['name'] = $attributes['description'];
        }
        
        if (!empty($attributes['rawText'])) {
            $merchant['raw_name'] = $attributes['rawText'];
        }
        
        // Parse location from description if available
        // UP Bank sometimes includes location info in the description
        if (!empty($attributes['message'])) {
            $merchant['message'] = $attributes['message'];
        }
        
        return !empty($merchant) ? $merchant : null;
    }
}