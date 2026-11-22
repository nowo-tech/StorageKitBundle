<?php

declare(strict_types=1);

/*
 * The other side of a real MySQL / MariaDB deadlock for RowVersionRaceTest (2.1, ZH-M1): a separate process, so the
 * two transactions really wait for each other. Arguments: database URL, the row it waits for, the row it holds, and
 * extra rows it changes so that InnoDB picks the test's transaction (fewer changed rows) as the deadlock victim.
 *
 * 1. Begins, changes the held row and the extra rows, prints "ready".
 * 2. After 1.5 s updates the row the test's save holds (the test's save is then waiting for the held row): deadlock.
 * 3. Rolls back and prints "done <sqlstate or ok>".
 */

$url    = (string) ($argv[1] ?? '');
$wanted = (int) ($argv[2] ?? 0);
$held   = (int) ($argv[3] ?? 0);
$extra  = array_map('intval', array_filter(explode(',', (string) ($argv[4] ?? ''))));
$parts  = parse_url($url);
if (!is_array($parts) || !isset($parts['host'], $parts['path'])) {
    throw new InvalidArgumentException('usage: deadlock-peer.php <mysql url> <wanted id> <held id> <extra ids>');
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $parts['host'], $parts['port'] ?? 3306, ltrim($parts['path'], '/')),
    isset($parts['user']) ? urldecode($parts['user']) : null,
    isset($parts['pass']) ? urldecode($parts['pass']) : null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
$pdo->beginTransaction();
foreach ([$held, ...$extra] as $id) {
    $pdo->prepare("UPDATE storage_kit_profile SET prefix = CONCAT('peer-', id) WHERE id = ?")->execute([$id]);
}
fwrite(\STDOUT, "ready\n");
fflush(\STDOUT);

// Waits on the server (not in PHP) while the test's save takes its row and starts waiting for the held one.
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
