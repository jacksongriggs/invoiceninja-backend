<?php
/**
 * HTTP Router for MCP Server
 * This file handles HTTP requests and forwards them to the MCP server
 */

// Prevent any output before we're ready
ob_start();

try {
    require_once __DIR__ . "/../vendor/autoload.php";
    require_once __DIR__ . "/app.php";
    require_once __DIR__ . "/../app/Services/McpServerNative.php";
    
    // Clean any output that might have been generated
    ob_end_clean();
    
    // Create MCP server instance
    $server = new \App\Services\McpServerNative("http");
    
    // Handle the request
    $server->handleHttpRequest();
    
} catch (Exception $e) {
    ob_end_clean();
    header("Content-Type: application/json");
    http_response_code(500);
    echo json_encode([
        "jsonrpc" => "2.0",
        "error" => [
            "code" => -32603,
            "message" => "Internal error",
            "data" => $e->getMessage()
        ],
        "id" => null
    ]);
}