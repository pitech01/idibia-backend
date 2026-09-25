<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Artisan;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url', 'http://localhost:5173')."/reset-password?token=$token&email={$notifiable->getEmailForPasswordReset()}";
        });

        if ($this->app->runningInConsole()) {
            Event::listen(CommandStarting::class, function (CommandStarting $event) {
                if ($event->command === 'serve') {
                    try {
                        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
                            if (function_exists('popen') && function_exists('pclose')) {
                                $h = @popen("start /B php artisan signaling:start > NUL 2>&1", "r");
                                if ($h) pclose($h);
                            }
                        } else {
                            if (function_exists('exec')) {
                                @\exec("php artisan signaling:start > /dev/null 2>&1 &");
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }
                    
                    if ($event->output) {
                        $event->output->writeln('<info>Signaling server checked/started alongside artisan serve.</info>');
                    }
                }
            });
        }
    }
}
