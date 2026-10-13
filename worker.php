<?php
/**
 * worker.php — CLI cron worker for the MailQueue.
 *
 * Run every minute:
 *   * * * * * /usr/bin/php /var/www/app/vendor-mailqueue/worker.php >> /var/log/mailqueue.log 2>&1
 *
 * Behaviour:
 *  - Single-instance via flock (a stuck cron never overlaps itself).
 *  - Sends at most --per-minute jobs, then exits (nice to MTAs / SMTP quotas).
 *  - Delivery is a callable: use PHP's mail() by default, or return a custom
 *    sender from config.php (e.g. the SmtpMailer from the php-utilities repo).
 *  - Failures re-queue with exponential backoff; dead jobs land in queue/dead/.
 *
 * Exit codes: 0 = finished (even if some jobs failed — check queue/dead/),
 *             1 = fatal (lock busy, bad config, unwritable dirs).
 */

declare(ticks=1);

$perMinute = 30;
foreach ($argv as $arg) {
    if (strpos($arg, '--per-minute=') === 0) {
        $perMinute = max(1, (int) substr($arg, strlen('--per-minute=')));
    }
}

require __DIR__ . '/src/MailQueue.php';

$configPath = __DIR__ . '/config.php';
$config = file_exists($configPath) ? require $configPath : [];
if (!is_array($config)) {
    fwrite(STDERR, "config.php must return an array\n");
    exit(1);
}

// ---- single-instance lock ------------------------------------------------
$lockFile = __DIR__ . '/queue/worker.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another worker is running, exiting.\n");
    exit(1);
}

$queue = new MailQueue(__DIR__ . '/queue', $config['max_attempts'] ?? 5);

// Default sender: PHP mail(). Override in config.php with any callable.
$sender = $config['sender'] ?? static function (array $job): bool {
    $headers = implode("\r\n", $job['headers']);
    // mail() returns bool but can still swallow failures; good enough for the default.
    return mail($job['to'], $job['subject'], $job['body'], $headers);
};

$sent = 0;
$failed = 0;
$start = time();

while ($sent < $perMinute) {
    $job = $queue->claimNext();
    if ($job === null) {
        break; // queue drained (or everything is delayed)
    }

    try {
        $ok = $sender($job);
    } catch (Throwable $e) {
        $ok = false;
        $jobError = $e->getMessage();
    }

    if ($ok) {
        $queue->done($job);
        $sent++;
    } else {
        $queue->fail($job, $jobError ?? 'sender returned false');
        $failed++;
    }

    // Don't let one run chew through the throttle for a whole minute.
    if (time() - $start >= 50) {
        break;
    }
}

$stats = $queue->stats();
echo sprintf(
    "[%s] sent=%d failed=%d pending=%d dead=%d\n",
    date('c'), $sent, $failed, $stats['pending'], $stats['dead']
);

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
