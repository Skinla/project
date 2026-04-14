#!/usr/bin/env php
<?php
/**
 * Test SSH connection to box.
 * Usage: php test_ssh.php
 */
$projectRoot = __DIR__;
$configPath = $projectRoot . '/config/ssh.php';

if (!file_exists($configPath)) {
    fwrite(STDERR, "Error: config/ssh.php not found. Copy from config/ssh.example.php and fill credentials.\n");
    exit(1);
}

$ssh = require $configPath;
$host = $ssh['host'] ?? '185.51.60.122';
$port = $ssh['port'] ?? 2226;
$user = $ssh['user'] ?? 'root';
$pass = $ssh['password'] ?? '';

echo "Testing SSH: $user@$host:$port ... ";

$cmd = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 -p %d %s@%s "echo OK && hostname"', escapeshellarg($pass), $port, $user, $host)
    : sprintf('ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 -p %d %s@%s "echo OK && hostname"', $port, $user, $host);

$output = trim(shell_exec($cmd) ?? '');

if (strpos($output, 'OK') !== false) {
    echo "OK\n";
    echo "Host: " . trim(explode("\n", $output)[1] ?? $output) . "\n";
    exit(0);
}

echo "FAILED\n";
fwrite(STDERR, "Output: " . ($output ?: 'no output') . "\n");
fwrite(STDERR, "Check: config/ssh.php, network, firewall, sshd on port $port\n");
exit(1);
