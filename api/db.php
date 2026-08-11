<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit;
}

$DB_HOST = "fsook8og8oscgccgcgs88w4o";
$DB_PORT = "3306";
$DB_NAME = "default";
$DB_USER = "mysql";
$DB_PASS = "rCHm3LJRaAa04UAnRtNFPwEk8fSoif40uvP8WAPGgJ18qFzh11vMCeoii9iuX9u1";

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

/**
 * Columns backing the profile page. Added lazily so a deploy does not need a
 * manual migration step; sql/user_profile_fields.sql is the same change to run
 * by hand if the DB user is not allowed to ALTER.
 */
const USER_PROFILE_COLUMNS = [
    "company_name" => "VARCHAR(150) NULL",
    "website"      => "VARCHAR(255) NULL",
    "description"  => "TEXT NULL",
    "phone"        => "VARCHAR(50) NULL",
];

function ensureUserProfileColumns(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $stmt = $pdo->query("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
        ");
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach (USER_PROFILE_COLUMNS as $column => $type) {
            if (!in_array($column, $existing, true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN `$column` $type");
            }
        }
    } catch (PDOException $e) {
        // Read-only DB user: the callers below fall back to empty values.
    }
}

/**
 * Columns added to `content` after the table first shipped. Same lazy approach
 * as the profile columns above; sql/content_link_language.sql is the manual
 * version for a DB user without ALTER rights.
 *
 * `language` decides the reading direction of the post body on the content
 * pages, so it always carries a value - 'english' is the default.
 */
const CONTENT_EXTRA_COLUMNS = [
    "link"     => "VARCHAR(500) NULL",
    "language" => "VARCHAR(10) NOT NULL DEFAULT 'english'",
];

function ensureContentColumns(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $stmt = $pdo->query("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content'
        ");
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach (CONTENT_EXTRA_COLUMNS as $column => $type) {
            if (!in_array($column, $existing, true)) {
                $pdo->exec("ALTER TABLE content ADD COLUMN `$column` $type");
            }
        }
    } catch (PDOException $e) {
        // Read-only DB user: the callers fall back to the defaults.
    }
}

/**
 * Custom visual types the admins add on the creation form. Kept in their own
 * table so a type added once is offered again on every later post, for every
 * admin - the built-in types stay hard-coded in the form.
 */
function ensureContentTypesTable(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS content_types (
              id          INT AUTO_INCREMENT PRIMARY KEY,
              type_id     VARCHAR(60)  NOT NULL,
              label       VARCHAR(80)  NOT NULL,
              platform    VARCHAR(60)  NOT NULL,
              category    VARCHAR(60)  NOT NULL,
              created_by  INT              NULL,
              created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_type_id (type_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Read-only DB user: custom types simply will not persist.
    }
}