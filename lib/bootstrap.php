<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function generate_slug(string $title): string {
    // Convert title to lowercase
    $slug = strtolower($title);
    // Replace spaces and special chars with hyphens
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    // Remove leading/trailing hyphens
    $slug = trim($slug, '-');
    // Add current year
    $slug = $slug . '-' . date('Y');
    // Add random 4 char suffix to avoid collisions
    $slug = $slug . '-' . strtolower(substr(bin2hex(random_bytes(2)), 0, 4));
    return $slug;
}
function run_migrations(): void {
    $migrationDir = __DIR__ . '/../migrations';
    $files = glob($migrationDir . '/*.sql');
    sort($files);

    db()->exec('CREATE TABLE IF NOT EXISTS migrations (
        filename TEXT PRIMARY KEY,
        run_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');

    foreach ($files as $file) {
        $filename = basename($file);
        $stmt = db()->prepare('SELECT filename FROM migrations WHERE filename = ?');
        $stmt->execute([$filename]);
        if ($stmt->fetch()) continue;

        $sql = file_get_contents($file);
        db()->exec($sql);

        $stmt = db()->prepare('INSERT INTO migrations (filename) VALUES (?)');
        $stmt->execute([$filename]);
    }
}

