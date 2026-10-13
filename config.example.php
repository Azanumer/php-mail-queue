<?php
/**
 * config.example.php — copy to config.php and adjust.
 *
 * return [
 *     // Max delivery attempts per job before it is dead-lettered.
 *     'max_attempts' => 5,
 *
 *     // Optional custom sender. Must return bool: true = delivered.
 *     // Receives the full job array: to, subject, body, headers, ...
 *     'sender' => function (array $job): bool {
 *         $mailer = new SmtpMailer('smtp.example.com', 587, 'user', 'pass');
 *         return $mailer->send($job['to'], $job['subject'], $job['body'], $job['headers']);
 *     },
 * ];
 */

return [
    'max_attempts' => 5,
    // 'sender' => ...,
];
