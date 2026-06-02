<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class StartSignalingServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'signaling:start {--force : Force start even if process is already running}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatic start/check for the Node.js signaling server';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking signaling server status...');

        // 1. Check if already running (cross-platform check)
        if (!$this->option('force')) {
            if ($this->isAlreadyRunning()) {
                $this->warn('Signaling server is already running.');
                return;
            }
        }

        // 2. Locate node and server script
        $nodePath = $this->findNodePath();
        if (!$nodePath) {
            $this->error('Node.js not found in PATH.');
            return;
        }

        $scriptPath = base_path('signaling/server.js');
        if (!file_exists($scriptPath)) {
            $this->error("Server script not found at: {$scriptPath}");
            return;
        }

        // 3. Start process in background
        $this->info("Starting signaling server from script: {$scriptPath}");
        
        $process = new Process([$nodePath, $scriptPath]);
        $process->setTimeout(null);
        $process->disableOutput(); // Run cleanly
        
        // Use background execution based on OS
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            $this->startBackgroundWindows($nodePath, $scriptPath);
        } else {
            $this->startBackgroundLinux($nodePath, $scriptPath);
        }

        $this->info('Signaling server started in background.');
    }

    private function isAlreadyRunning(): bool
    {
        // Simple check: Try to connect to port 3000
        $connection = @fsockopen('127.0.0.1', 3000, $errno, $errstr, 0.5);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    private function findNodePath(): ?string
    {
        // Check for common node locations or use 'node' if in path
        return 'node';
    }

    private function startBackgroundWindows($node, $script)
    {
        pclose(popen("start /B {$node} \"{$script}\" > NUL 2>&1", "r"));
    }

    private function startBackgroundLinux($node, $script)
    {
        exec("nohup {$node} \"{$script}\" > /dev/null 2>&1 &");
    }
}
