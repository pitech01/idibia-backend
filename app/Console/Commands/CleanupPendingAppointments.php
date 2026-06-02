<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Appointment;
use Carbon\Carbon;

class CleanupPendingAppointments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appointments:cleanup-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cancel pending appointments that have exceeded the lock duration (10 mins)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cutoff = Carbon::now()->subMinutes(10);

        $count = Appointment::where('status', 'pending_payment')
            ->where('created_at', '<', $cutoff)
            ->update(['status' => 'cancelled']);

        $this->info("Cancelled $count pending appointments.");
    }
}
