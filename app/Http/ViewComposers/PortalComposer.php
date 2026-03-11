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

namespace App\Http\ViewComposers;

use App\Utils\Ninja;
use App\Utils\TranslationHelper;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

/**
 * Class PortalComposer.
 */
class PortalComposer
{
    public const MODULE_RECURRING_INVOICES = 1;

    public const MODULE_CREDITS = 2;

    public const MODULE_QUOTES = 4;

    public const MODULE_TASKS = 8;

    public const MODULE_EXPENSES = 16;

    public const MODULE_PROJECTS = 32;

    public const MODULE_VENDORS = 64;

    public const MODULE_TICKETS = 128;

    public const MODULE_PROPOSALS = 256;

    public const MODULE_RECURRING_EXPENSES = 512;

    public const MODULE_RECURRING_TASKS = 1024;

    public const MODULE_RECURRING_QUOTES = 2048;

    public const MODULE_INVOICES = 4096;

    public const MODULE_PROFORMAL_INVOICES = 8192;

    public const MODULE_PURCHASE_ORDERS = 16384;

    public $settings;

    /**
     * Bind data to the view.
     *
     * @param  View  $view
     * @return void
     */
    public function compose(View $view): void
    {
        $view->with($this->portalData());

        if (auth()->guard('contact')->user()) {
            App::forgetInstance('translator');
            $t = app('translator');
            $t->replace(Ninja::transformTranslations(auth()->guard('contact')->user()->client->getMergedSettings()));
        }
    }

    /**
     * @return array
     */
    private function portalData(): array
    {
        if (! auth()->guard('contact')->user()) {
            return [];
        }

        auth()->guard('contact')->user()->loadMissing(['client' => function ($query) {
            $query->without('gateway_tokens', 'documents'); // Exclude 'grandchildren' relation of 'client'
        }]);

        $this->settings = auth()->guard('contact')->user()->client->getMergedSettings();

        $data['sidebar'] = $this->sidebarMenu();
        $data['header'] = [];
        $data['footer'] = [];
        $data['countries'] = app('countries');
        $data['company'] = auth()->guard('contact')->user()->company;
        $data['client'] = auth()->guard('contact')->user()->client;
        $data['settings'] = $this->settings;
        $data['currencies'] = app('currencies');
        $data['contact'] = auth()->guard('contact')->user();

        $data['multiple_contacts'] = session()->get('multiple_contacts') ?: collect();

        return $data;
    }

    private function sidebarMenu(): array
    {
        $enabled_modules = auth()->guard('contact')->user()->company->enabled_modules;
        $client = auth()->guard('contact')->user()->client;
        $data = [];

        // Get counts for all relationships in a single query to optimize performance
        $counts = [
            'invoices' => $client->invoices()->exists(),
            'recurring_invoices' => $client->recurring_invoices()->exists(),
            'payments' => $client->payments()->exists(),
            'quotes' => $client->quotes()->exists(),
            'credits' => $client->credits()->exists(),
            'payment_methods' => $client->gateway_tokens()->exists(),
            'documents' => $client->documents()->exists(),
            'tasks' => $client->tasks()->exists(),
            // Subscriptions in client portal are recurring invoices with subscription_id
            'subscriptions' => $client->recurring_invoices()
                ->where('status_id', \App\Models\RecurringInvoice::STATUS_ACTIVE)
                ->whereNotNull('subscription_id')
                ->exists(),
        ];

        // Dashboard always shows if enabled
        if ($this->settings->enable_client_portal_dashboard) {
            $data[] = [ 'title' => ctrans('texts.dashboard'), 'url' => 'client.dashboard', 'icon' => 'activity', 'id' => 'dashboard'];
        }

        // Only show invoices tab if there are invoices
        if ((self::MODULE_INVOICES & $enabled_modules) && $counts['invoices']) {
            $data[] = ['title' => ctrans('texts.invoices'), 'url' => 'client.invoices.index', 'icon' => 'file-text', 'id' => 'invoices'];
        }

        // Only show recurring invoices tab if there are recurring invoices
        if ((self::MODULE_RECURRING_INVOICES & $enabled_modules) && $counts['recurring_invoices']) {
            $data[] = ['title' => ctrans('texts.recurring_invoices'), 'url' => 'client.recurring_invoices.index', 'icon' => 'file', 'id' => 'recurring_invoices'];
        }

        // Only show payments tab if there are payments
        if ($counts['payments']) {
            $data[] = ['title' => ctrans('texts.payments'), 'url' => 'client.payments.index', 'icon' => 'credit-card', 'id' => 'payments'];
        }

        // Only show quotes tab if there are quotes
        if ((self::MODULE_QUOTES & $enabled_modules) && $counts['quotes']) {
            $data[] = ['title' => ctrans('texts.quotes'), 'url' => 'client.quotes.index', 'icon' => 'align-left', 'id' => 'quotes'];
        }

        // Only show credits tab if there are credits
        if ((self::MODULE_CREDITS & $enabled_modules) && $counts['credits']) {
            $data[] = ['title' => ctrans('texts.credits'), 'url' => 'client.credits.index', 'icon' => 'credit-card', 'id' => 'credits'];
        }

        // Payment methods show if client has any payment methods
        if ($counts['payment_methods']) {
            $data[] = ['title' => ctrans('texts.payment_methods'), 'url' => 'client.payment_methods.index', 'icon' => 'shield', 'id' => 'payment_methods'];
        }

        // Documents show if there are documents
        if ($counts['documents']) {
            $data[] = ['title' => ctrans('texts.documents'), 'url' => 'client.documents.index', 'icon' => 'download', 'id' => 'documents'];
        }

        // Tasks show if enabled and there are tasks
        if (auth()->guard('contact')->user()->client->getSetting('enable_client_portal_tasks') && $counts['tasks']) {
            $data[] = ['title' => ctrans('texts.tasks'), 'url' => 'client.tasks.index', 'icon' => 'clock', 'id' => 'tasks'];
            $data[] = ['title' => ctrans('texts.projects'), 'url' => 'client.projects.index', 'icon' => 'briefcase', 'id' => 'projects'];
        }

        // Statement always shows if there's any financial data
        if ($counts['invoices'] || $counts['payments']) {
            $data[] = ['title' => ctrans('texts.statement'), 'url' => 'client.statement', 'icon' => 'activity', 'id' => 'statement'];
        }

        // Subscriptions/Plan
        if (Ninja::isHosted() && auth()->guard('contact')->user() && auth()->guard('contact')->user()->company_id == config('ninja.ninja_default_company_id')) {
            // Always show plan for hosted ninja accounts
            $data[] = ['title' => ctrans('texts.plan'), 'url' => 'client.plan', 'icon' => 'credit-card', 'id' => 'plan'];
        } else {
            // Only show subscriptions if there are any
            if ($counts['subscriptions']) {
                $data[] = ['title' => ctrans('texts.subscriptions'), 'url' => 'client.subscriptions.index', 'icon' => 'calendar', 'id' => 'subsciptions'];
            }
        }

        // Pre-payment always shows if enabled
        if (auth()->guard('contact')->user()->client->getSetting('client_initiated_payments')) {
            $data[] = ['title' => ctrans('texts.pre_payment'), 'url' => 'client.pre_payments.index', 'icon' => 'dollar-sign', 'id' => 'pre_payment'];
        }

        return $data;
    }
}
