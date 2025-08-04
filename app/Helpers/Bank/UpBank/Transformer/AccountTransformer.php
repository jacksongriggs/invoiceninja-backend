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

use App\Helpers\Bank\AccountTransformerInterface;

/**
 * UP Bank Account Response Example:
 * {
 *   "type": "accounts",
 *   "id": "unique-id",
 *   "attributes": {
 *     "displayName": "Spending",
 *     "accountType": "TRANSACTIONAL", // or SAVER, HOME_LOAN
 *     "balance": {
 *       "currencyCode": "AUD",
 *       "value": "1234.56",
 *       "valueInBaseUnits": 123456
 *     },
 *     "createdAt": "2024-01-01T00:00:00Z"
 *   }
 * }
 */
class AccountTransformer implements AccountTransformerInterface
{
    public function transform($account)
    {
        // Handle both single account and array of accounts
        if (is_array($account) && !isset($account['type'])) {
            return array_map([$this, 'transformSingle'], $account);
        }
        
        return $this->transformSingle($account);
    }
    
    private function transformSingle($account): array
    {
        // Support both array and object access
        if (is_object($account)) {
            $account = json_decode(json_encode($account), true);
        }
        
        $attributes = $account['attributes'] ?? [];
        $balance = $attributes['balance'] ?? [];
        
        return [
            'id' => $account['id'],
            'account_type' => $this->mapAccountType($attributes['accountType'] ?? ''),
            'account_name' => $attributes['displayName'] ?? '',
            'account_status' => 'ACTIVE', // UP Bank accounts are always active if accessible
            'account_number' => '**** ' . substr($account['id'], -4), // Use last 4 chars of ID as account number
            'provider_account_id' => $account['id'],
            'provider_id' => 'upbank',
            'provider_name' => 'UP Bank',
            'nickname' => $attributes['displayName'] ?? '',
            'current_balance' => isset($balance['valueInBaseUnits']) ? $balance['valueInBaseUnits'] / 100 : 0,
            'account_currency' => $balance['currencyCode'] ?? 'AUD',
        ];
    }
    
    /**
     * Map UP Bank account types to Invoice Ninja account types
     */
    private function mapAccountType(string $upType): string
    {
        return match($upType) {
            'SAVER' => 'savings',
            'TRANSACTIONAL' => 'bank',
            'HOME_LOAN' => 'loan',
            default => 'bank'
        };
    }
}