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
                            {--host=0.0.0.0 : The host to bind to}
                            {--port=8080 : The port to bind to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the MCP (Model Context Protocol) server';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $host = $this->option('host');
        $port = $this->option('port');

        $this->info("Starting MCP server on {$host}:{$port}");
        
        // Create temp file for the server
        $tempFile = tempnam(sys_get_temp_dir(), 'mcp-server-');
        file_put_contents($tempFile, '<?php
require_once \'' . base_path('vendor/autoload.php') . '\';
require_once \'' . app_path('Services/McpServerNative.php') . '\';

use App\Services\McpServerNative;

$server = new McpServerNative();
$server->run();
');

        // Start the PHP built-in server
        $command = sprintf(
            'php -S %s:%s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($tempFile)
        );

        $this->info("Running: {$command}");
        
        // This will run until interrupted
        passthru($command);
        
        // Clean up
        @unlink($tempFile);
        
        return 0;
    }
}