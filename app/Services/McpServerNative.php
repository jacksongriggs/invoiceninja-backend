<?php

namespace App\Services;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Native MCP Server that implements JSON-RPC 2.0 protocol for Claude
 * This eliminates ALL duplication by using Invoice Ninja's transformers directly
 */
class McpServerNative
{
    private Logger $logger;
    private $app; // Laravel application instance
    private string $mode; // 'stdio' or 'http'
    
    public function __construct(string $mode = 'stdio')
    {
        $this->mode = $mode;
        
        // Initialize logger - use stderr for stdio mode to avoid interfering with JSON-RPC output
        $this->logger = new Logger('mcp-server-native');
        if ($mode === 'stdio') {
            $this->logger->pushHandler(new StreamHandler('php://stderr', $_ENV['LOG_LEVEL'] ?? Logger::INFO));
        } else {
            // For HTTP mode, log to a file to avoid broken pipe issues
            $this->logger->pushHandler(new StreamHandler('/var/www/html/storage/logs/mcp-server.log', $_ENV['LOG_LEVEL'] ?? Logger::INFO));
        }
        
        // Bootstrap Invoice Ninja's Laravel app directly
        $this->bootstrapInvoiceNinja();
    }
    
    private function bootstrapInvoiceNinja(): void
    {
        $invoiceNinjaPath = '/var/www/html'; // Path in Invoice Ninja container
        
        if (!file_exists($invoiceNinjaPath . '/vendor/autoload.php')) {
            $this->logger->warning("Invoice Ninja not found, falling back to SDK mode");
            return;
        }
        
        try {
            // Load Invoice Ninja's autoloader
            require_once $invoiceNinjaPath . '/vendor/autoload.php';
            
            // Bootstrap Laravel application (Laravel 11 returns true, not app instance)
            require_once $invoiceNinjaPath . '/bootstrap/app.php';
            $this->app = app();
            
            // Create HTTP kernel
            $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
            
            // Handle a fake request to initialize the app
            $request = \Illuminate\Http\Request::create('/', 'GET');
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            
            // Set the API token for authentication
            $apiToken = getenv('INVOICE_NINJA_API_TOKEN') ?: $_ENV['INVOICE_NINJA_API_TOKEN'] ?? null;
            if ($apiToken) {
                // Authenticate as the API user
                $token = \App\Models\CompanyToken::where('token', $apiToken)->first();
                if ($token) {
                    auth()->guard('api')->setUser($token->user);
                }
            }
            
            $this->logger->info("Invoice Ninja bootstrapped successfully");
        } catch (\Exception $e) {
            $this->logger->error("Failed to bootstrap Invoice Ninja", ['error' => $e->getMessage()]);
            $this->app = null;
        }
    }
    
    public function run(): void
    {
        if ($this->mode === 'stdio') {
            // Stdio mode - read from stdin in a loop
            while (!feof(STDIN)) {
                $line = fgets(STDIN);
                if ($line === false) break;
                
                $line = trim($line);
                if (empty($line)) continue;
                
                $request = json_decode($line, true);
                if ($request) {
                    $this->handleRequest($request);
                }
            }
        } else {
            // HTTP mode - single request/response
            $this->handleHttpRequest();
        }
    }
    
    public function handleHttpRequest(): void
    {
        // Read JSON-RPC request from body
        $input = file_get_contents('php://input');
        
        // Handle both JSON-RPC and simple GET requests for compatibility
        if (empty($input) && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
            // Simple GET request for health check
            $this->sendJson([
                'name' => 'invoice-ninja-mcp-native',
                'version' => '1.0.0',
                'description' => 'Native MCP server using Invoice Ninja transformers directly',
                'mode' => $this->mode,
                'protocol' => 'mcp-json-rpc'
            ]);
            return;
        }
        
        // Parse JSON-RPC request
        $request = json_decode($input, true);
        $this->handleRequest($request);
    }
    
    private function handleRequest($request): void
    {
        if (!$request || !isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            $this->sendJsonRpcError(null, -32700, 'Parse error');
            return;
        }
        
        if (!isset($request['method'])) {
            $this->sendJsonRpcError($request['id'] ?? null, -32600, 'Invalid Request');
            return;
        }
        
        $this->logger->info("Processing JSON-RPC request", ['method' => $request['method']]);
        
        // Route to appropriate method
        switch ($request['method']) {
            case 'initialize':
                $this->handleInitialize($request);
                break;
                
            case 'tools/list':
                $this->handleToolsList($request);
                break;
                
            case 'tools/call':
                $this->handleToolsCall($request);
                break;
                
            default:
                $this->sendJsonRpcError($request['id'] ?? null, -32601, 'Method not found');
                break;
        }
    }
    
    private function handleInitialize(array $request): void
    {
        // Respond with server capabilities
        $this->sendJsonRpcResult($request['id'] ?? null, [
            'protocolVersion' => '0.1.0',
            'capabilities' => [
                'tools' => [
                    'listChanged' => false
                ]
            ],
            'serverInfo' => [
                'name' => 'invoice-ninja-mcp',
                'version' => '1.0.0',
                'description' => 'Native Invoice Ninja MCP server'
            ]
        ]);
    }
    
    private function handleToolsList(array $request): void
    {
        // Check if we have Invoice Ninja loaded
        if (!$this->app) {
            $this->sendJsonRpcError($request['id'] ?? null, -32603, 'Invoice Ninja not available');
            return;
        }
        
        $tools = [];
        
        // Get all transformer classes
        $transformerPath = '/var/www/html/app/Transformers';
        $transformerFiles = glob($transformerPath . '/*Transformer.php');
        
        foreach ($transformerFiles as $file) {
            $className = basename($file, '.php');
            
            // Skip base classes and helpers
            if (in_array($className, ['EntityTransformer', 'ArraySerializer'])) {
                continue;
            }
            
            $entityName = str_replace('Transformer', '', $className);
            $entityNameLower = strtolower($entityName);
            $entityNamePlural = $this->pluralize($entityNameLower);
            
            // List operation
            $tools[] = [
                'name' => "list_{$entityNamePlural}",
                'description' => "List all {$entityNamePlural} from Invoice Ninja",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'page' => [
                            'type' => 'integer',
                            'description' => 'Page number for pagination'
                        ],
                        'per_page' => [
                            'type' => 'integer',
                            'description' => 'Items per page'
                        ]
                    ]
                ]
            ];
            
            // Get operation
            $tools[] = [
                'name' => "get_{$entityNameLower}",
                'description' => "Get a specific {$entityNameLower} by ID",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'description' => "The {$entityNameLower} ID"
                        ]
                    ],
                    'required' => ['id']
                ]
            ];
            
            // Create operation
            $tools[] = [
                'name' => "create_{$entityNameLower}",
                'description' => "Create a new {$entityNameLower}",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'description' => "{$entityName} data to create"
                        ]
                    ],
                    'required' => ['data']
                ]
            ];
        }
        
        $this->sendJsonRpcResult($request['id'] ?? null, [
            'tools' => $tools
        ]);
    }
    
    private function handleToolsCall(array $request): void
    {
        // Check if we have Invoice Ninja loaded
        if (!$this->app) {
            $this->sendJsonRpcError($request['id'] ?? null, -32603, 'Invoice Ninja not available');
            return;
        }
        
        $params = $request['params'] ?? [];
        
        if (!isset($params['name'])) {
            $this->sendJsonRpcError($request['id'] ?? null, -32602, 'Invalid params: missing tool name');
            return;
        }
        
        $toolName = $params['name'];
        $arguments = $params['arguments'] ?? [];
        
        try {
            // Parse tool name to determine action and entity
            $parts = explode('_', $toolName);
            $action = $parts[0]; // list, get, create, update, delete
            $entity = implode('', array_map('ucfirst', array_slice($parts, 1)));
            
            // Handle plural forms for list operations
            if ($action === 'list' && substr($entity, -1) === 's') {
                $entity = substr($entity, 0, -1);
            }
            
            // Use internal API calls instead of controllers for consistency
            $this->logger->info("Calling internal API", ['action' => $action, 'entity' => $entity, 'arguments' => $arguments]);
            $result = $this->callInternalApi($action, $entity, $arguments);
            $this->logger->info("API call successful", ['result_keys' => array_keys($result)]);
            
            // Format result for MCP
            $this->sendJsonRpcResult($request['id'] ?? null, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_PRETTY_PRINT)
                    ]
                ],
                'isError' => false
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Tool execution failed', [
                'tool' => $toolName,
                'error' => $e->getMessage()
            ]);
            
            // Return error as tool result (not JSON-RPC error)
            $this->sendJsonRpcResult($request['id'] ?? null, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Error: ' . $e->getMessage()
                    ]
                ],
                'isError' => true
            ]);
        }
    }
    
    private function callInternalApi(string $action, string $entity, array $arguments): array
    {
        // Use Laravel models directly instead of HTTP API calls
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
                
                $item = $modelClass::findOrFail($arguments['id']);
                
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
                
            case 'update':
                if (!isset($arguments['id'])) {
                    throw new \Exception('ID is required for update operation');
                }
                
                $item = $modelClass::findOrFail($arguments['id']);
                $updateData = $arguments['data'] ?? array_diff_key($arguments, ['id' => null]);
                $item->update($updateData);
                
                // Use transformer if available
                $transformerClass = "\\App\\Transformers\\{$entity}Transformer";
                if (class_exists($transformerClass)) {
                    $transformer = new $transformerClass();
                    $transformedData = $transformer->transform($item->fresh());
                } else {
                    $transformedData = $item->fresh()->toArray();
                }
                
                return ['data' => $transformedData];
                
            case 'delete':
                if (!isset($arguments['id'])) {
                    throw new \Exception('ID is required for delete operation');
                }
                
                $item = $modelClass::findOrFail($arguments['id']);
                $item->delete();
                
                return ['message' => "{$entity} deleted successfully"];
                
            default:
                throw new \Exception("Unsupported action: {$action}");
        }
    }
    
    private function pluralize(string $word): string
    {
        // Simple pluralization rules
        $irregular = [
            'activity' => 'activities',
            'company' => 'companies',
            'currency' => 'currencies',
            'country' => 'countries',
            'gateway' => 'gateways',
            'industry' => 'industries',
            'category' => 'categories'
        ];
        
        if (isset($irregular[$word])) {
            return $irregular[$word];
        }
        
        if (substr($word, -1) === 'y') {
            return substr($word, 0, -1) . 'ies';
        }
        
        if (substr($word, -1) === 's' || substr($word, -2) === 'ss') {
            return $word . 'es';
        }
        
        return $word . 's';
    }
    
    private function sendJsonRpcResult($id, $result): void
    {
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id
        ], JSON_PRETTY_PRINT);
    }
    
    private function sendJsonRpcError($id, int $code, string $message): void
    {
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message
            ],
            'id' => $id
        ], JSON_PRETTY_PRINT);
    }
    
    private function sendJson(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
    }
}