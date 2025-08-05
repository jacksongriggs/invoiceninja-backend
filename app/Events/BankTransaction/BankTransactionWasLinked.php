<?php

namespace App\Events\BankTransaction;

use App\Models\BankTransaction;
use App\Models\Company;
use Illuminate\Queue\SerializesModels;

class BankTransactionWasLinked
{
    use SerializesModels;

    public function __construct(
        public BankTransaction $source,
        public BankTransaction $target,
        public Company $company,
        public array $event_vars
    ) {}
}