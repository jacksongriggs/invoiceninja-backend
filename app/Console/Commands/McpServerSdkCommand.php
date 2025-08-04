<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Mcp\Server\Server;
use Mcp\Server\ServerRunner;
use Mcp\Types\Tool;
use Mcp\Types\ToolInputSchema;
use Mcp\Types\ToolInputProperties;
use Mcp\Types\ListToolsResult;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Add these for bank operations
use App\Models\BankIntegration;
use App\Models\BankTransaction;
use App\Helpers\Bank\UpBank\UpBank;
use App\Helpers\Bank\UpBank\Transformer\AccountTransformer;
use App\Jobs\Bank\ProcessBankTransactionsUpBank;

class McpServerSdkCommand extends Command
{
    protected $signature = 'ninja:mcp-server-sdk 
        {--stdio : Run in stdio mode for Claude Desktop}';
    protected $description = 'Start the MCP server using the official MCP SDK';

    private $debugLogger;

    public function handle()
    {
        // Suppress PHP warnings that can interfere with JSON-RPC communication
        error_reporting(E_ERROR | E_PARSE);
        
        // Set up dual logging - both stderr and file
        $logger = new Logger('mcp-server-sdk');
        
        // Always log to file for debugging
        $fileHandler = new StreamHandler(storage_path('logs/mcp-server-debug.log'), Logger::DEBUG);
        $logger->pushHandler($fileHandler);
        
        if ($this->option('stdio')) {
            // Also log to stderr for stdio mode (but only INFO and above to avoid noise)
            $stderrHandler = new StreamHandler('php://stderr', Logger::INFO);
            $logger->pushHandler($stderrHandler);
        }
        
        // Store logger for debugging
        $this->debugLogger = $logger;
        
        $this->debugLog("MCP Server starting in " . ($this->option('stdio') ? 'stdio' : 'http') . " mode");

        // Create server instance
        $server = new Server('invoice-ninja-mcp-sdk', $logger);

        // Register tools/list handler
        $server->registerHandler('tools/list', function($params) {
            $tools = $this->generateTools();
            return new ListToolsResult($tools);
        });

        // Register tools/call handler  
        $server->registerHandler('tools/call', function($params) {
            return $this->handleToolCall($params->name, $params->arguments ?? []);
        });

        if (!$this->option('stdio')) {
            $this->error('Please specify --stdio mode. For HTTP mode, use the supervisor-managed server on port 8080.');
            return 1;
        }

        // Run in stdio mode for Claude Desktop
        $initOptions = $server->createInitializationOptions();
        $runner = new ServerRunner($server, $initOptions, $logger);
        $runner->run();

        return 0;
    }

    private function debugLog(string $message, array $context = []): void
    {
        if ($this->debugLogger) {
            $this->debugLogger->info($message, $context);
        }
        
        // Also write to stderr for immediate visibility during development
        if (env('APP_DEBUG', false)) {
            fwrite(STDERR, "[MCP DEBUG] " . $message . (!empty($context) ? " " . json_encode($context) : "") . "\n");
        }
    }

    /**
     * Get entity configuration for creation patterns
     */
    private function getEntityConfiguration(string $entity): array
    {
        $configs = [
            // Entities that use repository pattern with service layer
            'Client' => ['use_repository' => true, 'use_service' => true, 'has_contacts' => true],
            'Invoice' => ['use_repository' => true, 'use_service' => true, 'has_items' => true],
            'Quote' => ['use_repository' => true, 'use_service' => true, 'has_items' => true],
            'Credit' => ['use_repository' => true, 'use_service' => true, 'has_items' => true],
            'PurchaseOrder' => ['use_repository' => true, 'use_service' => true, 'has_items' => true],
            'RecurringInvoice' => ['use_repository' => true, 'use_service' => true, 'has_items' => true],
            
            // Entities that use repository without service layer
            'Payment' => ['use_repository' => true, 'use_service' => false],
            'Expense' => ['use_repository' => true, 'use_service' => false],
            'Project' => ['use_repository' => true, 'use_service' => false],
            'Task' => ['use_repository' => true, 'use_service' => false],
            
            // Simple entities (direct model operations)
            'Product' => ['use_repository' => false, 'use_service' => false],
            'Vendor' => ['use_repository' => false, 'use_service' => false, 'has_contacts' => true],
            'TaxRate' => ['use_repository' => false, 'use_service' => false],
            'CompanyGateway' => ['use_repository' => false, 'use_service' => false],
            
            // Bank entities
            'BankIntegration' => ['use_repository' => false, 'use_service' => false],
            'BankTransaction' => ['use_repository' => false, 'use_service' => false],
        ];
        
        return $configs[$entity] ?? ['use_repository' => false, 'use_service' => false];
    }

    private function generateTools(): array
    {
        $tools = [];
        
        // Generate tools for Invoice Ninja entities
        $entities = [
            'clients' => 'Client',
            'invoices' => 'Invoice', 
            'expenses' => 'Expense',
            'payments' => 'Payment',
            'products' => 'Product',
            'projects' => 'Project',
            'quotes' => 'Quote',
            'vendors' => 'Vendor'
        ];
        
        foreach ($entities as $plural => $singular) {
            // List tool
            $listProperties = ToolInputProperties::fromArray([
                'per_page' => [
                    'type' => 'integer',
                    'description' => 'Items per page (default: 20)'
                ],
                'page' => [
                    'type' => 'integer', 
                    'description' => 'Page number (default: 1)'
                ],
                'client_id' => [
                    'type' => 'string',
                    'description' => 'Filter by client ID (for invoices, quotes, projects, payments)'
                ],
                'vendor_id' => [
                    'type' => 'string',
                    'description' => 'Filter by vendor ID (for expenses)'
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by status (for invoices, quotes)'
                ],
                'created_at' => [
                    'type' => 'string',
                    'description' => 'Filter by creation date (format: YYYY-MM-DD or YYYY-MM-DD:YYYY-MM-DD for range)'
                ],
                'is_deleted' => [
                    'type' => 'boolean',
                    'description' => 'Include deleted items (default: false)'
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Filter by name (partial match for clients, vendors)'
                ]
            ]);
            
            $listSchema = new ToolInputSchema(
                'object',
                $listProperties,
                []
            );
            
            $tools[] = new Tool(
                "list_$plural",
                "List all $plural from Invoice Ninja",
                $listSchema
            );
            
            // Get tool
            $getProperties = ToolInputProperties::fromArray([
                'id' => [
                    'type' => 'string',
                    'description' => "The $singular ID",
                    'required' => true
                ]
            ]);
            
            $getSchema = new ToolInputSchema(
                'object',
                $getProperties,
                ['id']
            );
            
            $tools[] = new Tool(
                "get_$singular",
                "Get a specific $singular by ID",
                $getSchema
            );
            
            // Create tool
            $createProperties = ToolInputProperties::fromArray([
                'data' => [
                    'type' => 'object',
                    'description' => "$singular data to create (company_id and user_id are added automatically)",
                    'required' => true
                ]
            ]);
            
            $createSchema = new ToolInputSchema(
                'object',
                $createProperties,
                ['data']
            );
            
            $tools[] = new Tool(
                "create_$singular",
                "Create a new $singular",
                $createSchema
            );
            
            // Update tool
            $updateProperties = ToolInputProperties::fromArray([
                'id' => [
                    'type' => 'string',
                    'description' => "The $singular ID to update",
                    'required' => true
                ],
                'data' => [
                    'type' => 'object',
                    'description' => "$singular data to update",
                    'required' => true
                ]
            ]);
            
            $updateSchema = new ToolInputSchema(
                'object',
                $updateProperties,
                ['id', 'data']
            );
            
            $tools[] = new Tool(
                "update_$singular",
                "Update an existing $singular",
                $updateSchema
            );
            
            // Delete tool
            $deleteProperties = ToolInputProperties::fromArray([
                'id' => [
                    'type' => 'string',
                    'description' => "The $singular ID to delete",
                    'required' => true
                ]
            ]);
            
            $deleteSchema = new ToolInputSchema(
                'object',
                $deleteProperties,
                ['id']
            );
            
            $tools[] = new Tool(
                "delete_$singular",
                "Delete a $singular",
                $deleteSchema
            );
        }
        
        // Add bank-specific tools
        $tools = array_merge($tools, $this->generateBankTools());
        
        return $tools;
    }

    /**
     * Generate bank-specific tools
     */
    private function generateBankTools(): array
    {
        $tools = [];
        
        // List bank integrations
        $listBankProperties = ToolInputProperties::fromArray([
            'per_page' => [
                'type' => 'integer',
                'description' => 'Items per page (default: 20)'
            ],
            'page' => [
                'type' => 'integer',
                'description' => 'Page number (default: 1)'
            ],
            'provider' => [
                'type' => 'string',
                'description' => 'Filter by provider (YODLEE, NORDIGEN, UPBANK)'
            ]
        ]);
        
        $tools[] = new Tool(
            'list_bank_integrations',
            'List all bank integrations',
            new ToolInputSchema('object', $listBankProperties, [])
        );
        
        // Connect UP Bank
        $connectUpBankProperties = ToolInputProperties::fromArray([
            'access_token' => [
                'type' => 'string',
                'description' => 'UP Bank Personal Access Token',
                'required' => true
            ]
        ]);
        
        $tools[] = new Tool(
            'connect_upbank',
            'Connect to UP Bank and fetch available accounts',
            new ToolInputSchema('object', $connectUpBankProperties, ['access_token'])
        );
        
        // Store UP Bank account
        $storeUpBankProperties = ToolInputProperties::fromArray([
            'account_id' => [
                'type' => 'string',
                'description' => 'UP Bank account ID to connect',
                'required' => true
            ],
            'access_token' => [
                'type' => 'string',
                'description' => 'Encrypted UP Bank access token',
                'required' => true
            ]
        ]);
        
        $tools[] = new Tool(
            'store_upbank_account',
            'Store a connected UP Bank account',
            new ToolInputSchema('object', $storeUpBankProperties, ['account_id', 'access_token'])
        );
        
        // Sync bank transactions
        $syncTransactionsProperties = ToolInputProperties::fromArray([
            'integration_id' => [
                'type' => 'string',
                'description' => 'Bank integration ID',
                'required' => true
            ]
        ]);
        
        $tools[] = new Tool(
            'sync_bank_transactions',
            'Sync transactions for a bank integration',
            new ToolInputSchema('object', $syncTransactionsProperties, ['integration_id'])
        );
        
        // List bank transactions
        $listTransactionsProperties = ToolInputProperties::fromArray([
            'integration_id' => [
                'type' => 'string',
                'description' => 'Bank integration ID'
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Filter by status (UNMATCHED, MATCHED, CONVERTED)'
            ],
            'from_date' => [
                'type' => 'string',
                'description' => 'Filter transactions from date (YYYY-MM-DD)'
            ],
            'to_date' => [
                'type' => 'string',
                'description' => 'Filter transactions to date (YYYY-MM-DD)'
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Items per page (default: 20)'
            ],
            'page' => [
                'type' => 'integer',
                'description' => 'Page number (default: 1)'
            ]
        ]);
        
        $tools[] = new Tool(
            'list_bank_transactions',
            'List bank transactions',
            new ToolInputSchema('object', $listTransactionsProperties, [])
        );
        
        return $tools;
    }

    private function handleToolCall(string $name, array $arguments): CallToolResult
    {
        $this->debugLog("Tool called: $name", ['arguments' => $arguments]);
        
        try {
            // Handle bank-specific tools
            if (strpos($name, 'bank') !== false || strpos($name, 'upbank') !== false) {
                return $this->handleBankToolCall($name, $arguments);
            }
            
            // Parse the tool name to determine action and entity
            $parts = explode('_', $name);
            $action = $parts[0];
            
            // Handle the entity name (could be multi-part like purchase_order)
            $entityParts = array_slice($parts, 1);
            $entity = implode('_', $entityParts);
            
            // Map plural to singular and determine model class
            $entityMap = [
                'clients' => 'Client',
                'invoices' => 'Invoice',
                'expenses' => 'Expense',
                'payments' => 'Payment',
                'products' => 'Product',
                'projects' => 'Project',
                'quotes' => 'Quote',
                'vendors' => 'Vendor',
                'Client' => 'Client',
                'Invoice' => 'Invoice',
                'Expense' => 'Expense',
                'Payment' => 'Payment',
                'Product' => 'Product',
                'Project' => 'Project',
                'Quote' => 'Quote',
                'Vendor' => 'Vendor'
            ];
            
            $modelName = $entityMap[$entity] ?? ucfirst($entity);
            $modelClass = "App\\Models\\$modelName";
            
            if (!class_exists($modelClass)) {
                throw new \Exception("Model class $modelClass not found");
            }
            
            switch ($action) {
                case 'list':
                    return $this->handleList($modelClass, $arguments);
                case 'get':
                    return $this->handleGet($modelClass, $arguments);
                case 'create':
                    return $this->handleCreate($modelClass, $modelName, $arguments);
                case 'update':
                    return $this->handleUpdate($modelClass, $modelName, $arguments);
                case 'delete':
                    return $this->handleDelete($modelClass, $arguments);
                default:
                    throw new \Exception("Unknown action: $action");
            }
        } catch (\Exception $e) {
            $this->debugLog("Tool call error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return new CallToolResult([
                new TextContent("Error: " . $e->getMessage())
            ]);
        }
    }

    /**
     * Handle bank-specific tool calls
     */
    private function handleBankToolCall(string $name, array $arguments): CallToolResult
    {
        try {
            $company = \App\Models\Company::first();
            $user = \App\Models\User::first();
            
            switch ($name) {
                case 'list_bank_integrations':
                    $query = BankIntegration::where('company_id', $company->id);
                    
                    if (isset($arguments['provider'])) {
                        $query->where('integration_type', $arguments['provider']);
                    }
                    
                    $integrations = $query->paginate($arguments['per_page'] ?? 20);
                    
                    return new CallToolResult([
                        new TextContent(json_encode([
                            'data' => $integrations->items(),
                            'meta' => [
                                'total' => $integrations->total(),
                                'per_page' => $integrations->perPage(),
                                'current_page' => $integrations->currentPage()
                            ]
                        ], JSON_PRETTY_PRINT))
                    ]);
                    
                case 'connect_upbank':
                    if (!isset($arguments['access_token'])) {
                        throw new \Exception('access_token is required');
                    }
                    
                    // Create temporary integration to test token
                    $tempIntegration = new BankIntegration();
                    $tempIntegration->up_access_token = encrypt($arguments['access_token']);
                    
                    $upBank = new UpBank($tempIntegration);
                    
                    if (!$upBank->validateToken()) {
                        throw new \Exception('Invalid UP Bank access token');
                    }
                    
                    // Fetch accounts
                    $accounts = $upBank->getAccounts();
                    $transformer = new AccountTransformer();
                    $transformedAccounts = $transformer->transform($accounts);
                    
                    return new CallToolResult([
                        new TextContent(json_encode([
                            'success' => true,
                            'accounts' => $transformedAccounts,
                            'encrypted_token' => encrypt($arguments['access_token'])
                        ], JSON_PRETTY_PRINT))
                    ]);
                    
                case 'store_upbank_account':
                    if (!isset($arguments['account_id']) || !isset($arguments['access_token'])) {
                        throw new \Exception('account_id and access_token are required');
                    }
                    
                    // Check if already connected
                    $existing = BankIntegration::where('company_id', $company->id)
                        ->where('up_account_id', $arguments['account_id'])
                        ->where('integration_type', BankIntegration::INTEGRATION_TYPE_UPBANK)
                        ->first();
                        
                    if ($existing) {
                        throw new \Exception('This UP Bank account is already connected');
                    }
                    
                    // Create bank integration
                    $bankIntegration = new BankIntegration();
                    $bankIntegration->company_id = $company->id;
                    $bankIntegration->account_id = $company->account_id;
                    $bankIntegration->user_id = $user->id;
                    $bankIntegration->integration_type = BankIntegration::INTEGRATION_TYPE_UPBANK;
                    $bankIntegration->provider_name = 'up_bank';
                    $bankIntegration->bank_account_id = $arguments['account_id'];
                    $bankIntegration->up_account_id = $arguments['account_id'];
                    $bankIntegration->up_access_token = $arguments['access_token'];
                    $bankIntegration->from_date = now()->subDays(90)->format('Y-m-d');
                    $bankIntegration->auto_sync = true;
                    $bankIntegration->disabled_upstream = false;
                    
                    // Fetch account details
                    $upBank = new UpBank($bankIntegration);
                    $account = $upBank->getAccount($arguments['account_id']);
                    
                    $transformer = new AccountTransformer();
                    $transformed = $transformer->transform($account);
                    
                    // Update with account details
                    $bankIntegration->bank_account_name = $transformed['account_name'];
                    $bankIntegration->bank_account_type = $transformed['account_type'];
                    $bankIntegration->bank_account_status = $transformed['account_status'];
                    $bankIntegration->bank_account_number = $transformed['account_number'];
                    $bankIntegration->balance = $transformed['current_balance'];
                    $bankIntegration->currency = $transformed['account_currency'];
                    $bankIntegration->nickname = $transformed['nickname'];
                    
                    if (isset($account['attributes']['accountType'])) {
                        $bankIntegration->up_account_type = $account['attributes']['accountType'];
                    }
                    
                    $bankIntegration->save();
                    
                    // Queue transaction sync
                    ProcessBankTransactionsUpBank::dispatch($bankIntegration, $bankIntegration->from_date);
                    
                    return new CallToolResult([
                        new TextContent(json_encode([
                            'success' => true,
                            'integration_id' => $bankIntegration->id,
                            'message' => 'UP Bank account connected successfully. Transactions are being imported in the background.'
                        ], JSON_PRETTY_PRINT))
                    ]);
                    
                case 'sync_bank_transactions':
                    if (!isset($arguments['integration_id'])) {
                        throw new \Exception('integration_id is required');
                    }
                    
                    $integration = BankIntegration::where('company_id', $company->id)
                        ->where('id', $arguments['integration_id'])
                        ->first();
                        
                    if (!$integration) {
                        throw new \Exception('Bank integration not found');
                    }
                    
                    // Dispatch appropriate job based on provider
                    if ($integration->integration_type === BankIntegration::INTEGRATION_TYPE_UPBANK) {
                        ProcessBankTransactionsUpBank::dispatch($integration);
                    }
                    
                    return new CallToolResult([
                        new TextContent(json_encode([
                            'success' => true,
                            'message' => 'Transaction sync queued successfully'
                        ], JSON_PRETTY_PRINT))
                    ]);
                    
                case 'list_bank_transactions':
                    $query = BankTransaction::where('company_id', $company->id);
                    
                    if (isset($arguments['integration_id'])) {
                        $query->where('bank_integration_id', $arguments['integration_id']);
                    }
                    
                    if (isset($arguments['status'])) {
                        $statusMap = [
                            'UNMATCHED' => BankTransaction::STATUS_UNMATCHED,
                            'MATCHED' => BankTransaction::STATUS_MATCHED,
                            'CONVERTED' => BankTransaction::STATUS_CONVERTED
                        ];
                        
                        if (isset($statusMap[$arguments['status']])) {
                            $query->where('status_id', $statusMap[$arguments['status']]);
                        }
                    }
                    
                    if (isset($arguments['from_date'])) {
                        $query->where('date', '>=', $arguments['from_date']);
                    }
                    
                    if (isset($arguments['to_date'])) {
                        $query->where('date', '<=', $arguments['to_date']);
                    }
                    
                    $transactions = $query->orderBy('date', 'desc')
                        ->paginate($arguments['per_page'] ?? 20);
                    
                    return new CallToolResult([
                        new TextContent(json_encode([
                            'data' => $transactions->items(),
                            'meta' => [
                                'total' => $transactions->total(),
                                'per_page' => $transactions->perPage(),
                                'current_page' => $transactions->currentPage()
                            ]
                        ], JSON_PRETTY_PRINT))
                    ]);
                    
                default:
                    throw new \Exception("Unknown bank tool: $name");
            }
        } catch (\Exception $e) {
            $this->debugLog("Bank tool error: " . $e->getMessage());
            
            return new CallToolResult([
                new TextContent("Error: " . $e->getMessage())
            ]);
        }
    }

    // ... rest of the existing methods (handleList, handleGet, handleCreate, etc.) remain the same ...
    
    private function handleList(string $modelClass, array $arguments): CallToolResult
    {
        $company = \App\Models\Company::first();
        $query = $modelClass::where('company_id', $company->id);
        
        // Apply filters
        if (isset($arguments['client_id'])) {
            $query->where('client_id', $this->decodeId($arguments['client_id'], 'App\Models\Client'));
        }
        
        if (isset($arguments['vendor_id'])) {
            $query->where('vendor_id', $this->decodeId($arguments['vendor_id'], 'App\Models\Vendor'));
        }
        
        if (isset($arguments['status'])) {
            $query->where('status_id', $arguments['status']);
        }
        
        if (isset($arguments['created_at'])) {
            $dates = explode(':', $arguments['created_at']);
            if (count($dates) == 2) {
                $query->whereBetween('created_at', [$dates[0], $dates[1]]);
            } else {
                $query->whereDate('created_at', $dates[0]);
            }
        }
        
        if (isset($arguments['is_deleted']) && $arguments['is_deleted']) {
            $query->withTrashed();
        }
        
        if (isset($arguments['name'])) {
            $query->where('name', 'like', '%' . $arguments['name'] . '%');
        }
        
        $results = $query->paginate($arguments['per_page'] ?? 20);
        
        $data = [
            'data' => $results->items(),
            'meta' => [
                'total' => $results->total(),
                'per_page' => $results->perPage(),
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage()
            ]
        ];
        
        return new CallToolResult([
            new TextContent(json_encode($data, JSON_PRETTY_PRINT))
        ]);
    }
    
    private function handleGet(string $modelClass, array $arguments): CallToolResult
    {
        if (!isset($arguments['id'])) {
            throw new \Exception('ID is required');
        }
        
        $company = \App\Models\Company::first();
        
        $model = $modelClass::where('company_id', $company->id)
            ->where('id', $this->decodeId($arguments['id'], $modelClass))
            ->first();
        
        if (!$model) {
            throw new \Exception('Record not found');
        }
        
        return new CallToolResult([
            new TextContent(json_encode($model->toArray(), JSON_PRETTY_PRINT))
        ]);
    }
    
    private function handleCreate(string $modelClass, string $modelName, array $arguments): CallToolResult
    {
        if (!isset($arguments['data'])) {
            throw new \Exception('Data is required');
        }
        
        $data = $arguments['data'];
        $company = \App\Models\Company::first();
        $user = \App\Models\User::first();
        
        // Add required fields
        $data['company_id'] = $company->id;
        $data['user_id'] = $user->id;
        
        // Get entity configuration
        $config = $this->getEntityConfiguration($modelName);
        
        $this->debugLog("Creating $modelName", [
            'config' => $config,
            'data' => $data
        ]);
        
        if ($config['use_repository'] && $config['use_service']) {
            // Use repository + service pattern
            $repositoryClass = "App\\Repositories\\{$modelName}Repository";
            $repository = app()->make($repositoryClass);
            
            // Apply defaults
            $data = $this->applyEntityDefaults($modelName, $data, $company);
            
            $model = $repository->create($data);
            
            // Handle special entities with service layer
            if (in_array($modelName, ['Invoice', 'Quote', 'Credit', 'PurchaseOrder'])) {
                $serviceClass = "App\\Services\\{$modelName}\\{$modelName}Service";
                $service = app()->make($serviceClass);
                $model = $service->fillDefaults($model);
                $repository->save($data, $model);
            }
        } elseif ($config['use_repository']) {
            // Use repository without service
            $repositoryClass = "App\\Repositories\\{$modelName}Repository";
            $repository = app()->make($repositoryClass);
            
            $data = $this->applyEntityDefaults($modelName, $data, $company);
            $model = $repository->create($data);
        } else {
            // Direct model creation
            $data = $this->applyEntityDefaults($modelName, $data, $company);
            $model = $modelClass::create($data);
        }
        
        return new CallToolResult([
            new TextContent(json_encode([
                'success' => true,
                'id' => $model->hashed_id ?? $model->id,
                'data' => $model->toArray()
            ], JSON_PRETTY_PRINT))
        ]);
    }
    
    private function handleUpdate(string $modelClass, string $modelName, array $arguments): CallToolResult
    {
        if (!isset($arguments['id']) || !isset($arguments['data'])) {
            throw new \Exception('ID and data are required');
        }
        
        $company = \App\Models\Company::first();
        
        $model = $modelClass::where('company_id', $company->id)
            ->where('id', $this->decodeId($arguments['id'], $modelClass))
            ->first();
        
        if (!$model) {
            throw new \Exception('Record not found');
        }
        
        $config = $this->getEntityConfiguration($modelName);
        
        if ($config['use_repository']) {
            $repositoryClass = "App\\Repositories\\{$modelName}Repository";
            $repository = app()->make($repositoryClass);
            $model = $repository->save($arguments['data'], $model);
        } else {
            $model->fill($arguments['data']);
            $model->save();
        }
        
        return new CallToolResult([
            new TextContent(json_encode([
                'success' => true,
                'data' => $model->toArray()
            ], JSON_PRETTY_PRINT))
        ]);
    }
    
    private function handleDelete(string $modelClass, array $arguments): CallToolResult
    {
        if (!isset($arguments['id'])) {
            throw new \Exception('ID is required');
        }
        
        $company = \App\Models\Company::first();
        
        $model = $modelClass::where('company_id', $company->id)
            ->where('id', $this->decodeId($arguments['id'], $modelClass))
            ->first();
        
        if (!$model) {
            throw new \Exception('Record not found');
        }
        
        $model->delete();
        
        return new CallToolResult([
            new TextContent(json_encode([
                'success' => true,
                'message' => 'Record deleted successfully'
            ], JSON_PRETTY_PRINT))
        ]);
    }
    
    private function decodeId(string $hashedId, string $modelClass): int
    {
        try {
            $model = new $modelClass();
            if (method_exists($model, 'decodePrimaryKey')) {
                $decodedId = $model->decodePrimaryKey($hashedId);
                $this->debugLog("Decoded ID", [
                    'hashed' => $hashedId,
                    'decoded' => $decodedId,
                    'model' => $modelClass
                ]);
                return $decodedId;
            }
        } catch (\Exception $e) {
            $this->debugLog("Failed to decode ID, using as-is", [
                'id' => $hashedId,
                'error' => $e->getMessage()
            ]);
        }
        
        return (int) $hashedId;
    }
    
    private function applyEntityDefaults(string $entity, array $data, $company): array
    {
        // Apply entity-specific defaults
        switch ($entity) {
            case 'Client':
                $data['currency_id'] = $data['currency_id'] ?? $company->settings->currency_id ?? 1;
                $data['country_id'] = $data['country_id'] ?? $company->settings->country_id ?? 840;
                break;
                
            case 'Invoice':
            case 'Quote':
            case 'Credit':
                $data['status_id'] = $data['status_id'] ?? 1;
                $data['currency_id'] = $data['currency_id'] ?? $company->settings->currency_id ?? 1;
                $data['date'] = $data['date'] ?? now()->format('Y-m-d');
                $data['due_date'] = $data['due_date'] ?? now()->addDays(30)->format('Y-m-d');
                break;
                
            case 'Payment':
                $data['status_id'] = $data['status_id'] ?? 4;
                $data['currency_id'] = $data['currency_id'] ?? $company->settings->currency_id ?? 1;
                $data['date'] = $data['date'] ?? now()->format('Y-m-d');
                break;
                
            case 'Expense':
                $data['currency_id'] = $data['currency_id'] ?? $company->settings->currency_id ?? 1;
                $data['date'] = $data['date'] ?? now()->format('Y-m-d');
                $data['payment_date'] = $data['payment_date'] ?? now()->format('Y-m-d');
                break;
        }
        
        return $data;
    }
}