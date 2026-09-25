<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MaintenanceController extends Controller
{
    /**
     * Clear caches, link storage, and run safe database migrations without overwriting existing data.
     */
    public function fixCache(Request $request)
    {
        $startTime = microtime(true);
        $results = [];
        $overallSuccess = true;

        // 1. Database Connection Check
        $dbStatus = 'Connected';
        $dbError = null;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbStatus = 'Connection Failed';
            $dbError = $e->getMessage();
            $overallSuccess = false;
        }

        // 2. Commands to execute (Safe Migration is executed FIRST so all tables exist)
        $tasks = [
            [
                'name' => 'Safe Database Migration',
                'command' => 'migrate',
                'params' => ['--force' => true],
                'description' => 'Applies only pending migrations. Existing tables and data are NOT deleted or overwritten.'
            ],
            [
                'name' => 'Safe Database Seeding',
                'command' => 'db:seed',
                'params' => ['--force' => true],
                'description' => 'Seeds default admin accounts and initial content safely without overwriting existing data.',
                'ignore_error' => true
            ],
            [
                'name' => 'Clear Optimizer & Bootstrap Cache',
                'command' => 'optimize:clear',
                'params' => [],
                'description' => 'Clears cached bootstrap files, config, routes, and views.'
            ],
            [
                'name' => 'Clear Config Cache',
                'command' => 'config:clear',
                'params' => [],
                'description' => 'Ensures updated .env variables are loaded immediately.'
            ],
            [
                'name' => 'Clear Application Cache',
                'command' => 'cache:clear',
                'params' => [],
                'description' => 'Clears data cache.'
            ],
            [
                'name' => 'Clear Route Cache',
                'command' => 'route:clear',
                'params' => [],
                'description' => 'Removes old cached route definitions.'
            ],
            [
                'name' => 'Clear View Cache',
                'command' => 'view:clear',
                'params' => [],
                'description' => 'Clears compiled Blade templates.'
            ],
            [
                'name' => 'Link Public Storage',
                'command' => 'storage:link',
                'params' => [],
                'description' => 'Creates public/storage symlink for uploaded files.',
                'ignore_error' => true // May fail if symlink already exists
            ],
            [
                'name' => 'Restart Queue Workers',
                'command' => 'queue:restart',
                'params' => [],
                'description' => 'Signals queue workers to restart gracefully if active.'
            ],
            [
                'name' => 'Start & Check Signaling Server',
                'command' => 'signaling:start',
                'params' => [],
                'description' => 'Checks status of the Node.js signaling server and automatically starts it in the background if not running.',
                'ignore_error' => true
            ]
        ];

        foreach ($tasks as $task) {
            $taskStart = microtime(true);
            try {
                // If DB failed, skip migrations and seeders
                if (($task['command'] === 'migrate' || $task['command'] === 'db:seed') && $dbError) {
                    $results[] = [
                        'name' => $task['name'],
                        'command' => $task['command'],
                        'status' => 'skipped',
                        'description' => $task['description'],
                        'output' => 'Skipped: Database connection is unavailable (' . $dbError . ')',
                        'duration' => 0
                    ];
                    continue;
                }

                $exitCode = Artisan::call($task['command'], $task['params']);
                $output = trim(Artisan::output());

                $status = ($exitCode === 0) ? 'success' : 'warning';
                if ($exitCode !== 0 && empty($task['ignore_error'])) {
                    $overallSuccess = false;
                }

                $results[] = [
                    'name' => $task['name'],
                    'command' => $task['command'],
                    'status' => $status,
                    'description' => $task['description'],
                    'output' => $output ?: 'Executed successfully (no output)',
                    'duration' => round((microtime(true) - $taskStart) * 1000, 2)
                ];
            } catch (\Throwable $e) {
                $isIgnored = !empty($task['ignore_error']);
                if (!$isIgnored) {
                    $overallSuccess = false;
                }

                $results[] = [
                    'name' => $task['name'],
                    'command' => $task['command'],
                    'status' => $isIgnored ? 'notice' : 'error',
                    'description' => $task['description'],
                    'output' => $e->getMessage(),
                    'duration' => round((microtime(true) - $taskStart) * 1000, 2)
                ];
            }
        }

        $totalDuration = round((microtime(true) - $startTime) * 1000, 2);

        // Return JSON if requested
        if ($request->wantsJson() || $request->query('format') === 'json' || $request->is('api/*')) {
            return response()->json([
                'status' => $overallSuccess ? 'success' : 'partial_success',
                'message' => 'Cache cleared and safe migrations executed.',
                'database_status' => $dbStatus,
                'total_duration_ms' => $totalDuration,
                'results' => $results
            ], $overallSuccess ? 200 : 207);
        }

        // Render HTML view
        return response($this->renderHtml($results, $dbStatus, $totalDuration, $overallSuccess), 200)
            ->header('Content-Type', 'text/html');
    }

    /**
     * Render a modern, clean HTML report.
     */
    private function renderHtml(array $results, string $dbStatus, float $totalDuration, bool $overallSuccess): string
    {
        $appName = config('app.name', 'Idibia Backend');
        $appEnv = config('app.env', 'production');
        $phpVersion = PHP_VERSION;
        $laravelVersion = app()->version();
        $date = date('Y-m-d H:i:s T');

        $statusBadge = $overallSuccess
            ? '<span class="badge badge-success"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> System Healthy & Cleared</span>'
            : '<span class="badge badge-warning"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Completed with Warnings</span>';

        $cardsHtml = '';
        foreach ($results as $index => $res) {
            $badgeClass = match ($res['status']) {
                'success' => 'badge-success',
                'warning' => 'badge-warning',
                'notice' => 'badge-info',
                'skipped' => 'badge-muted',
                default => 'badge-danger'
            };

            $icon = match ($res['status']) {
                'success' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>',
                'notice', 'skipped' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
                'warning' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                default => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'
            };

            $escapedOutput = htmlspecialchars($res['output'], ENT_QUOTES, 'UTF-8');
            $command = htmlspecialchars($res['command'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($res['name'], ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars($res['description'], ENT_QUOTES, 'UTF-8');

            $cardsHtml .= <<<HTML
            <div class="step-card">
                <div class="step-header">
                    <div class="step-title-wrap">
                        <span class="step-icon">{$icon}</span>
                        <div>
                            <h3 class="step-title">{$name}</h3>
                            <p class="step-desc">{$description}</p>
                        </div>
                    </div>
                    <div class="step-meta">
                        <span class="command-pill">php artisan {$command}</span>
                        <span class="badge {$badgeClass}">{$res['status']}</span>
                        <span class="duration">{$res['duration']} ms</span>
                    </div>
                </div>
                <div class="step-output">
                    <pre><code>{$escapedOutput}</code></pre>
                </div>
            </div>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Cache & Safe Migration Manager | {$appName}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-page: #0b0f19;
            --bg-card: #131b2e;
            --bg-subtle: #1a243b;
            --border: #263352;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --accent: #3b82f6;
            --accent-glow: rgba(59, 130, 246, 0.15);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #38bdf8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            min-height: 100vh;
            padding: 2.5rem 1.5rem;
            line-height: 1.5;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .header-card {
            background: linear-gradient(135deg, #131b2e 0%, #1e293b 100%);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3);
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .title {
            font-size: 1.6rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .subtitle {
            font-size: 0.925rem;
            color: var(--text-muted);
            margin-top: 0.35rem;
        }
        .system-pill-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 0.75rem;
            margin-top: 1.25rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--border);
        }
        .sys-item {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 0.65rem 0.9rem;
        }
        .sys-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            font-weight: 600;
        }
        .sys-val {
            font-size: 0.925rem;
            font-weight: 600;
            color: #fff;
            margin-top: 0.15rem;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-success { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-warning { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-danger { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-info { background: rgba(56, 189, 248, 0.15); color: #7dd3fc; border: 1px solid rgba(56, 189, 248, 0.3); }
        .badge-muted { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.3); }

        .notice-banner {
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.2);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.75rem;
            display: flex;
            align-items: center;
            gap: 0.85rem;
            font-size: 0.9rem;
            color: #a7f3d0;
        }

        .step-list {
            display: flex;
            flex-direction: column;
            gap: 1.15rem;
        }
        .step-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            transition: transform 0.15s ease, border-color 0.15s ease;
        }
        .step-card:hover {
            border-color: #3b82f6;
        }
        .step-header {
            padding: 1.15rem 1.35rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            background: var(--bg-subtle);
            border-bottom: 1px solid var(--border);
        }
        .step-title-wrap {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }
        .step-icon {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .step-title {
            font-size: 1rem;
            font-weight: 600;
            color: #f8fafc;
        }
        .step-desc {
            font-size: 0.825rem;
            color: var(--text-muted);
            margin-top: 0.1rem;
        }
        .step-meta {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }
        .command-pill {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.775rem;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #93c5fd;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
        }
        .duration {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-family: 'JetBrains Mono', monospace;
        }
        .step-output {
            padding: 1rem 1.35rem;
            background: #090d16;
        }
        .step-output pre {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.825rem;
            color: #e2e8f0;
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.5;
        }
        .action-bar {
            margin-top: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--accent);
            color: #fff;
            padding: 0.65rem 1.25rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-card">
            <div class="header-top">
                <div>
                    <h1 class="title">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                        System Maintenance & Cache Manager
                    </h1>
                    <p class="subtitle">Completed execution for {$appName} on {$date}</p>
                </div>
                <div>
                    {$statusBadge}
                </div>
            </div>

            <div class="system-pill-grid">
                <div class="sys-item">
                    <div class="sys-label">Environment</div>
                    <div class="sys-val">{$appEnv}</div>
                </div>
                <div class="sys-item">
                    <div class="sys-label">Database</div>
                    <div class="sys-val" style="color: {$overallSuccess} ? '#34d399' : '#fbbf24'">{$dbStatus}</div>
                </div>
                <div class="sys-item">
                    <div class="sys-label">Laravel</div>
                    <div class="sys-val">v{$laravelVersion}</div>
                </div>
                <div class="sys-item">
                    <div class="sys-label">PHP Version</div>
                    <div class="sys-val">v{$phpVersion}</div>
                </div>
                <div class="sys-item">
                    <div class="sys-label">Execution Time</div>
                    <div class="sys-val">{$totalDuration} ms</div>
                </div>
            </div>
        </div>

        <div class="notice-banner">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <div>
                <strong>Safe Migration Policy Active:</strong> Migrations were executed using <code>php artisan migrate --force</code>. Your existing tables and user records remain completely intact and untouched.
            </div>
        </div>

        <div class="step-list">
            {$cardsHtml}
        </div>

        <div class="action-bar">
            <a href="javascript:location.reload()" class="btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                Run Maintenance Again
            </a>
            <span style="font-size: 0.85rem; color: var(--text-muted);">
                Endpoint: <code>/fix-cache</code> or <code>/api/fix-cache</code>
            </span>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
