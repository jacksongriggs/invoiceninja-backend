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
            
            // Entities that use repository pattern without service layer
            'Payment' => ['use_repository' => true, 'use_service' => true],
            'Expense' => ['use_repository' => true, 'use_service' => false],
            'Vendor' => ['use_repository' => true, 'use_service' => true, 'has_contacts' => true],
            'Product' => ['use_repository' => true, 'use_service' => false],
            'Task' => ['use_repository' => true, 'use_service' => false],
            
            // Entities that use direct model operations
            'Project' => ['use_repository' => false, 'use_service' => true],
            'TaxRate' => ['use_repository' => false, 'use_service' => false],
            'Location' => ['use_repository' => false, 'use_service' => false],
            'GroupSetting' => ['use_repository' => false, 'use_service' => false],
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
            'vendors' => 'Vendor',
            'bank_transactions' => 'BankTransaction'
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
                'is_deleted' => [
                    'type' => 'boolean',
                    'description' => 'Include deleted items (default: false)'
                ],
                'created_at' => [
                    'type' => 'string',
                    'description' => 'Filter by creation date (format: YYYY-MM-DD or YYYY-MM-DD:YYYY-MM-DD for range)'
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Filter by name (partial match for clients, vendors)'
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by status (for invoices, quotes, bank transactions: UNMATCHED, MATCHED, CONVERTED)'
                ],
                'bank_integration_id' => [
                    'type' => 'string',
                    'description' => 'Filter by bank integration ID (for bank transactions)'
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Filter by description (partial match for bank transactions)'
                ]
            ]);

            $tools[] = new Tool(
                name: "list_{$plural}",
                description: "List all {$plural} from Invoice Ninja",
                inputSchema: new ToolInputSchema(properties: $listProperties)
            );

            // Get tool
            $getProperties = ToolInputProperties::fromArray([
                'id' => [
                    'type' => 'string',
                    'description' => "The {$singular} ID"
                ]
            ]);

            $tools[] = new Tool(
                name: "get_{$singular}",
                description: "Get a specific {$singular} by ID",
                inputSchema: new ToolInputSchema(
                    properties: $getProperties,
                    required: ['id']
                )
            );

            // Create tool
            $createProperties = ToolInputProperties::fromArray([
                'data' => [
                    'type' => 'object',
                    'description' => "{$singular} data to create (company_id and user_id are added automatically)"
                ]
            ]);

            $tools[] = new Tool(
                name: "create_{$singular}",
                description: "Create a new {$singular}",
                inputSchema: new ToolInputSchema(
                    properties: $createProperties,
                    required: ['data']
                )
            );

            // Update tool
            $updateProperties = ToolInputProperties::fromArray([
                'id' => [
                    'type' => 'string',
                    'description' => "The {$singular} ID to update"
                ],
                'data' => [
                    'type' => 'object',
                    'description' => "{$singular} data to update"
                ]
            ]);

            $tools[] = new Tool(
                name: "update_{$singular}",
                description: "Update an existing {$singular}",
                inputSchema: new ToolInputSchema(
                    properties: $updateProperties,
                    required: ['id', 'data']
                )
            );

            // Delete tool
            $deleteProperties = ToolInputProperties::fromArray([
                'id' => [
                    'type' => 'string',
                    'description' => "The {$singular} ID to delete"
                ]
            ]);

            $tools[] = new Tool(
                name: "delete_{$singular}",
                description: "Delete a {$singular}",
                inputSchema: new ToolInputSchema(
                    properties: $deleteProperties,
                    required: ['id']
                )
            );
        }

        // Add special bank transaction conversion tool
        $convertProperties = ToolInputProperties::fromArray([
            'bank_transaction_id' => [
                'type' => 'string',
                'description' => 'The bank transaction ID to convert'
            ],
            'expense_id' => [
                'type' => 'string',
                'description' => 'The expense ID to link to (optional - will create new if not provided)'
            ],
            'vendor_id' => [
                'type' => 'string',
                'description' => 'The vendor ID for the expense'
            ],
            'ninja_category_id' => [
                'type' => 'string',
                'description' => 'The expense category ID (optional)'
            ]
        ]);

        $tools[] = new Tool(
            name: 'convert_bank_transaction',
            description: 'Convert a bank transaction to an expense using the proper Invoice Ninja workflow',
            inputSchema: new ToolInputSchema(
                properties: $convertProperties,
                required: ['bank_transaction_id']
            )
        );

        return $tools;
    }

    private function handleToolCall(string $toolName, array $arguments): CallToolResult
    {
        $this->debugLog("handleToolCall called", ['tool' => $toolName, 'arguments' => $arguments]);
        
        try {
            // Set a timeout for operations
            set_time_limit(30); // 30 second timeout
            
            // Handle special bank transaction conversion tool
            if ($toolName === 'convert_bank_transaction') {
                return $this->handleBankTransactionConversion($arguments);
            }
            
            // Parse tool name to determine action and entity
            $parts = explode('_', $toolName);
            $action = $parts[0]; // list, get, create, update, delete
            $entity = implode('', array_map('ucfirst', array_slice($parts, 1)));
            
            $this->debugLog("Parsed tool call", ['action' => $action, 'entity' => $entity]);
            
            // Handle plural forms for list operations
            if ($action === 'list' && substr($entity, -1) === 's') {
                $entity = substr($entity, 0, -1);
            }

            $result = $this->callInternalApi($action, $entity, $arguments);
            
            $this->debugLog("Tool call completed successfully", ['tool' => $toolName]);

            return new CallToolResult(
                content: [new TextContent(
                    text: json_encode($result, JSON_PRETTY_PRINT)
                )]
            );

        } catch (\Exception $e) {
            $this->debugLog("Tool call failed", [
                'tool' => $toolName, 
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new CallToolResult(
                content: [new TextContent(
                    text: 'Error: ' . $e->getMessage()
                )],
                isError: true
            );
        } finally {
            // Reset timeout
            set_time_limit(0);
        }
    }

    private function callInternalApi(string $action, string $entity, array $arguments): array
    {
        $this->debugLog("callInternalApi called", ['action' => $action, 'entity' => $entity]);
        
        // Use Laravel models directly
        $modelClass = "\\App\\Models\\{$entity}";
        
        if (!class_exists($modelClass)) {
            throw new \Exception("Model not found: {$modelClass}");
        }
        
        switch ($action) {
            case 'list':
                return $this->handleListOperation($entity, $modelClass, $arguments);
                
            case 'get':
                return $this->handleGetOperation($entity, $modelClass, $arguments);
                
            case 'create':
                return $this->handleCreateOperation($entity, $modelClass, $arguments);
                
            case 'update':
                return $this->handleUpdateOperation($entity, $modelClass, $arguments);
                
            case 'delete':
                return $this->handleDeleteOperation($entity, $modelClass, $arguments);
                
            default:
                throw new \Exception("Unsupported action: {$action}");
        }
    }

    private function handleListOperation(string $entity, string $modelClass, array $arguments): array
    {
        $this->debugLog("Executing list operation", ['entity' => $entity, 'arguments' => $arguments]);
        
        $perPage = $arguments['per_page'] ?? 20;
        $page = $arguments['page'] ?? 1;
        
        // Always use direct query approach (filter classes require auth context)
        // Build query with company scope
        $company = \App\Models\Company::first();
        $query = $modelClass::where('company_id', $company->id);
            
        
        // Apply common filters if provided
        if (isset($arguments['client_id'])) {
            $decodedId = $this->decodeHashedId($arguments['client_id']);
            if ($decodedId) {
                $query->where('client_id', $decodedId);
                $this->debugLog("Applied client_id filter", ['client_id' => $decodedId]);
            }
        }
        if (isset($arguments['vendor_id'])) {
            $decodedId = $this->decodeHashedId($arguments['vendor_id']);
            if ($decodedId) {
                $query->where('vendor_id', $decodedId);
                $this->debugLog("Applied vendor_id filter", ['vendor_id' => $decodedId]);
            }
        }
        if (isset($arguments['is_deleted'])) {
            $isDeleted = $arguments['is_deleted'] === 'true' || $arguments['is_deleted'] === true;
            $query->where('is_deleted', $isDeleted);
            $this->debugLog("Applied is_deleted filter", ['is_deleted' => $isDeleted]);
        }
        if (isset($arguments['name'])) {
            $query->where('name', 'like', '%' . $arguments['name'] . '%');
            $this->debugLog("Applied name filter", ['name' => $arguments['name']]);
        }
        if (isset($arguments['status'])) {
            // Handle status filter for invoices/quotes
            $query->where('status_id', $arguments['status']);
            $this->debugLog("Applied status filter", ['status' => $arguments['status']]);
        }
        if (isset($arguments['created_at'])) {
            // Handle date range filter
            $this->applyDateRangeFilter($query, 'created_at', $arguments['created_at']);
            $this->debugLog("Applied created_at filter", ['created_at' => $arguments['created_at']]);
        }
        if (isset($arguments['updated_at'])) {
            // Handle date range filter
            $this->applyDateRangeFilter($query, 'updated_at', $arguments['updated_at']);
            $this->debugLog("Applied updated_at filter", ['updated_at' => $arguments['updated_at']]);
        }
        
        // Bank transaction specific filters
        if ($entity === 'BankTransaction') {
            if (isset($arguments['bank_integration_id'])) {
                $decodedId = $this->decodeHashedId($arguments['bank_integration_id']);
                if ($decodedId) {
                    $query->where('bank_integration_id', $decodedId);
                    $this->debugLog("Applied bank_integration_id filter", ['bank_integration_id' => $decodedId]);
                }
            }
            
            if (isset($arguments['description'])) {
                $query->where('description', 'like', '%' . $arguments['description'] . '%');
                $this->debugLog("Applied description filter", ['description' => $arguments['description']]);
            }
            
            // Handle bank transaction status mapping
            if (isset($arguments['status'])) {
                $statusMap = [
                    'UNMATCHED' => \App\Models\BankTransaction::STATUS_UNMATCHED,
                    'MATCHED' => \App\Models\BankTransaction::STATUS_MATCHED,
                    'CONVERTED' => \App\Models\BankTransaction::STATUS_CONVERTED
                ];
                
                if (isset($statusMap[$arguments['status']])) {
                    $query->where('status_id', $statusMap[$arguments['status']]);
                    $this->debugLog("Applied bank transaction status filter", ['status' => $arguments['status'], 'status_id' => $statusMap[$arguments['status']]]);
                }
            }
        }
        
        // Get paginated results
        $results = $query->paginate($perPage, ['*'], 'page', $page);
        $this->debugLog("Query executed", ['total' => $results->total(), 'page' => $page]);
        
        // Use transformer if available
        $transformerClass = "\\App\\Transformers\\{$entity}Transformer";
        if (class_exists($transformerClass)) {
            $transformer = new $transformerClass();
            $data = $results->getCollection()->map(function ($item) use ($transformer) {
                return $transformer->transform($item);
            });
        } else {
            $data = $results->getCollection()->toArray();
        }
        
        return [
            'data' => $data,
            'meta' => [
                'pagination' => [
                    'total' => $results->total(),
                    'count' => $results->count(),
                    'per_page' => $results->perPage(),
                    'current_page' => $results->currentPage(),
                    'total_pages' => $results->lastPage(),
                ]
            ]
        ];
    }

    private function handleGetOperation(string $entity, string $modelClass, array $arguments): array
    {
        $this->debugLog("Executing get operation", ['entity' => $entity]);
        
        if (!isset($arguments['id'])) {
            throw new \Exception('ID is required for get operation');
        }
        
        // Decode the hashed ID to get the actual database ID
        $model = new $modelClass();
        $decodedId = $model->decodePrimaryKey($arguments['id']);
        
        if (!$decodedId) {
            throw new \Exception("Invalid ID: {$arguments['id']}");
        }
        
        $item = $modelClass::findOrFail($decodedId);
        
        // Use transformer if available
        $transformerClass = "\\App\\Transformers\\{$entity}Transformer";
        if (class_exists($transformerClass)) {
            $transformer = new $transformerClass();
            $data = $transformer->transform($item);
        } else {
            $data = $item->toArray();
        }
        
        return ['data' => $data];
    }

    private function handleCreateOperation(string $entity, string $modelClass, array $arguments): array
    {
        $this->debugLog("Executing create operation", ['entity' => $entity, 'data' => $arguments]);
        
        $data = $arguments['data'] ?? $arguments;
        
        // Get the first company and user for context
        $company = \App\Models\Company::first();
        $user = \App\Models\User::first();
        
        if (!$company || !$user) {
            throw new \Exception('No company or user found in the system');
        }
        
        $this->debugLog("Found company and user", ['company_id' => $company->id, 'user_id' => $user->id]);
        
        // Decode any hashed IDs in the data
        $data = $this->decodeHashedIds($data);
        
        // Apply entity-specific defaults (like currency_id for expenses)
        $data = $this->applyEntityDefaults($entity, $data, $company);
        
        $this->debugLog("Data after ID decoding and defaults", ['data' => $data]);
        
        // Get entity configuration
        $config = $this->getEntityConfiguration($entity);
        $this->debugLog("Entity configuration", ['entity' => $entity, 'config' => $config]);
        
        // Create using factory
        $factoryClass = "\\App\\Factory\\{$entity}Factory";
        if (!class_exists($factoryClass)) {
            throw new \Exception("Factory not found: {$factoryClass}");
        }
        
        $item = $factoryClass::create($company->id, $user->id);
        $this->debugLog("Factory created item", ['item_id' => $item->id ?? 'no_id']);
        
        // Handle creation based on entity configuration
        if ($config['use_repository']) {
            // Use repository pattern with Laravel's IoC container
            $repositoryClass = "\\App\\Repositories\\{$entity}Repository";
            
            if (!class_exists($repositoryClass)) {
                throw new \Exception("Repository not found: {$repositoryClass}");
            }
            
            try {
                $this->debugLog("Using repository pattern with IoC container", ['repository' => $repositoryClass]);
                
                // Use Laravel's service container to resolve dependencies
                $repository = app()->make($repositoryClass);
                $item = $repository->save($data, $item);
                
                $this->debugLog("Repository save completed", ['item_id' => $item->id ?? 'no_id']);
            } catch (\Exception $e) {
                $this->debugLog("Repository save failed, falling back to direct save", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Fallback to direct model operations
                $item->fill($data);
                $item->saveQuietly();
                $this->debugLog("Direct save completed", ['item_id' => $item->id ?? 'no_id']);
            }
        } else {
            // Use direct model operations
            $this->debugLog("Using direct model operations");
            $item->fill($data);
            $item->saveQuietly();
            $this->debugLog("Direct save completed", ['item_id' => $item->id ?? 'no_id']);
            
            // Handle special cases
            if ($entity === 'Project') {
                // Always generate number if not provided or empty
                if (empty($item->number) || $item->number === null || $item->number === '') {
                    $item->number = $this->getNextProjectNumber($item);
                    $item->saveQuietly();
                    $this->debugLog("Project number generated", ['number' => $item->number]);
                }
            }
        }
        
        // Apply service layer if configured
        if ($config['use_service'] && method_exists($item, 'service')) {
            try {
                $this->debugLog("Applying service layer");
                $service = $item->service();
                
                // Call fillDefaults if available
                if (method_exists($service, 'fillDefaults')) {
                    $this->debugLog("Calling fillDefaults");
                    $service->fillDefaults();
                }
                
                // Save through service
                if (method_exists($service, 'save')) {
                    $this->debugLog("Calling service save");
                    $item = $service->save();
                    $this->debugLog("Service save completed");
                }
            } catch (\Exception $e) {
                $this->debugLog("Service layer processing failed, continuing", ['error' => $e->getMessage()]);
                // Continue with the item as is
            }
        }
        
        // Trigger creation event
        event('eloquent.created: App\\Models\\' . $entity, $item);
        
        // Refresh the item to get all relationships
        $item = $item->fresh();
        
        // Use transformer if available
        $transformerClass = "\\App\\Transformers\\{$entity}Transformer";
        if (class_exists($transformerClass)) {
            $transformer = new $transformerClass();
            $transformedData = $transformer->transform($item);
        } else {
            $transformedData = $item->toArray();
        }
        
        $this->debugLog("Create operation completed", ['entity' => $entity, 'id' => $item->id ?? 'no_id']);
        return ['data' => $transformedData];
    }

    private function handleUpdateOperation(string $entity, string $modelClass, array $arguments): array
    {
        $this->debugLog("Executing update operation", ['entity' => $entity]);
        
        if (!isset($arguments['id'])) {
            throw new \Exception('ID is required for update operation');
        }
        
        if (!isset($arguments['data'])) {
            throw new \Exception('Data is required for update operation');
        }
        
        // Decode the hashed ID
        $model = new $modelClass();
        $decodedId = $model->decodePrimaryKey($arguments['id']);
        
        if (!$decodedId) {
            throw new \Exception("Invalid ID: {$arguments['id']}");
        }
        
        $item = $modelClass::findOrFail($decodedId);
        
        // Decode any hashed IDs in the update data
        $updateData = $this->decodeHashedIds($arguments['data']);
        
        // Get entity configuration
        $config = $this->getEntityConfiguration($entity);
        
        if ($config['use_repository']) {
            // Use repository for update
            $repositoryClass = "\\App\\Repositories\\{$entity}Repository";
            
            if (class_exists($repositoryClass)) {
                try {
                    $this->debugLog("Using repository for update", ['repository' => $repositoryClass]);
                    $repository = app()->make($repositoryClass);
                    $item = $repository->save($updateData, $item);
                    $this->debugLog("Repository update completed");
                } catch (\Exception $e) {
                    $this->debugLog("Repository update failed, using direct update", ['error' => $e->getMessage()]);
                    $item->update($updateData);
                }
            } else {
                $item->update($updateData);
            }
        } else {
            // Direct update
            $item->update($updateData);
        }
        
        // Apply service layer if configured
        if ($config['use_service'] && method_exists($item, 'service')) {
            try {
                $service = $item->service();
                if (method_exists($service, 'save')) {
                    $item = $service->save();
                }
            } catch (\Exception $e) {
                $this->debugLog("Service layer update failed, continuing", ['error' => $e->getMessage()]);
            }
        }
        
        // Use transformer if available
        $transformerClass = "\\App\\Transformers\\{$entity}Transformer";
        if (class_exists($transformerClass)) {
            $transformer = new $transformerClass();
            $transformedData = $transformer->transform($item);
        } else {
            $transformedData = $item->toArray();
        }
        
        return ['data' => $transformedData];
    }

    private function handleDeleteOperation(string $entity, string $modelClass, array $arguments): array
    {
        $this->debugLog("Executing delete operation", ['entity' => $entity]);
        
        if (!isset($arguments['id'])) {
            throw new \Exception('ID is required for delete operation');
        }
        
        // Decode the hashed ID
        $model = new $modelClass();
        $decodedId = $model->decodePrimaryKey($arguments['id']);
        
        if (!$decodedId) {
            throw new \Exception("Invalid ID: {$arguments['id']}");
        }
        
        $item = $modelClass::findOrFail($decodedId);
        
        // Get entity configuration
        $config = $this->getEntityConfiguration($entity);
        
        if ($config['use_repository']) {
            // Some repositories have special delete methods
            $repositoryClass = "\\App\\Repositories\\{$entity}Repository";
            
            if (class_exists($repositoryClass) && method_exists($repositoryClass, 'delete')) {
                try {
                    $repository = app()->make($repositoryClass);
                    $repository->delete($item);
                } catch (\Exception $e) {
                    $this->debugLog("Repository delete failed, using direct delete", ['error' => $e->getMessage()]);
                    $item->delete();
                }
            } else {
                $item->delete();
            }
        } else {
            // Direct delete
            $item->delete();
        }
        
        return ['data' => ['message' => "{$entity} deleted successfully", 'id' => $arguments['id']]];
    }

    /**
     * Apply entity-specific defaults
     */
    private function applyEntityDefaults(string $entity, array $data, $company): array
    {
        switch ($entity) {
            case 'Expense':
                // Set default currency_id if not provided (required for exchange rate calculation)
                if (!isset($data['currency_id']) || strlen($data['currency_id']) == 0) {
                    $data['currency_id'] = (string) $company->settings->currency_id;
                    $this->debugLog("Applied default currency_id for Expense", ['currency_id' => $data['currency_id']]);
                }
                break;
                
            case 'Invoice':
            case 'Quote':
            case 'Credit':
                // Set default currency_id if not provided
                if (!isset($data['currency_id']) || strlen($data['currency_id']) == 0) {
                    $data['currency_id'] = (string) $company->settings->currency_id;
                }
                break;
                
            case 'Payment':
                // Set default currency_id if not provided
                if (!isset($data['currency_id']) || strlen($data['currency_id']) == 0) {
                    $data['currency_id'] = (string) $company->settings->currency_id;
                }
                // Set default type_id if not provided
                if (!isset($data['type_id'])) {
                    $data['type_id'] = 1; // Manual payment
                }
                break;
                
            case 'Vendor':
                // Validate or remove invalid country_id
                if (isset($data['country_id'])) {
                    // Check if the country_id exists
                    $country = \App\Models\Country::find($data['country_id']);
                    if (!$country) {
                        // Use default country_id from factory or remove it
                        unset($data['country_id']);
                        $this->debugLog("Removed invalid country_id for Vendor");
                    }
                }
                
                // Validate or set default currency_id
                if (isset($data['currency_id'])) {
                    $currency = \App\Models\Currency::find($data['currency_id']);
                    if (!$currency) {
                        // Remove invalid currency_id, let the factory handle it
                        unset($data['currency_id']);
                        $this->debugLog("Removed invalid currency_id for Vendor");
                    }
                }
                break;
        }
        
        return $data;
    }
    
    /**
     * Get the next project number
     */
    private function getNextProjectNumber($project): string
    {
        // Get counter and pattern from company settings
        $counter = $project->company->settings->project_number_counter ?? 1;
        $pattern = $project->company->settings->project_number_pattern ?? '';
        
        // If no pattern is set, use a simple counter format
        if (empty($pattern)) {
            $pattern = '{$counter}';
        }
        
        // Replace the counter placeholder - the pattern uses {$counter} not as a PHP variable
        $number = str_replace('{$counter}', str_pad($counter, 4, '0', STR_PAD_LEFT), $pattern);
        
        // If the number is still empty or unchanged, use a fallback
        if (empty($number) || $number === $pattern) {
            $number = str_pad($counter, 4, '0', STR_PAD_LEFT);
        }
        
        // Update the counter in company settings
        $settings = $project->company->settings;
        $settings->project_number_counter = $counter + 1;
        $project->company->settings = $settings;
        $project->company->save();
        
        $this->debugLog("Generated project number", ['counter' => $counter, 'pattern' => $pattern, 'number' => $number]);
        
        return $number;
    }
    
    /**
     * Apply date range filter to query
     */
    private function applyDateRangeFilter($query, string $field, string $dateRange): void
    {
        // Parse date range format: "2025-01-01:2025-12-31" or ">2025-01-01" or "<2025-12-31"
        if (strpos($dateRange, ':') !== false) {
            // Range format
            list($start, $end) = explode(':', $dateRange);
            $query->whereBetween($field, [$start, $end]);
        } elseif (strpos($dateRange, '>') === 0) {
            // Greater than
            $query->where($field, '>', substr($dateRange, 1));
        } elseif (strpos($dateRange, '<') === 0) {
            // Less than
            $query->where($field, '<', substr($dateRange, 1));
        } else {
            // Exact date
            $query->whereDate($field, $dateRange);
        }
    }
    
    /**
     * Decode a single hashed ID
     */
    private function decodeHashedId(string $hashedId): ?int
    {
        try {
            $tempModel = new \App\Models\Client();
            $decodedId = $tempModel->decodePrimaryKey($hashedId);
            return $decodedId ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Decode any hashed IDs in the data array
     */
    private function decodeHashedIds(array $data): array
    {
        // List of fields that should remain numeric (not decoded)
        $numericFields = [
            'currency_id',
            'payment_type_id',
            'country_id',
            'language_id',
            'industry_id',
            'size_id',
            'tax_rate_id',
            'task_status_id',
            'expense_category_id',
            'status_id',
            'design_id',
            'gateway_type_id',
            'card_brand_id',
            'bank_id',
            'bank_account_id',
            'frequency_id'
        ];
        
        foreach ($data as $key => $value) {
            // Check if field ends with _id and value is a string (hashed ID)
            if (str_ends_with($key, '_id') && is_string($value) && !empty($value)) {
                // Skip numeric fields
                if (in_array($key, $numericFields)) {
                    $this->debugLog("Skipping numeric field", ['field' => $key, 'value' => $value]);
                    continue;
                }
                
                // Skip if value is numeric
                if (is_numeric($value)) {
                    $this->debugLog("Skipping numeric value", ['field' => $key, 'value' => $value]);
                    continue;
                }
                
                try {
                    // Try to decode the hashed ID using a concrete model
                    $tempModel = new \App\Models\Client();
                    $decodedId = $tempModel->decodePrimaryKey($value);
                    
                    if ($decodedId) {
                        $this->debugLog("Decoded hashed ID", ['field' => $key, 'original' => $value, 'decoded' => $decodedId]);
                        $data[$key] = $decodedId;
                    }
                } catch (\Exception $e) {
                    $this->debugLog("Failed to decode hashed ID", ['field' => $key, 'value' => $value, 'error' => $e->getMessage()]);
                    // If decoding fails, leave the original value
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Handle bank transaction to expense conversion using proper Invoice Ninja workflow
     */
    private function handleBankTransactionConversion(array $arguments): CallToolResult
    {
        $this->debugLog("handleBankTransactionConversion called", ['arguments' => $arguments]);
        
        try {
            // Validate required fields
            if (!isset($arguments['bank_transaction_id'])) {
                throw new \Exception('bank_transaction_id is required');
            }
            
            // Get the bank transaction
            $bankTransactionId = $this->decodeHashedId($arguments['bank_transaction_id']);
            if (!$bankTransactionId) {
                throw new \Exception("Invalid bank transaction ID: {$arguments['bank_transaction_id']}");
            }
            
            $bankTransaction = \App\Models\BankTransaction::findOrFail($bankTransactionId);
            $this->debugLog("Found bank transaction", ['id' => $bankTransaction->id, 'description' => $bankTransaction->description]);
            
            // Check if already converted
            if ($bankTransaction->status_id == \App\Models\BankTransaction::STATUS_CONVERTED) {
                throw new \Exception('Bank transaction is already converted');
            }
            
            // Prepare input for MatchBankTransactions job
            $input = [
                'id' => $bankTransaction->id  // Use database ID, not hashed ID
            ];
            
            if (isset($arguments['vendor_id'])) {
                // Decode vendor_id to database ID
                $vendorId = $this->decodeHashedId($arguments['vendor_id']);
                if ($vendorId) {
                    $input['vendor_id'] = $vendorId;
                }
            }
            
            if (isset($arguments['ninja_category_id'])) {
                $input['ninja_category_id'] = $arguments['ninja_category_id'];
            }
            
            // Use the MatchBankTransactions job for proper conversion
            $jobInput = [
                'transactions' => [$input]
            ];
            
            $this->debugLog("Creating MatchBankTransactions job with input", $jobInput);
            
            $matchJob = new \App\Jobs\Bank\MatchBankTransactions(
                $bankTransaction->company_id,
                $bankTransaction->company->db,
                $jobInput
            );
            
            // Execute the job synchronously
            $this->debugLog("Executing MatchBankTransactions job");
            try {
                $matchJob->handle();
                $this->debugLog("MatchBankTransactions job completed successfully");
            } catch (\Exception $jobError) {
                $this->debugLog("MatchBankTransactions job failed", [
                    'error' => $jobError->getMessage(),
                    'trace' => $jobError->getTraceAsString()
                ]);
                throw $jobError;
            }
            
            // Refresh the bank transaction to get updated data
            $bankTransaction->refresh();
            
            // Get the linked expense
            $expense = null;
            if ($bankTransaction->expense_id) {
                // expense_id is stored as a numeric ID in the database
                $expense = \App\Models\Expense::find($bankTransaction->expense_id);
            }
            
            $result = [
                'bank_transaction' => [
                    'id' => $bankTransaction->hashed_id,
                    'status' => $bankTransaction->status_id == \App\Models\BankTransaction::STATUS_CONVERTED ? 'CONVERTED' : 'UNMATCHED',
                    'expense_id' => $bankTransaction->expense_id,
                    'vendor_id' => $bankTransaction->vendor_id
                ]
            ];
            
            if ($expense) {
                $result['expense'] = [
                    'id' => $expense->hashed_id,
                    'number' => $expense->number,
                    'amount' => $expense->amount,
                    'transaction_id' => $expense->transaction_id
                ];
            }
            
            $this->debugLog("Bank transaction conversion completed", $result);
            
            return new CallToolResult(
                content: [new TextContent(
                    text: json_encode($result, JSON_PRETTY_PRINT)
                )],
                isError: false
            );
            
        } catch (\Exception $e) {
            $this->debugLog("Bank transaction conversion failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new CallToolResult(
                content: [new TextContent(
                    text: 'Error: ' . $e->getMessage()
                )],
                isError: true
            );
        }
    }
}