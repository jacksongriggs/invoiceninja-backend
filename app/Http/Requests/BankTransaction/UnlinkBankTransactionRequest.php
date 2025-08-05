<?php

namespace App\Http\Requests\BankTransaction;

use App\Http\Requests\Request;

class UnlinkBankTransactionRequest extends Request
{
    public function authorize(): bool
    {
        return auth()->user()->can('edit', $this->bank_transaction);
    }
    
    public function rules()
    {
        return [];
    }
}