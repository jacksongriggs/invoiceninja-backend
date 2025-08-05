<?php

namespace App\Events\BankTransaction;

use App\Models\BankTransaction;
use App\Models\Company;
use Illuminate\Queue\SerializesModels;

class BankTransactionWasUnlinked
{
    use SerializesModels;

    public function __construct(
        public BankTransaction $transaction,
        public Company $company,
        public array $event_vars
    ) {}
}