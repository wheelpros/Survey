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
 * as the profile columns above; sql/content_link.sql is the manual
 * version for a DB user without ALTER rights.
 *
 * The post body carries its own alignment in the caption HTML, so there is
 * no language column - the toolbar on admin-content-form.html sets it.
 */
const CONTENT_EXTRA_COLUMNS = [
    "link"     => "VARCHAR(500) NULL",
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
 * Custom content types the admins add on the creation form. Kept in their own
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
              icon        VARCHAR(20)  NOT NULL DEFAULT 'plus',
              created_by  INT              NULL,
              created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_type_id (type_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // `icon` arrived after the table did; types saved before it keep the
        // generic chip until they are re-created.
        $stmt = $pdo->query("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_types'
        ");

        if (!in_array("icon", $stmt->fetchAll(PDO::FETCH_COLUMN), true)) {
            $pdo->exec("ALTER TABLE content_types ADD COLUMN icon VARCHAR(20) NOT NULL DEFAULT 'plus'");
        }

    } catch (PDOException $e) {
        // Read-only DB user: custom types simply will not persist.
    }
}

/**
 * Slides shown beside the sign-in form on login.html. Capped at five: the
 * panel is decoration, and a longer rotation just means visitors never see
 * the later ones. sql/login_slides.sql is the same table to run by hand.
 */
const LOGIN_SLIDES_MAX = 5;

/**
 * Floor for a slide image. The panel is half a desktop screen tall, so
 * anything under this is visibly stretched. settings.html mirrors these two
 * numbers so the admin hears about it before the upload, not after.
 */
const LOGIN_SLIDE_MIN_WIDTH = 800;
const LOGIN_SLIDE_MIN_HEIGHT = 450;

function ensureLoginSlidesTable(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_slides (
              id          INT AUTO_INCREMENT PRIMARY KEY,
              image_path  VARCHAR(255) NOT NULL,
              title       VARCHAR(120)     NULL,
              subtitle    VARCHAR(255)     NULL,
              position    INT          NOT NULL DEFAULT 0,
              created_by  INT              NULL,
              created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_position (position, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Read-only DB user: login.html keeps the centred form.
    }
}

/**
 * Columns the calendar pages added to `appointments` after the table first
 * shipped. Same lazy approach as the profile columns above, so a deploy needs
 * no manual migration; sql/appointment_requests.sql is the version to run by
 * hand if the DB user is not allowed to ALTER.
 *
 * A row is a meeting request in one of two directions, which is what
 * `requested_by` records:
 *
 *   'admin' - an admin asked a client for time. Waits on the user.
 *   'user'  - a client asked the admin for time. Waits on an admin.
 *
 * `client` is the company_name the admin addressed, kept on the row so the
 * admin's own list still reads correctly after a user changes companies.
 */
const APPOINTMENT_EXTRA_COLUMNS = [
    "topic"        => "VARCHAR(200) NULL",
    "notes"        => "TEXT NULL",
    "requested_by" => "VARCHAR(10) NOT NULL DEFAULT 'admin'",
    "admin_id"     => "INT NULL",
    "client"       => "VARCHAR(150) NULL",
];

function ensureAppointmentTables(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        // The table predates this file on the deployed database. Creating it
        // here only matters for a fresh install.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS appointments (
              id         INT AUTO_INCREMENT PRIMARY KEY,
              user_id    INT          NOT NULL,
              title      VARCHAR(200)     NULL,
              date       DATE         NOT NULL,
              time       TIME         NOT NULL,
              status     VARCHAR(20)  NOT NULL DEFAULT 'pending',
              created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_user_date (user_id, date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $pdo->query("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
        ");
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach (APPOINTMENT_EXTRA_COLUMNS as $column => $type) {
            if (!in_array($column, $existing, true)) {
                $pdo->exec("ALTER TABLE appointments ADD COLUMN `$column` $type");
            }
        }
    } catch (PDOException $e) {
        // Read-only DB user: calendar.php falls back to the base columns.
    }
}
