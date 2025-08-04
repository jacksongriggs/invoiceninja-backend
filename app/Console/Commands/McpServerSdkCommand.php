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

    public function handle()
    {
        // Set up logging based on mode
        $logger = new Logger('mcp-server-sdk');
        
        if ($this->option('stdio')) {
            // Log to stderr for stdio mode
            $handler = new StreamHandler('php://stderr', Logger::INFO);
        } else {
            // Log to file for HTTP mode
            $handler = new StreamHandler(storage_path('logs/mcp-server-sdk.log'), Logger::INFO);
        }
        $logger->pushHandler($handler);

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
                    'description' => "{$singular} data to create"
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
        }

        return $tools;
    }

    private function handleToolCall(string $toolName, array $arguments): CallToolResult
    {
        try {
            // Parse tool name to determine action and entity
            $parts = explode('_', $toolName);
            $action = $parts[0]; // list, get, create, update, delete
            $entity = implode('', array_map('ucfirst', array_slice($parts, 1)));
            
            // Handle plural forms for list operations
            if ($action === 'list' && substr($entity, -1) === 's') {
                $entity = substr($entity, 0, -1);
            }

            $result = $this->callInternalApi($action, $entity, $arguments);

            return new CallToolResult(
                content: [new TextContent(
                    text: json_encode($result, JSON_PRETTY_PRINT)
                )]
            );

        } catch (\Exception $e) {
            return new CallToolResult(
                content: [new TextContent(
                    text: 'Error: ' . $e->getMessage()
                )],
                isError: true
            );
        }
    }

    private function callInternalApi(string $action, string $entity, array $arguments): array
    {
        // Use Laravel models directly
        $modelClass = "\\App\\Models\\{$entity}";
        
        if (!class_exists($modelClass)) {
            throw new \Exception("Model not found: {$modelClass}");
        }
        
        switch ($action) {
            case 'list':
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
                $data = $arguments['data'] ?? $arguments;
                $item = $modelClass::create($data);
                
                // Use transformer if available
                $transformerClass = "\\App\\Transformers\\{$entity}Transformer";
                if (class_exists($transformerClass)) {
                    $transformer = new $transformerClass();
                    $transformedData = $transformer->transform($item);
                } else {
                    $transformedData = $item->toArray();
                }
                
                return ['data' => $transformedData];
                
            default:
                throw new \Exception("Unsupported action: {$action}");
        }
    }
}