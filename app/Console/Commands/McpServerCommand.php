<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Console\Commands;

use App\Services\McpServerNative;
use Illuminate\Console\Command;

class McpServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ninja:mcp-server 
                            {--stdio : Run in stdio mode for Claude Desktop}
                            {--http : Run in HTTP mode for network access}
                            {--host=0.0.0.0 : The host to bind to (HTTP mode only)}
                            {--port=8080 : The port to bind to (HTTP mode only)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the MCP (Model Context Protocol) server in stdio or HTTP mode';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $isStdio = $this->option('stdio');
        $isHttp = $this->option('http');
        
        // Default to HTTP if neither specified
        if (!$isStdio && !$isHttp) {
            $isHttp = true;
        }
        
        if ($isStdio && $isHttp) {
            $this->error('Cannot run in both stdio and HTTP mode simultaneously. Choose one.');
            return 1;
        }
        
        require_once base_path('vendor/autoload.php');
        require_once app_path('Services/McpServerNative.php');
        
        if ($isStdio) {
            $this->info('Starting MCP server in stdio mode (for Claude Desktop)');
            $this->info('Reading from stdin, writing to stdout...');
            
            $server = new McpServerNative('stdio');
            $server->run();
        } else {
            $host = $this->option('host');
            $port = $this->option('port');
            
            $this->info("Starting MCP server in HTTP mode on {$host}:{$port}");
            
            // Create a simple HTTP server that wraps the MCP server
            $server = new McpServerNative('http');
            
            // Use PHP's built-in server with the pre-created router file
            $router = base_path('bootstrap/mcp-http-server.php');
            
            $command = sprintf(
                'php -S %s:%s %s',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($router)
            );
            
            $this->info("Running: {$command}");
            passthru($command);
        }
        
        return 0;
    }
}