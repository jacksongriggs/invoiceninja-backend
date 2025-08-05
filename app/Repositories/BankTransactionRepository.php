<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Repositories;

use App\Jobs\Bank\MatchBankTransactions;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Activity;
use App\Models\Payment;
use App\Events\BankTransaction\BankTransactionWasLinked;
use App\Events\BankTransaction\BankTransactionWasUnlinked;
use App\Utils\Ninja;
use Illuminate\Support\Carbon;
use App\Repositories\ActivityRepository;

/**
 * Class for bank transaction repository.
 */
class BankTransactionRepository extends BaseRepository
{
    public function save($data, BankTransaction $bank_transaction)
    {
        if (array_key_exists('bank_integration_id', $data)) {
            $bank_transaction->bank_integration_id = $data['bank_integration_id'];
        }

        $bank_transaction->fill($data);
        $bank_transaction->save();

        $bank_transaction->service()->processRules();

        return $bank_transaction->fresh();
    }

    public function convert_matched($bank_transactions)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $data['transactions'] = $bank_transactions->map(function ($bt) {
            return ['id' => $bt->id, 'invoice_ids' => $bt->invoice_ids, 'ninja_category_id' => $bt->ninja_category_id];
        })->toArray();

        $bts = (new MatchBankTransactions($user->company()->id, $user->company()->db, $data))->handle();
    }


    public function delete($entity)
    {
        if (!$entity || $entity->is_deleted) {
            return;
        }

        $bt = $this->unlink($entity);

        parent::delete($bt);

    }

    public function unlink($bank_transaction)
    {
        if ($bank_transaction->payment_id) {
            $payment = Payment::withTrashed()->find($bank_transaction->payment_id);
            
            if ($payment) {
                $payment->transaction_id = null;
                $payment->saveQuietly();
            }
        }

        // Add linked transaction handling
        if ($bank_transaction->linked_transaction_id) {
            $this->unlinkTransaction($bank_transaction->id);
        }

        $bank_transaction->payment_id = null;
        $bank_transaction->expense_id = null;
        $bank_transaction->vendor_id = null;
        $bank_transaction->status_id = BankTransaction::STATUS_UNMATCHED;
        $bank_transaction->ninja_category_id = null;
        $bank_transaction->linked_transaction_id = null;
        $bank_transaction->saveQuietly();
        
        return $bank_transaction;
    }

    public function linkTransactions($source_id, $target_id)
    {
        $source = BankTransaction::find($source_id);
        $target = BankTransaction::find($target_id);
        
        if (!$source || !$target) {
            return false;
        }
        
        // Ensure same company
        if ($source->company_id !== $target->company_id) {
            return false;
        }
        
        $result = $source->linkToTransaction($target_id);
        
        if ($result) {
            // Log activity using ActivityRepository pattern
            $activity_repo = new ActivityRepository();
            $fields = new \stdClass();
            $fields->user_id = auth()->user()->id ?? null;
            $fields->company_id = $source->company_id;
            $fields->activity_type_id = Activity::LINK_BANK_TRANSACTION;
            $fields->notes = "Linked bank transaction {$source->id} to {$target->id}";
            $fields->account_id = $source->company->account_id;
            
            $activity_repo->save($fields, $source, Ninja::eventVars(auth()->user() ? auth()->user()->id : null));
            
            // Dispatch event
            event(new BankTransactionWasLinked(
                $source->fresh(),
                $target->fresh(),
                $source->company,
                Ninja::eventVars(auth()->user() ? auth()->user()->id : null)
            ));
        }
        
        return $result;
    }

    public function unlinkTransaction($transaction_id)
    {
        $transaction = BankTransaction::find($transaction_id);
        
        if ($transaction && $transaction->isLinked()) {
            $transaction->unlink();
            
            // Log activity using ActivityRepository pattern
            $activity_repo = new ActivityRepository();
            $fields = new \stdClass();
            $fields->user_id = auth()->user()->id ?? null;
            $fields->company_id = $transaction->company_id;
            $fields->activity_type_id = Activity::UNLINK_BANK_TRANSACTION;
            $fields->notes = "Unlinked bank transaction {$transaction->id}";
            $fields->account_id = $transaction->company->account_id;
            
            $activity_repo->save($fields, $transaction, Ninja::eventVars(auth()->user() ? auth()->user()->id : null));
            
            // Dispatch event
            event(new BankTransactionWasUnlinked(
                $transaction->fresh(),
                $transaction->company,
                Ninja::eventVars(auth()->user() ? auth()->user()->id : null)
            ));
            
            return true;
        }
        
        return false;
    }

    public function autoDetectTransfers($company_id, $date_range_days = 2)
    {
        $cutoff_date = Carbon::now()->subDays($date_range_days);
        
        $unlinked_transactions = BankTransaction::where('company_id', $company_id)
            ->whereNull('linked_transaction_id')
            ->whereNull('expense_id')
            ->whereNull('payment_id')
            ->where('status_id', BankTransaction::STATUS_UNMATCHED)
            ->where('date', '>=', $cutoff_date)
            ->orderBy('date', 'desc')
            ->get();
        
        $linked_count = 0;
        
        foreach ($unlinked_transactions as $transaction) {
            // Skip if already processed
            if ($transaction->fresh()->isLinked()) {
                continue;
            }
            
            // Find potential match
            $match = BankTransaction::where('company_id', $company_id)
                ->whereNull('linked_transaction_id')
                ->whereNull('expense_id')
                ->whereNull('payment_id')
                ->where('status_id', BankTransaction::STATUS_UNMATCHED)
                ->where('id', '!=', $transaction->id)
                ->where('amount', -$transaction->amount) // Opposite amount
                ->whereBetween('date', [
                    Carbon::parse($transaction->date)->subHours(48),
                    Carbon::parse($transaction->date)->addHours(48)
                ])
                ->first();
                
            if ($match && $this->linkTransactions($transaction->id, $match->id)) {
                $linked_count++;
            }
        }
        
        return $linked_count;
    }


    public function auto_link($bank_transaction)
    {
        // For bulk action support
        return $this->autoDetectTransfers($bank_transaction->company_id, 7);
    }
}
