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

/**
 * Columns added to `surveys` after the table first shipped. Same lazy approach
 * as the content columns below; sql/survey_description.sql is the manual
 * version for a DB user without ALTER rights.
 */
const SURVEY_EXTRA_COLUMNS = [
    "description" => "TEXT NULL",
];

function ensureSurveyColumns(PDO $pdo)
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
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'surveys'
        ");
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach (SURVEY_EXTRA_COLUMNS as $column => $type) {
            if (!in_array($column, $existing, true)) {
                $pdo->exec("ALTER TABLE surveys ADD COLUMN `$column` $type");
            }
        }
    } catch (PDOException $e) {
        // Read-only DB user: the callers fall back to an empty description.
    }
}

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
 * Floor for a slide image. The panel is a full-height, half-width column -
 * around 720 x 900 CSS px on a 1440 x 900 screen - so the floor is portrait
 * too. A landscape image under this is stretched to cover the height and
 * loses most of its width to the crop. settings.html mirrors these two
 * numbers so the admin hears about it before the upload, not after.
 */
const LOGIN_SLIDE_MIN_WIDTH = 600;
const LOGIN_SLIDE_MIN_HEIGHT = 800;

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

/**
 * The inbox behind notifications.html and admin-notifications.html. One row
 * is one message for one recipient, and `recipient_kind` says which table
 * `recipient_id` points at - the same two-audience split calendar.php already
 * resolves tokens against.
 *
 * Fan-out happens when the event fires, not when the page is read: something
 * aimed at "every admin responsible for this client" becomes one row per
 * admin, so reading is a single indexed lookup and marking one read touches
 * nobody else. api/notify.php does the writing.
 *
 * sql/notifications.sql is the same table to run by hand.
 */
function ensureNotificationsTable(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
              id             INT AUTO_INCREMENT PRIMARY KEY,
              recipient_kind VARCHAR(10)  NOT NULL,
              recipient_id   INT          NOT NULL,
              event_type     VARCHAR(40)  NOT NULL,
              title          VARCHAR(200) NOT NULL,
              body           VARCHAR(500)     NULL,
              link           VARCHAR(255)     NULL,
              actor_kind     VARCHAR(10)      NULL,
              actor_id       INT              NULL,
              read_at        DATETIME         NULL,
              announcement_id INT             NULL,
              created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_inbox (recipient_kind, recipient_id, id),
              KEY idx_unread (recipient_kind, recipient_id, read_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Read-only DB user: notify() stays silent and the sidebar badge never
        // appears. Nothing else on any page depends on it.
    }
}

/**
 * The announcements the owner writes by hand, plus the notifications column
 * that points back at them.
 *
 * The CREATE above only fires on a fresh install, so an existing database
 * needs the column added separately - same information_schema check
 * ensureContentColumns() uses. Callers get both from this one function.
 *
 * sql/announcements.sql is the same table to run by hand.
 */
function ensureAnnouncementsTable(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensureNotificationsTable($pdo);

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS announcements (
              id                  INT AUTO_INCREMENT PRIMARY KEY,
              audience            VARCHAR(10)  NOT NULL,
              title               VARCHAR(200) NOT NULL,
              body                TEXT             NULL,
              event_date          DATE             NULL,
              image_path          VARCHAR(255)     NULL,
              created_by_admin_id INT              NULL,
              created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $pdo->query("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
        ");
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array("announcement_id", $existing, true)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN announcement_id INT NULL");
        }
    } catch (PDOException $e) {
        // Read-only DB user: announcements cannot be written and the reader
        // below treats every row as an ordinary notification.
    }
}

/**
 * Project Management: projects, the task updates published against them, and
 * the admins working on them. sql/projects.sql is the same three tables to run
 * by hand, and carries the long explanation of why `status` and the stat-card
 * buckets are separate things.
 *
 * All three ship together because none is useful alone, so one ensure covers
 * the lot - unlike the appointment/notification pair, which arrived separately.
 */

const PROJECT_EXTRA_COLUMNS = [
    "image_path" => "VARCHAR(255) NULL",
];

const PROJECT_TASK_EXTRA_COLUMNS = [
    "scheduled_time"     => "TIME NULL",

    /* Stamped the first time a task is announced to the client, by the hook at
       the end of api/project-tasks.php's POST branch. It is what makes that
       notification fire exactly once - comparing the old status with the new
       one would re-announce the task on every later edit. */
    "client_notified_at" => "DATETIME NULL",
];

/*
|--------------------------------------------------------------------------
| The vocabularies, and when a task is live
|--------------------------------------------------------------------------
|
| These three live beside ensureProjectTables() rather than in the endpoint
| that first needed them, because api/projects.php, api/project-tasks.php and
| api/user-projects.php all read them and none can require_once another -
| each runs its own auth block at top level and exits.
|
| A drifted copy of PROJECT_TYPES would be a cosmetic bug. A drifted copy of
| TASK_IS_LIVE_SQL would be a silent disclosure: the client page would start
| showing drafts, with nothing failing.
|
| Both vocabularies are stored as VARCHAR and validated in PHP rather than as
| an ENUM, so adding a type or a phase never needs an ALTER the database user
| may not have. The same precedent as content.content_type. The pages render
| their selects from what the endpoints return, so a new entry here appears
| everywhere at once.
|
*/

const PROJECT_TYPES = [
    "survey"     => "Survey",
    "research"   => "Research",
    "campaign"   => "Campaign",
    "audit"      => "Audit",
    "consulting" => "Consulting",
    "other"      => "Other",
];

/* Only 'completed' changes any arithmetic - the rest are labels. There is
   deliberately no 'on_hold' or 'cancelled': every project must land in exactly
   one of the three stat-card buckets or the four numbers stop adding up. */
const PROJECT_STATUSES = [
    "planning"  => "Planning Phase",
    "active"    => "In Progress",
    "review"    => "In Review",
    "completed" => "Completed",
];

/*
| Nothing runs on a schedule in this codebase, so a future-dated task is not
| moved from scheduled to live by a cron - the read is filtered instead, and
| this is the one predicate that does it.
|
| The admin grid shows every task and needs the answer as a column, so
| api/projects.php and api/project-tasks.php select it AS is_live. The client
| endpoint puts the same expression in its WHERE and never returns it: there,
| a task that is not live simply does not exist.
|
| Assumes the project_tasks table is aliased `t`.
*/

const TASK_IS_LIVE_SQL = "
    (
        t.status = 'published'
        AND (t.scheduled_date IS NULL OR t.scheduled_date <= CURDATE())
    )
";

function ensureProjectTables(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS projects (
              id                       INT AUTO_INCREMENT PRIMARY KEY,
              client_id                INT              NOT NULL,
              title                    VARCHAR(200)     NOT NULL,
              description              TEXT                 NULL,
              project_type             VARCHAR(60)      NOT NULL DEFAULT 'survey',
              start_date               DATE             NOT NULL,
              end_date                 DATE             NOT NULL,
              progress                 TINYINT UNSIGNED NOT NULL DEFAULT 0,
              status                   VARCHAR(20)      NOT NULL DEFAULT 'planning',
              image_path               VARCHAR(255)         NULL,
              account_manager_admin_id INT                  NULL,
              created_by_admin_id      INT                  NULL,
              created_at               TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at               TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                             ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_client       (client_id),
              KEY idx_manager      (account_manager_admin_id),
              KEY idx_status_dates (status, start_date),
              KEY idx_type         (project_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS project_tasks (
              id                  INT AUTO_INCREMENT PRIMARY KEY,
              project_id          INT          NOT NULL,
              title               VARCHAR(200) NOT NULL,
              link                VARCHAR(500)     NULL,
              description         TEXT             NULL,
              orientation         ENUM('horizontal','vertical') NOT NULL DEFAULT 'horizontal',
              image_path          VARCHAR(255)     NULL,
              scheduled_date      DATE             NULL,
              scheduled_time      TIME             NULL,
              is_complete         TINYINT(1)   NOT NULL DEFAULT 0,
              status              VARCHAR(20)  NOT NULL DEFAULT 'draft',
              created_by_admin_id INT              NULL,
              created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                    ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_project          (project_id, id),
              KEY idx_project_schedule (project_id, scheduled_date),
              KEY idx_author           (created_by_admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS project_members (
              id         INT AUTO_INCREMENT PRIMARY KEY,
              project_id INT       NOT NULL,
              admin_id   INT       NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_member (project_id, admin_id),
              KEY idx_admin (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Columns added after a database was first built from an early draft.
        $tables = [
            "projects"      => PROJECT_EXTRA_COLUMNS,
            "project_tasks" => PROJECT_TASK_EXTRA_COLUMNS,
        ];

        foreach ($tables as $table => $columns) {

            $stmt = $pdo->prepare("
                SELECT COLUMN_NAME
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);
            $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($columns as $column => $type) {
                if (!in_array($column, $existing, true)) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $type");
                }
            }
        }
    } catch (PDOException $e) {
        // Read-only DB user: the endpoints below fail on the first query with
        // their own message rather than dying here.
    }
}
