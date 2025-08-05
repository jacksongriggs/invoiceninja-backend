<?php

namespace App\Http\Requests\BankTransaction;

use App\Http\Requests\Request;
use App\Models\BankTransaction;

class LinkBankTransactionRequest extends Request
{
    public function authorize(): bool
    {
        $user = auth()->user();
        
        // Check permission on source transaction
        if (!$user->can('edit', $this->bank_transaction)) {
            return false;
        }
        
        // Check permission on target transaction
        if ($this->has('target_transaction_id')) {
            $target_id = $this->decodePrimaryKey($this->input('target_transaction_id'));
            $target = BankTransaction::find($target_id);
            
            if ($target && !$user->can('edit', $target)) {
                return false;
            }
        }
        
        return true;
    }

    public function rules()
    {
        return [
            'target_transaction_id' => [
                'required',
                'bail',
                function ($attribute, $value, $fail) {
                    $target_id = $this->decodePrimaryKey($value);
                    
                    // Check if target exists and belongs to same company
                    $target = BankTransaction::where('id', $target_id)
                        ->where('company_id', auth()->user()->company()->id)
                        ->first();
                        
                    if (!$target) {
                        $fail('The selected target transaction id is invalid.');
                        return;
                    }
                    
                    // Prevent self-linking
                    if ($target_id == $this->bank_transaction->id) {
                        $fail('Cannot link a transaction to itself.');
                    }
                    
                    // Check if target is already linked
                    if ($target->isLinked()) {
                        $fail('Target transaction is already linked.');
                    }
                    
                    // Check if source is already linked
                    if ($this->bank_transaction->isLinked()) {
                        $fail('This transaction is already linked.');
                    }
                }
            ],
        ];
    }
}