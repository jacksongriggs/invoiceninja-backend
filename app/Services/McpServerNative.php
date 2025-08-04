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
    
    public function __construct()
    {
        // Initialize logger
        $this->logger = new Logger('mcp-server-native');
        $this->logger->pushHandler(new StreamHandler('php://stdout', $_ENV['LOG_LEVEL'] ?? Logger::INFO));
        
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
            
            // Bootstrap Laravel application
            $this->app = require_once $invoiceNinjaPath . '/bootstrap/app.php';
            
            // Create HTTP kernel
            $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
            
            // Handle a fake request to initialize the app
            $request = \Illuminate\Http\Request::create('/', 'GET');
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            
            // Set the API token for authentication
            if (isset($_ENV['INVOICE_NINJA_API_TOKEN'])) {
                // Authenticate as the API user
                $token = \App\Models\CompanyToken::where('token', $_ENV['INVOICE_NINJA_API_TOKEN'])->first();
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
        // Read JSON-RPC request from body
        $input = file_get_contents('php://input');
        
        // Handle both JSON-RPC and simple GET requests for compatibility
        if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'GET') {
            // Simple GET request for health check
            $this->sendJson([
                'name' => 'invoice-ninja-mcp-native',
                'version' => '1.0.0',
                'description' => 'Native MCP server using Invoice Ninja transformers directly',
                'mode' => 'native',
                'protocol' => 'mcp-json-rpc'
            ]);
            return;
        }
        
        // Parse JSON-RPC request
        $request = json_decode($input, true);
        
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
            
            $controllerClass = "\\App\\Http\\Controllers\\{$entity}Controller";
            
            if (!class_exists($controllerClass)) {
                throw new \Exception("Controller not found: $controllerClass");
            }
            
            // Map action to controller method
            $methodMap = [
                'list' => 'index',
                'get' => 'show',
                'create' => 'store',
                'update' => 'update',
                'delete' => 'destroy'
            ];
            
            $method = $methodMap[$action] ?? null;
            
            if (!$method) {
                throw new \Exception("Unknown action: $action");
            }
            
            // Create controller instance
            $controller = $this->app->make($controllerClass);
            
            // Create request with arguments
            $request = new \Illuminate\Http\Request();
            
            // For create/update operations, use the data field
            if (in_array($action, ['create', 'update']) && isset($arguments['data'])) {
                $request->merge($arguments['data']);
            } else {
                $request->merge($arguments);
            }
            
            $request->headers->set('X-API-TOKEN', $_ENV['INVOICE_NINJA_API_TOKEN']);
            
            // For show/update/delete, we need to resolve the model
            if (in_array($action, ['get', 'update', 'delete']) && isset($arguments['id'])) {
                $modelClass = "\\App\\Models\\{$entity}";
                $model = $modelClass::findOrFail($arguments['id']);
                $response = $controller->$method($request, $model);
            } else {
                $response = $controller->$method($request);
            }
            
            // Get the response content
            if ($response instanceof \Illuminate\Http\JsonResponse) {
                $result = json_decode($response->getContent(), true);
            } else {
                $result = $response;
            }
            
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