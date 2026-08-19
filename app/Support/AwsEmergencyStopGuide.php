<?php

namespace App\Support;

class AwsEmergencyStopGuide
{
    /**
     * Shell commands to run on the AWS host when UI stop-all is not enough
     * (supervisor/systemd respawns workers, schedule:work keeps running, etc.).
     *
     * @return list<array{title: string, command: string, note: string}>
     */
    public static function steps(): array
    {
        return [
            [
                'title' => 'Stop supervisor-managed queue workers',
                'command' => 'sudo supervisorctl stop all',
                'note' => 'Or stop individual programs: sudo supervisorctl stop "laravel-worker:*" "schedule:work"',
            ],
            [
                'title' => 'Stop systemd queue/scheduler units (if used)',
                'command' => 'sudo systemctl stop laravel-worker laravel-scheduler 2>/dev/null; sudo systemctl stop seatsbroker-worker seatsbroker-scheduler 2>/dev/null',
                'note' => 'Adjust unit names to match your host. Use systemctl list-units | grep -E "worker|schedule|laravel|queue".',
            ],
            [
                'title' => 'Kill orphaned PHP worker/scheduler processes',
                'command' => 'pkill -f "artisan queue:work" ; pkill -f "artisan schedule:work" ; pkill -f "run-queue-workers.sh"',
                'note' => 'Safe after supervisor/systemd is stopped. Verify with: pgrep -af "artisan (queue|schedule)"',
            ],
            [
                'title' => 'Disable crontab schedule:run (prevents new cron dispatches)',
                'command' => 'crontab -l | grep -v "schedule:run" | crontab -',
                'note' => 'Re-add later: * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1',
            ],
            [
                'title' => 'Set env flags on the server (persists across deploys if in .env)',
                'command' => 'cd /path/to/seatsbroker-provider-api && sed -i \'\' \'s/^APP_SCHEDULER_ENABLED=.*/APP_SCHEDULER_ENABLED=false/\' .env && sed -i \'\' \'s/^APP_LOW_LOAD_MODE=.*/APP_LOW_LOAD_MODE=true/\' .env',
                'note' => 'Also set XS2_ENABLED=false and SELLER_API_ENABLED=false if you want zero integration activity.',
            ],
            [
                'title' => 'Clear queued jobs directly (if UI/API unreachable)',
                'command' => 'cd /path/to/seatsbroker-provider-api && php artisan tinker --execute="DB::table(\'jobs\')->delete();"',
                'note' => 'Equivalent to stop-all queue purge. Run after workers are stopped.',
            ],
        ];
    }

    /** @return list<string> */
    public static function summary(): array
    {
        return array_map(
            static fn (array $step): string => $step['title'],
            self::steps(),
        );
    }
}
