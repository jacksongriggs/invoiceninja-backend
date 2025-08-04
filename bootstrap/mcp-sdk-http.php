<?php
/**
 * MCP SDK HTTP Server Entry Point
 * This file is served by PHP's built-in server for HTTP mode
 */

// Only handle POST requests to the root path
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/app.php';

use Mcp\Server\Server;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\Transport\Http\StandardPhpAdapter;
use Mcp\Server\Transport\Http\FileSessionStore;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Set up logging
$logger = new Logger('mcp-server-sdk-http');
$handler = new StreamHandler(__DIR__ . '/../storage/logs/mcp-server-sdk-http.log', Logger::INFO);
$logger->pushHandler($handler);

// Create server instance
$server = new Server('invoice-ninja-mcp-sdk', $logger);

// Load the command class to reuse its logic
$command = new \App\Console\Commands\McpServerSdkCommand();

// Use reflection to access private methods
$generateToolsMethod = new ReflectionMethod($command, 'generateTools');
$generateToolsMethod->setAccessible(true);

$handleToolCallMethod = new ReflectionMethod($command, 'handleToolCall');
$handleToolCallMethod->setAccessible(true);

// Register handlers
$server->registerHandler('tools/list', function($params) use ($command, $generateToolsMethod) {
    $tools = $generateToolsMethod->invoke($command);
    return new \Mcp\Types\ListToolsResult($tools);
});

$server->registerHandler('tools/call', function($params) use ($command, $handleToolCallMethod) {
    return $handleToolCallMethod->invoke($command, $params->name, $params->arguments ?? []);
});

// Configure HTTP options
$httpOptions = [
    'session_timeout' => 1800,
    'max_queue_size' => 500,
    'enable_sse' => false,
    'shared_hosting' => false,
    'server_header' => 'Invoice-Ninja-MCP-SDK/1.0',
];

try {
    // Create session store
    $sessionDir = __DIR__ . '/../storage/mcp_sessions';
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0755, true);
    }
    $fileStore = new FileSessionStore($sessionDir);
    
    // Create runner (4th param is logger which can be null, 5th is session store)
    $runner = new HttpServerRunner($server, $server->createInitializationOptions(), $httpOptions, null, $fileStore);
    
    // Create adapter and handle request
    $adapter = new StandardPhpAdapter($runner);
    $adapter->handle();
} catch (\Exception $e) {
    $logger->error('MCP Server error', ['exception' => $e]);
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
}