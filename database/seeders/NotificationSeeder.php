<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = \App\Models\User::find(1);

        if ($user) {
            $user->notify(new \App\Notifications\GeneralNotification([
                'title' => 'Appointment Confirmed',
                'message' => 'Your appointment with Dr. Armstrong is confirmed for tomorrow.',
                'type' => 'appointment'
            ]));

            $user->notify(new \App\Notifications\GeneralNotification([
                'title' => 'Lab Results',
                'message' => 'Blood test results are now available.',
                'type' => 'result'
            ]));

             $user->notify(new \App\Notifications\GeneralNotification([
                'title' => 'System Update',
                'message' => 'We have updated our privacy policy.',
                'type' => 'system'
            ]));
        }
    }
}
