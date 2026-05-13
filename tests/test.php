<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

// Original test
test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

// Feature 1: Search by title
test('search returns documents matching title', function () {
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE d.title LIKE ?
        ORDER BY d.created_at DESC
    ');
    $stmt->execute(['%Welcome%']);
    $docs = $stmt->fetchAll();
    assert_true(count($docs) > 0, 'expected at least one result for Welcome');
    assert_true($docs[0]['title'] === 'Welcome Packet', 'expected Welcome Packet');
});

test('search returns empty for non-existent title', function () {
    $stmt = db()->prepare('
        SELECT d.*
        FROM documents d
        WHERE d.title LIKE ?
    ');
    $stmt->execute(['%xyznotexist%']);
    $docs = $stmt->fetchAll();
    assert_true(count($docs) === 0, 'expected no results for xyznotexist');
});

// Feature 2: Scheduled publishing
test('document with future publish_at is not yet available', function () {
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ');
    $future = date('Y-m-d H:i:s', strtotime('+1 day'));
    $stmt->execute(['Future Doc', 'Body here', $future]);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    assert_true($doc['publish_at'] > date('Y-m-d H:i:s'), 'expected publish_at to be in the future');
});

test('document with no publish_at is always available', function () {
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, NULL)
    ');
    $stmt->execute(['No Schedule Doc', 'Body here']);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    assert_true($doc['publish_at'] === null, 'expected publish_at to be null');
});

// Feature 3: Human readable slugs
test('generate_slug produces a readable slug from title', function () {
    $slug = generate_slug('Hello World');
    assert_true(str_starts_with($slug, 'hello-world-'), 'expected slug to start with hello-world-, got: ' . $slug);
});

test('generate_slug includes current year', function () {
    $slug = generate_slug('Test Document');
    assert_true(str_contains($slug, date('Y')), 'expected slug to contain current year');
});

test('document is saved with a slug', function () {
    $slug = generate_slug('Slug Test Doc');
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, slug)
        VALUES (?, ?, 1, ?)
    ');
    $stmt->execute(['Slug Test Doc', 'Body here', $slug]);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT slug FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    assert_true($doc['slug'] === $slug, 'expected slug to be saved correctly');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);