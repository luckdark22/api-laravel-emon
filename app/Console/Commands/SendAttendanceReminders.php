<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Placement;
use App\Services\FcmService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SendAttendanceReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send push notifications for attendance check-in and check-out reminders';

    /**
     * Execute the console command.
     */
    public function handle(FcmService $fcmService)
    {
        $now = Carbon::now();
        $this->info("Running attendance reminders at {$now}");

        // Get all active placements with student user token and dudi schedule
        $placements = Placement::with(['student.user', 'dudi'])
            ->where('status', 'ACTIVE') // Ensure status matches constant
            ->get();

        foreach ($placements as $placement) {
            $dudi = $placement->dudi;
            $student = $placement->student;

            if (!$dudi || !$student || !$student->user || !$student->user->fcm_token) {
                continue;
            }

            // Parse work times
            try {
                // Assuming work_start_time and work_end_time are H:i:s or H:i
                $startTime = Carbon::createFromTimeString($dudi->work_start_time);
                $endTime = Carbon::createFromTimeString($dudi->work_end_time);

                // Adjust strict comparison to a window (e.g., matching the minute)
                $notificationWindow = 15; // minutes

                // Check-in Reminder (15 mins before start)
                if ($now->format('H:i') === $startTime->subMinutes($notificationWindow)->format('H:i')) {
                    $this->sendReminder($fcmService, $student->user->fcm_token, 'Waktunya Masuk PKL!', '15 Menit lagi jam masuk PKL dimulai. Jangan lupa absen ya! 👋', '/presensi');
                }

                // Check-out Reminder (15 mins before end)
                // Note: reset $endTime because subMinutes modifies it in place if not cloned, 
                // but createFromTimeString returns a new instance each time we call it? 
                // Wait, Carbon is mutable depending on version, but usually in Laravel it's safe if we don't chain on original variable used elsewhere. 
                // Actually, let's allow "15 mins before end"
                $endTime = Carbon::createFromTimeString($dudi->work_end_time);
                if ($now->format('H:i') === $endTime->subMinutes($notificationWindow)->format('H:i')) {
                    $this->sendReminder($fcmService, $student->user->fcm_token, 'Waktunya Pulang PKL!', '15 Menit lagi jam pulang. Jangan lupa absen pulang saat selesai! 🏠', '/presensi');
                }

            } catch (\Exception $e) {
                Log::error("Error processing reminder for placement {$placement->id}: " . $e->getMessage());
            }
        }
    }

    private function sendReminder($fcmService, $token, $title, $body, $url = null)
    {
        $this->info("Sending to token: " . substr($token, 0, 10) . "...");
        $data = $url ? ['url' => $url] : [];
        $success = $fcmService->sendNotification($token, $title, $body, $data);
        if ($success) {
            $this->info("Notification sent successfully.");
        } else {
            $this->error("Failed to send notification.");
        }
    }
}
