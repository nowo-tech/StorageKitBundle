<?php

declare(strict_types=1);

/*
 * The other side of a real MySQL / MariaDB deadlock for GenericFilesRaceTest (2.2): a separate process, so the two
 * transactions really wait for each other. Arguments: database URL, the profile row it waits for, and the file rows it
 * holds (the first is the one the test's variant write wants; the others make the test's transaction the victim).
 *
 * 1. Begins, changes the held file rows, prints "ready".
 * 2. After 1.5 s updates the profile row the test's host transaction holds (the test's variant write is then waiting
 *    for the first held file row): deadlock.
 * 3. Rolls back and prints "done <sqlstate or ok>".
 */

$url    = (string) ($argv[1] ?? '');
$wanted = (int) ($argv[2] ?? 0);
$held   = array_map('intval', array_filter(explode(',', (string) ($argv[3] ?? ''))));
$parts  = parse_url($url);
if (!is_array($parts) || !isset($parts['host'], $parts['path']) || $held === []) {
    throw new InvalidArgumentException('usage: file-deadlock-peer.php <mysql url> <wanted profile id> <held file ids>');
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $parts['host'], $parts['port'] ?? 3306, ltrim($parts['path'], '/')),
    isset($parts['user']) ? urldecode($parts['user']) : null,
    isset($parts['pass']) ? urldecode($parts['pass']) : null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
$pdo->beginTransaction();
foreach ($held as $id) {
    $pdo->prepare("UPDATE storage_kit_file SET original_name = CONCAT('peer-', id) WHERE id = ?")->execute([$id]);
}
fwrite(\STDOUT, "ready\n");
fflush(\STDOUT);

// Waits on the server (not in PHP) while the test's variant write starts waiting for the first held row.
$pdo->exec('DO SLEEP(1.5)');
$result = 'ok';
try {
    $pdo->prepare("UPDATE storage_kit_profile SET prefix = 'peer' WHERE id = ?")->execute([$wanted]);
} catch (PDOException $e) {
    $result = (string) $e->getCode();
}
if ($pdo->inTransaction()) {
    $pdo->rollBack();
}
fwrite(\STDOUT, 'done ' . $result . "\n");
