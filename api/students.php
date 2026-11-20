<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';

const PROGRAMS = ['BSIT', 'BSIS', 'BSCS'];

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        respond(400, ['error' => 'Request body must be a valid JSON object']);
    }
    return $data;
}

function validateStudent(array $data): array {
    $first = trim((string)($data['first_name'] ?? ''));
    $middle = trim((string)($data['middle_initial'] ?? ''));
    $last = trim((string)($data['last_name'] ?? ''));
    $program = $data['program'] ?? '';
    $year = filter_var($data['year_level'] ?? null, FILTER_VALIDATE_INT);

    $errors = [];
    if ($first === '' || mb_strlen($first) > 80) {
        $errors['first_name'] = 'Required; maximum 80 characters.';
    }
    if ($middle !== '' && mb_strlen($middle) > 1) {
        $errors['middle_initial'] = 'Use one character or an empty string.';
    }
    if ($last === '' || mb_strlen($last) > 80) {
        $errors['last_name'] = 'Required; maximum 80 characters.';
    }
    if (!in_array($program, PROGRAMS, true)) {
        $errors['program'] = 'Must be BSIT, BSIS, or BSCS.';
    }
    if ($year === false || $year < 1 || $year > 4) {
        $errors['year_level'] = 'Must be an integer from 1 to 4.';
    }
    if ($errors) {
        respond(422, ['error' => 'Validation failed', 'details' => $errors]);
    }

    return [
        'first_name' => $first,
        'middle_initial' => ($middle === '') ? null : $middle,
        'last_name' => $last,
        'program' => $program,
        'year_level' => $year,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$idRaw = $_GET['id'] ?? null;
$id = null;
if ($idRaw !== null) {
    $id = filter_var($idRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        respond(400, ['error' => 'id must be a positive integer']);
    }
}

try {
    switch ($method) {
        case 'GET':
            if ($id !== null) {
                $stmt = $pdo->prepare(
                    'SELECT id, first_name, middle_initial, last_name, program, year_level
                     FROM students WHERE id = :id'
                );
                $stmt->execute(['id' => $id]);
                $student = $stmt->fetch();
                if (!$student) {
                    respond(404, ['error' => 'Student not found']);
                }
                respond(200, ['data' => $student]);
            }

            // ---- Exercise D: filter, search, pagination ----
            $where = [];
            $params = [];

            if (isset($_GET['program'])) {
                $program = strtoupper(trim((string)$_GET['program']));
                if (!in_array($program, PROGRAMS, true)) {
                    respond(400, ['error' => 'program must be BSIT, BSIS, or BSCS']);
                }
                $where[] = 'program = :program';
                $params[':program'] = [$program, PDO::PARAM_STR];
            }

            if (isset($_GET['search']) && trim((string)$_GET['search']) !== '') {
                $like = '%' . trim((string)$_GET['search']) . '%';
                // Native prepares cannot reuse one named placeholder twice.
                $where[] = '(first_name LIKE :s1 OR last_name LIKE :s2)';
                $params[':s1'] = [$like, PDO::PARAM_STR];
                $params[':s2'] = [$like, PDO::PARAM_STR];
            }

            $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 100]]);
            $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]);
            if ($limit === false || $offset === false) {
                respond(400, ['error' => 'limit must be 1-100 and offset must be 0 or greater']);
            }

            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $count = $pdo->prepare("SELECT COUNT(*) FROM students $whereSql");
            foreach ($params as $k => [$v, $t]) {
                $count->bindValue($k, $v, $t);
            }
            $count->execute();
            $total = (int)$count->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT id, first_name, middle_initial, last_name, program, year_level
                 FROM students $whereSql ORDER BY id ASC LIMIT :limit OFFSET :offset"
            );
            foreach ($params as $k => [$v, $t]) {
                $stmt->bindValue($k, $v, $t);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            respond(200, [
                'data' => $stmt->fetchAll(),
                'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
            ]);

        case 'POST':
            $student = validateStudent(readJsonBody());
            $stmt = $pdo->prepare(
                'INSERT INTO students
                 (first_name, middle_initial, last_name, program, year_level)
                 VALUES (:first_name, :middle_initial, :last_name, :program, :year_level)'
            );
            $stmt->execute($student);
            $student['id'] = (int)$pdo->lastInsertId();
            respond(201, ['message' => 'Student created', 'data' => $student]);

        case 'PUT':
            if ($id === null) {
                respond(400, ['error' => 'Provide id, e.g. ?id=3']);
            }
            $student = validateStudent(readJsonBody());
            $student['id'] = $id;

            $check = $pdo->prepare('SELECT id FROM students WHERE id = :id');
            $check->execute(['id' => $id]);
            if (!$check->fetch()) {
                respond(404, ['error' => 'Student not found']);
            }

            $stmt = $pdo->prepare(
                'UPDATE students SET first_name = :first_name,
                 middle_initial = :middle_initial, last_name = :last_name,
                 program = :program, year_level = :year_level
                 WHERE id = :id'
            );
            $stmt->execute($student);
            respond(200, ['message' => 'Student updated', 'data' => $student]);

        case 'DELETE':
            if ($id === null) {
                respond(400, ['error' => 'Provide id, e.g. ?id=3']);
            }
            $stmt = $pdo->prepare('DELETE FROM students WHERE id = :id');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() === 0) {
                respond(404, ['error' => 'Student not found']);
            }
            respond(200, ['message' => 'Student deleted', 'id' => $id]);

        default:
            header('Allow: GET, POST, PUT, DELETE, OPTIONS');
            respond(405, ['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    respond(500, ['error' => 'A database error occurred']);
}
