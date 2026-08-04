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