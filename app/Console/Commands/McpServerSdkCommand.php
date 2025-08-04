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
        
        // Also write to stderr for immediate visibility
        fwrite(STDERR, "[MCP DEBUG] " . $message . (!empty($context) ? " " . json_encode($context) : "") . "\n");
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

        return $tools;
    }

    private function handleToolCall(string $toolName, array $arguments): CallToolResult
    {
        $this->debugLog("handleToolCall called", ['tool' => $toolName, 'arguments' => $arguments]);
        
        try {
            // Set a timeout for operations
            set_time_limit(30); // 30 second timeout
            
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
                $this->debugLog("Executing list operation", ['entity' => $entity]);
                $perPage = $arguments['per_page'] ?? 20;
                $page = $arguments['page'] ?? 1;
                
                // Get paginated results
                $results = $modelClass::paginate($perPage, ['*'], 'page', $page);
                
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
                
            case 'get':
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
                
            case 'create':
                $this->debugLog("Executing create operation", ['entity' => $entity, 'data' => $arguments]);
                
                $data = $arguments['data'] ?? $arguments;
                
                // Get the first company and user for context
                $company = \App\Models\Company::first();
                $user = \App\Models\User::first();
                
                if (!$company || !$user) {
                    throw new \Exception('No company or user found in the system');
                }
                
                $this->debugLog("Found company and user", ['company_id' => $company->id, 'user_id' => $user->id]);
                
                // Decode any hashed IDs in the data (fields ending with _id)
                $data = $this->decodeHashedIds($data);
                
                $this->debugLog("Data after ID decoding", ['data' => $data]);
                
                // Check for factory and repository classes
                $factoryClass = "\\App\\Factory\\{$entity}Factory";
                $repositoryClass = "\\App\\Repositories\\{$entity}Repository";
                
                $this->debugLog("Checking classes", [
                    'factory' => $factoryClass,
                    'repository' => $repositoryClass,
                    'factory_exists' => class_exists($factoryClass),
                    'repository_exists' => class_exists($repositoryClass)
                ]);
                
                $item = null;
                
                // Check if we should use factory pattern (some entities like Project don't have repository save method)
                if (class_exists($factoryClass) && method_exists($factoryClass, 'create')) {
                    try {
                        $this->debugLog("Using factory pattern for {$entity}");
                        
                        // Create using factory
                        $item = $factoryClass::create($company->id, $user->id);
                        $this->debugLog("Factory created item", ['item_id' => $item->id ?? 'no_id']);
                        
                        // Fill with data
                        $item->fill($data);
                        $this->debugLog("Item filled with data");
                        
                        // Save the item
                        $item->saveQuietly();
                        $this->debugLog("Item saved", ['item_id' => $item->id ?? 'no_id']);
                        
                        // Special handling for specific entity types
                        switch ($entity) {
                            case 'Project':
                                // Projects need a number if not provided
                                if (empty($item->number)) {
                                    $item->number = $this->getNextProjectNumber($item);
                                    $item->saveQuietly();
                                    $this->debugLog("Project number generated", ['number' => $item->number]);
                                }
                                break;
                                
                            case 'Client':
                            case 'Invoice':
                            case 'Quote':
                            case 'Payment':
                                // These entities might have repository save methods
                                if (class_exists($repositoryClass) && method_exists($repositoryClass, 'save')) {
                                    $this->debugLog("Using repository save for {$entity}");
                                    $repository = new $repositoryClass();
                                    $item = $repository->save($data, $item);
                                    $this->debugLog("Repository save completed");
                                }
                                break;
                        }
                        
                        // Apply service layer if available (for entities that support it)
                        if ($item && method_exists($item, 'service')) {
                            $this->debugLog("Checking service layer for {$entity}");
                            
                            try {
                                $service = $item->service();
                                
                                // Only call fillDefaults if it exists
                                if (method_exists($service, 'fillDefaults')) {
                                    $this->debugLog("Calling fillDefaults");
                                    $service->fillDefaults();
                                }
                                
                                // Save through service if available
                                if (method_exists($service, 'save')) {
                                    $this->debugLog("Calling service save");
                                    $item = $service->save();
                                    $this->debugLog("Service save completed");
                                }
                            } catch (\Exception $e) {
                                $this->debugLog("Service layer processing failed, continuing with basic save", ['error' => $e->getMessage()]);
                                // Continue with the item as saved
                            }
                        }
                        
                        // Trigger creation event
                        event('eloquent.created: App\\Models\\' . $entity, $item);
                        
                        $this->debugLog("Entity creation completed successfully", ['entity' => $entity, 'id' => $item->id ?? 'no_id']);
                        
                    } catch (\Exception $e) {
                        $this->debugLog("Factory creation failed", [
                            'entity' => $entity,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        throw $e;
                    }
                } else {
                    $this->debugLog("Using simple model creation fallback");
                    // Fallback for entities without factories/repositories
                    $data['company_id'] = $company->id;
                    $data['user_id'] = $user->id;
                    
                    try {
                        $item = $modelClass::create($data);
                        $this->debugLog("Simple model creation completed", ['item_id' => $item->id ?? 'no_id']);
                    } catch (\Exception $e) {
                        $this->debugLog("Simple model creation failed", ['error' => $e->getMessage()]);
                        throw $e;
                    }
                }
                
                if (!$item) {
                    throw new \Exception("Failed to create {$entity}");
                }
                
                // Use transformer if available
                $transformerClass = "\\App\\Transformers\\{$entity}Transformer";
                if (class_exists($transformerClass)) {
                    $transformer = new $transformerClass();
                    $transformedData = $transformer->transform($item);
                } else {
                    $transformedData = $item->toArray();
                }
                
                $this->debugLog("Create operation completed", ['entity' => $entity]);
                return ['data' => $transformedData];
                
            case 'update':
                $this->debugLog("Executing update operation", ['entity' => $entity]);
                if (!isset($arguments['id'])) {
                    throw new \Exception('ID is required for update operation');
                }
                
                if (!isset($arguments['data'])) {
                    throw new \Exception('Data is required for update operation');
                }
                
                // Decode the hashed ID to get the actual database ID
                $model = new $modelClass();
                $decodedId = $model->decodePrimaryKey($arguments['id']);
                
                if (!$decodedId) {
                    throw new \Exception("Invalid ID: {$arguments['id']}");
                }
                
                $item = $modelClass::findOrFail($decodedId);
                
                // Decode any hashed IDs in the update data
                $updateData = $this->decodeHashedIds($arguments['data']);
                $item->update($updateData);
                
                // Use transformer if available
                $transformerClass = "\\App\\Transformers\\{$entity}Transformer";
                if (class_exists($transformerClass)) {
                    $transformer = new $transformerClass();
                    $transformedData = $transformer->transform($item);
                } else {
                    $transformedData = $item->toArray();
                }
                
                return ['data' => $transformedData];
                
            case 'delete':
                $this->debugLog("Executing delete operation", ['entity' => $entity]);
                if (!isset($arguments['id'])) {
                    throw new \Exception('ID is required for delete operation');
                }
                
                // Decode the hashed ID to get the actual database ID
                $model = new $modelClass();
                $decodedId = $model->decodePrimaryKey($arguments['id']);
                
                if (!$decodedId) {
                    throw new \Exception("Invalid ID: {$arguments['id']}");
                }
                
                $item = $modelClass::findOrFail($decodedId);
                
                // Soft delete if supported, otherwise hard delete
                if (method_exists($item, 'delete')) {
                    $item->delete();
                }
                
                return ['data' => ['message' => "{$entity} deleted successfully", 'id' => $arguments['id']]];
                
            default:
                throw new \Exception("Unsupported action: {$action}");
        }
    }

    /**
     * Get the next project number
     * 
     * @param \App\Models\Project $project
     * @return string
     */
    private function getNextProjectNumber($project): string
    {
        $counter = $project->company->settings->project_number_counter ?? 1;
        $pattern = $project->company->settings->project_number_pattern ?? '{$counter}';
        
        // Simple counter replacement (can be extended for more complex patterns)
        $number = str_replace('{$counter}', str_pad($counter, 4, '0', STR_PAD_LEFT), $pattern);
        
        // Update the counter in company settings
        $settings = $project->company->settings;
        $settings->project_number_counter = $counter + 1;
        $project->company->settings = $settings;
        $project->company->save();
        
        return $number;
    }
    
    /**
     * Decode any hashed IDs in the data array (fields ending with _id)
     * 
     * @param array $data
     * @return array
     */
    private function decodeHashedIds(array $data): array
    {
        foreach ($data as $key => $value) {
            // Check if field ends with _id and value is a string (hashed ID)
            if (str_ends_with($key, '_id') && is_string($value) && !empty($value)) {
                try {
                    // Try to decode the hashed ID using a concrete model
                    $tempModel = new \App\Models\Client();
                    $decodedId = $tempModel->decodePrimaryKey($value);
                    
                    if ($decodedId) {
                        $this->debugLog("Decoded hashed ID", ['field' => $key, 'original' => $value, 'decoded' => $decodedId]);
                        $data[$key] = $decodedId;
                    }
                    // If decoding fails, leave the original value (might be a numeric ID already)
                } catch (\Exception $e) {
                    $this->debugLog("Failed to decode hashed ID", ['field' => $key, 'value' => $value, 'error' => $e->getMessage()]);
                    // If decoding fails, leave the original value
                    continue;
                }
            }
        }
        
        return $data;
    }
}