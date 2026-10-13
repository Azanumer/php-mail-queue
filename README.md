# php-mail-queue

Dependency-free, file-backed **mail queue** for PHP. Queue emails in your request, deliver them from a 1-minute cron — no more slow page loads waiting on `mail()`/SMTP, no more silently dropped mail when the MTA hiccups.

## Why

- **Fast requests** — enqueueing is one atomic file write (~ms); delivery happens out-of-band.
- **Resilient** — failures re-queue with exponential backoff (1m, 2m, 4m, …); exhausted jobs land in `queue/dead/` for inspection instead of vanishing.
- **Throttled** — `--per-minute` cap keeps you inside SMTP/MTA quotas.
- **Zero dependencies** — no Redis, no DB, no composer. Just PHP 7.4+.

## Quick start

```php
require 'src/MailQueue.php';

$queue = new MailQueue(__DIR__ . '/queue');
$queue->enqueue('customer@example.com', 'Your order shipped', $html, [
    'headers'  => ['Content-Type: text/html; charset=UTF-8'],
    'priority' => 10,   // higher = sent first
    'delay'    => 300,   // deliver no earlier than +5 minutes
]);
```

Cron (every minute):

```
* * * * * /usr/bin/php /var/www/app/mailqueue/worker.php --per-minute=30 >> /var/log/mailqueue.log 2>&1
```

Copy `config.example.php` to `config.php` to set `max_attempts` or plug in a custom sender (e.g. the dependency-free `SmtpMailer` from the `php-utilities` repo).

## Layout

| Path | Purpose |
|---|---|
| `queue/pending/` | Waiting jobs (priority + time ordered) |
| `queue/processing/` | Claimed by the worker (crash-safe — a dead worker's jobs stay visible here) |
| `queue/dead/` | Exhausted retries / corrupt files, for manual review |

## API

- `enqueue($to, $subject, $body, $options)` → job id. Options: `headers`, `priority`, `delay` (seconds), `maxAttempts`.
- `claimNext()` → next due job (atomic claim — safe with multiple workers).
- `fail($job, $error)` → re-queue with backoff, or dead-letter.
- `done($job)` → remove after successful send.
- `stats()` → `['pending' => n, 'processing' => n, 'dead' => n]`.

Requires PHP 7.4+. MIT licensed.
