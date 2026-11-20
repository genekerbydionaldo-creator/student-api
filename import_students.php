<?php
// One-time CLI importer: php import_students.php
// Place students_25.json beside this file. Run once on an EMPTY table, then delete this script.
require_once __DIR__ . '/config/database.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$file = __DIR__ . '/students_25.json';
$rows = json_decode(file_get_contents($file), true);
if (!is_array($rows)) {
    throw new RuntimeException('The JSON file is invalid.');
}

if ((int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn() > 0) {
    exit("Table is not empty. Clear it first to avoid duplicates.\n");
}

$stmt = $pdo->prepare(
    'INSERT INTO students (first_name, middle_initial, last_name, program, year_level)
     VALUES (:first_name, :middle_initial, :last_name, :program, :year_level)'
);

$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        $stmt->execute([
            'first_name' => trim($row['first_name']),
            'middle_initial' => ($row['middle_initial'] ?? '') === '' ? null : $row['middle_initial'],
            'last_name' => trim($row['last_name']),
            'program' => $row['program'],
            'year_level' => (int)$row['year_level'],
        ]);
    }
    $pdo->commit();
    echo 'Imported ' . count($rows) . " student records.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
