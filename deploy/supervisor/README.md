# Supervisor queue workers (Seats Broker Provider API)

Generate config from the admin UI (**Cron Jobs → Queue management → Copy supervisor config**) or:

```bash
php artisan tinker --execute="echo app(\App\Services\Admin\QueueProfileService::class)->supervisorConfig();"
```

## Install on AWS (EC2)

1. Apply a queue profile in admin (**Minimal load** recommended for CPU < 60%).
2. Copy generated config to the server:

```bash
sudo nano /etc/supervisor/conf.d/seatsbroker-provider.conf
```

3. Reload supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

## Queue profiles

| Profile | Workers | Sleep | Use when |
|---------|---------|-------|----------|
| **Minimal load** | 1× xs2-sync only | 5s | CPU must stay low |
| **Balanced** | 1 per queue | 3s | Normal production |
| **Throughput** | 2× xs2-sync + others | 1s | Short catch-up windows |

## Stop everything

Admin **Stop all crons** applies the **Minimal load** profile and clears queues. On the server also run:

```bash
sudo supervisorctl stop all
```

See `app/Support/AwsEmergencyStopGuide.php` for full AWS steps.
