<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('linked_transaction_id')->nullable()->after('payment_id');
            
            $table->foreign('linked_transaction_id')
                ->references('id')
                ->on('bank_transactions')
                ->onDelete('set null');
                
            $table->index('linked_transaction_id');
        });
    }

    public function down()
    {
        // Reset any linked transactions to unmatched status
        DB::table('bank_transactions')
            ->where('status_id', 4) // STATUS_LINKED
            ->update(['status_id' => 1]); // STATUS_UNMATCHED
            
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropForeign(['linked_transaction_id']);
            $table->dropColumn('linked_transaction_id');
        });
    }
};