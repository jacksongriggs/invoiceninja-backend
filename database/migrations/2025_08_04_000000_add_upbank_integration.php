<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bank_integrations', function (Blueprint $table) {
            $table->string('up_account_id')->nullable();
            $table->enum('up_account_type', ['SAVER', 'TRANSACTIONAL', 'HOME_LOAN'])->nullable();
            $table->text('up_access_token')->nullable(); // encrypted
            $table->string('webhook_id')->nullable(); // Store webhook ID for management
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_integrations', function (Blueprint $table) {
            $table->dropColumn(['up_account_id', 'up_account_type', 'up_access_token', 'webhook_id']);
        });
    }
};