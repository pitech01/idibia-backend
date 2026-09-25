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
        $port = (int) env('SIGNALING_PORT', 3000);
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    private function findNodePath(): ?string
    {
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            return 'node';
        }

        $candidates = [
            '/usr/local/bin/node',
            '/usr/bin/node',
            '/bin/node',
            '/opt/cpanel/ea-nodejs20/bin/node',
            '/opt/cpanel/ea-nodejs18/bin/node',
            '/opt/cpanel/ea-nodejs16/bin/node',
            '/opt/alt/alt-nodejs20/root/usr/bin/node',
            '/opt/alt/alt-nodejs18/root/usr/bin/node',
            '/home/ellisili/.nvm/versions/node/v20.*/bin/node',
            '/home/ellisili/.nvm/versions/node/v18.*/bin/node',
            '/home/ellisili/.nvm/versions/node/v16.*/bin/node',
            '/root/.nvm/versions/node/v20.*/bin/node',
            '/root/.nvm/versions/node/v18.*/bin/node'
        ];

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '*')) {
                $globbed = glob($candidate);
                if (!empty($globbed) && is_executable($globbed[0])) {
                    return $globbed[0];
                }
            } elseif (file_exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        if (function_exists('shell_exec')) {
            $which = trim(@shell_exec('which node 2>/dev/null') ?: '');
            if ($which && file_exists($which) && is_executable($which)) {
                return $which;
            }
        }

        return 'node';
    }

    private function startBackgroundWindows($node, $script)
    {
        if (function_exists('popen') && function_exists('pclose')) {
            try {
                $handle = @popen("start /B {$node} \"{$script}\" > NUL 2>&1", "r");
                if ($handle) {
                    pclose($handle);
                }
            } catch (\Throwable $e) {
                $this->warn('Could not spawn background process on Windows: ' . $e->getMessage());
            }
        }
    }

    private function startBackgroundLinux($node, $script)
    {
        $logFile = storage_path('logs/signaling.log');
        $cmd = "nohup {$node} \"{$script}\" > \"{$logFile}\" 2>&1 &";
        
        if (function_exists('exec')) {
            try {
                @\exec($cmd);
            } catch (\Throwable $e) {
                $this->warn('Could not spawn background process on Linux: ' . $e->getMessage());
            }
        } elseif (function_exists('shell_exec')) {
            try {
                @\shell_exec($cmd);
            } catch (\Throwable $e) {
                $this->warn('Could not spawn background process on Linux: ' . $e->getMessage());
            }
        }
    }
}
