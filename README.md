# PHP CRUD API using PDO — Student Records
IT 110 · Gene Kerby B. Dionaldo

## Setup
1. Run `database.sql` in phpMyAdmin (or `mysql -u root < database.sql`).
2. Edit `config/database.php` if your MySQL password isn't empty.
3. Put `students_25.json` in this folder, then run once: `php import_students.php`
4. Start the server from this folder: `php -S 127.0.0.1:8000`

## Endpoints (`/api/students.php`)
| Operation | Method | URL |
|---|---|---|
| Create | POST | `/api/students.php` |
| Read all | GET | `/api/students.php` (supports `?program=BSIT&search=reyes&limit=10&offset=0`) |
| Read one | GET | `/api/students.php?id=1` |
| Update | PUT | `/api/students.php?id=1` |
| Delete | DELETE | `/api/students.php?id=1` |

## Lab exercise answers (expected results — paste your real outputs from your own run)

### Exercise A
| Request | Status | Body |
|---|---|---|
| GET collection | 200 | `{"data":[...],"meta":{...}}` |
| GET ?id=1 (exists) | 200 | `{"data":{...}}` |
| GET ?id=999 | 404 | `{"error":"Student not found"}` |

### Exercise B
- POST valid BSIT / year 1 → **201**, `{"message":"Student created","data":{...,"id":N}}`
- POST empty `first_name` → **422**, `{"error":"Validation failed","details":{"first_name":"Required; maximum 80 characters."}}`
- PUT with new program/year_level → **200**, `{"message":"Student updated",...}`
- **Why prepared statements?** The SQL text and the user's values are sent to the database separately, so input is always treated as data, never as SQL code. This prevents SQL injection (e.g. `' OR 1=1 --`), and also handles quoting/escaping automatically.

### Exercise C
- DELETE existing id → **200**, `{"message":"Student deleted","id":N}`; it no longer appears in GET collection.
- DELETE the same id again → **404**, `{"error":"Student not found"}`
- Malformed JSON → **400**, `{"error":"Request body must be a valid JSON object"}`
- `program=BSBA` → **422**, details: `"Must be BSIT, BSIS, or BSCS."`
- `year_level=5` → **422**, details: `"Must be an integer from 1 to 4."`

### Exercise D (implemented in `api/students.php`)
- Filter: `?program=BSIT` (invalid value → 400)
- Pagination: `?limit=10&offset=20` (limit 1–100, offset ≥ 0, validated; response includes `meta.total`)
- Search: `?search=rey` → bound `LIKE` on first_name or last_name
- Refactor / auth design (Exercise D 17–18): move `respond()`, `readJsonBody()`, `validateStudent()` into `helpers.php` and `require_once` it. For admin auth: issue a login endpoint that returns a token (JWT or session), require `Authorization: Bearer <token>` for POST/PUT/DELETE, check `role === 'admin'` server-side (401 if missing/invalid token, 403 if not admin), keep GET public or authenticated as needed, and serve over HTTPS.

## curl test commands (Linux/macOS)
```
curl -i http://127.0.0.1:8000/api/students.php
curl -i "http://127.0.0.1:8000/api/students.php?id=1"
curl -i -X POST http://127.0.0.1:8000/api/students.php -H "Content-Type: application/json" \
  -d '{"first_name":"Alyssa","middle_initial":"M","last_name":"Reyes","program":"BSIT","year_level":1}'
curl -i -X PUT "http://127.0.0.1:8000/api/students.php?id=1" -H "Content-Type: application/json" \
  -d '{"first_name":"Alyssa","middle_initial":"M","last_name":"Reyes","program":"BSCS","year_level":2}'
curl -i -X DELETE "http://127.0.0.1:8000/api/students.php?id=1"
```
