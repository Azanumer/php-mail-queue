<?php
/**
 * MailQueue — a dependency-free, file-backed mail queue.
 *
 * Problem it solves: calling mail() (or an SMTP send) inline makes page
 * requests slow and drops mail silently when the MTA burps. Queue the mail
 * instead and let a tiny cron worker deliver it with retries, backoff and
 * a per-minute throttle.
 *
 * Usage:
 *   $queue = new MailQueue(__DIR__ . '/../queue');
 *   $queue->enqueue('user@example.com', 'Welcome!', $htmlBody, [
 *       'headers'    => ['Content-Type: text/html; charset=UTF-8'],
 *       'priority'   => 10,        // higher = sent first (default 0)
 *       'delay'      => 300,       // deliver no earlier than +5 min
 *       'maxAttempts'=> 5,         // default 5
 *   ]);
 *
 * Then point a cron at worker.php every minute (see worker.php).
 *
 * Queue layout (JSON files, atomic via rename):
 *   queue/pending/   — waiting jobs   (<priority>.<enqueue-time>.<random>.json)
 *   queue/processing/ — picked up by the worker
 *   queue/dead/      — exhausted retries, for manual inspection
 *
 * @author Azan Umer <https://github.com/Azanumer>
 * @license MIT
 */
final class MailQueue
{
    private string $dir;
    private int $defaultMaxAttempts;

    public function __construct(string $queueDir, int $defaultMaxAttempts = 5)
    {
        $this->dir = rtrim($queueDir, '/');
        $this->defaultMaxAttempts = $defaultMaxAttempts;
        foreach (['pending', 'processing', 'dead'] as $sub) {
            $path = "{$this->dir}/{$sub}";
            if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
                throw new RuntimeException("Cannot create queue dir: {$path}");
            }
        }
    }

    /**
     * Queue one email. Returns the job id (file name).
     */
    public function enqueue(string $to, string $subject, string $body, array $options = []): string
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid recipient: {$to}");
        }

        $now = time();
        $job = [
            'id'          => null, // filled in below
            'to'          => $to,
            'subject'     => $subject,
            'body'        => $body,
            'headers'     => $options['headers'] ?? [],
            'priority'    => (int) ($options['priority'] ?? 0),
            'not_before'  => $now + (int) ($options['delay'] ?? 0),
            'attempts'    => 0,
            'max_attempts'=> (int) ($options['maxAttempts'] ?? $this->defaultMaxAttempts),
            'last_error'  => null,
            'created_at'  => $now,
        ];

        // Higher priority sorts first; same priority -> oldest first.
        $name = sprintf('%08d.%010d.%s.json',
            99999999 - max(0, $job['priority']),
            $now,
            bin2hex(random_bytes(6))
        );
        $job['id'] = $name;

        // Atomic write: temp file + rename so the worker never reads a half file.
        $tmp = "{$this->dir}/pending/.tmp." . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException('Cannot write queue temp file');
        }
        rename($tmp, "{$this->dir}/pending/{$name}");

        return $name;
    }

    /**
     * Claim the next due job. Returns the job array or null.
     * The file is moved to processing/ so concurrent workers never double-send.
     */
    public function claimNext(): ?array
    {
        $files = glob("{$this->dir}/pending/*.json") ?: [];
        sort($files); // naming scheme already sorts by priority, then time
        $now = time();

        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            $job = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($job)) {
                rename($file, "{$this->dir}/dead/" . basename($file) . '.corrupt');
                continue; // poison file -> dead-letter, keep going
            }
            if ($job['not_before'] > $now) {
                continue; // delayed job, not due yet
            }
            if (rename($file, "{$this->dir}/processing/" . basename($file))) {
                return $job;
            }
            // Lost the rename race to another worker -> try the next file.
        }
        return null;
    }

    /**
     * Mark a job as failed. Re-queues with exponential backoff
     * (1m, 2m, 4m, 8m, ...) or dead-letters it when attempts are exhausted.
     */
    public function fail(array $job, string $error): void
    {
        $job['attempts']++;
        $job['last_error'] = mb_substr($error, 0, 500);
        $base = "{$this->dir}/processing/{$job['id']}";

        if ($job['attempts'] >= $job['max_attempts']) {
            rename($base, "{$this->dir}/dead/{$job['id']}");
            return;
        }

        $job['not_before'] = time() + (60 * (2 ** ($job['attempts'] - 1)));
        $tmp = "{$this->dir}/pending/.tmp." . bin2hex(random_bytes(8));
        file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE));
        rename($tmp, "{$this->dir}/pending/{$job['id']}"); // same id, back in line
        @unlink($base);
    }

    public function done(array $job): void
    {
        @unlink("{$this->dir}/processing/{$job['id']}");
    }

    /**
     * Quick stats for dashboards / health checks.
     */
    public function stats(): array
    {
        return [
            'pending'    => count(glob("{$this->dir}/pending/*.json") ?: []),
            'processing' => count(glob("{$this->dir}/processing/*.json") ?: []),
            'dead'       => count(glob("{$this->dir}/dead/*.json") ?: []),
        ];
    }
}
