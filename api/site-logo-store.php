<?php

/*
|--------------------------------------------------------------------------
| The portal's logo, kept in the database
|--------------------------------------------------------------------------
|
| A deploy builds a new container from git, and the logo is not in git, so a
| file on the container's disk is gone after every deploy. The database is the
| one place this install already keeps across them.
|
| Every page still loads logo.png: docker/site-files.conf maps that path to
| api/site-logo.php, which serves what is stored here. Shared by the Settings
| upload (api/upload-logo.php) and the assistant's set_logo.
|
| A logo.png left on disk from before this is only read while nothing has been
| uploaded, so an install keeps its logo until the next upload replaces it.
|
| Added lazily; sql/site_logo.sql is the same table to run by hand.
|
*/

const SITE_LOGO_MAX_BYTES = 3 * 1024 * 1024;

function ensureSiteLogoTable(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS site_logo (
              id         TINYINT      NOT NULL PRIMARY KEY,
              mime       VARCHAR(40)  NOT NULL,
              version    CHAR(40)     NOT NULL,
              data       MEDIUMBLOB   NOT NULL,
              updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Read-only DB user: saving reports the failure, and reading falls
        // back to the file until sql/site_logo.sql is run by hand.
    }
}

function siteLogoLegacyPath()
{
    return dirname(__DIR__) . "/logo.png";
}

/**
 * What the bytes really are, or null if they aren't a logo we can show. The
 * file's name says nothing: browsers trust the type this decides, so a wrong
 * answer is a broken image on every page.
 */
function siteLogoMime($bytes)
{
    $size = @getimagesizefromstring($bytes);
    if ($size && in_array($size["mime"] ?? "", ["image/png", "image/jpeg"], true)) {
        return $size["mime"];
    }

    $head = substr($bytes, 0, 4096);
    $head = preg_replace('/^\xEF\xBB\xBF/', "", $head);
    if (preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $head)) {
        return "image/svg+xml";
    }

    return null;
}

/** Replaces the logo. Throws PDOException if it can't be saved. */
function saveSiteLogo(PDO $pdo, $bytes, $mime)
{
    ensureSiteLogoTable($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO site_logo (id, mime, version, data, updated_at)
        VALUES (1, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
          mime = VALUES(mime), version = VALUES(version), data = VALUES(data), updated_at = VALUES(updated_at)
    ");
    $stmt->bindValue(1, $mime);
    $stmt->bindValue(2, sha1($bytes));
    $stmt->bindValue(3, $bytes, PDO::PARAM_LOB);
    $stmt->execute();
}

/** ["mime" => ..., "version" => ...] without loading the image, or null. */
function siteLogoInfo(PDO $pdo)
{
    ensureSiteLogoTable($pdo);

    try {
        $row = $pdo->query("SELECT mime, version FROM site_logo WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    } catch (PDOException $e) {
        // No table yet: only a file can be the logo.
    }

    $file = siteLogoLegacyPath();
    if (is_file($file)) {
        $mime = siteLogoMime((string) file_get_contents($file));
        if ($mime) {
            return ["mime" => $mime, "version" => sha1_file($file)];
        }
    }

    return null;
}

/** The image itself, or null. */
function siteLogoBytes(PDO $pdo)
{
    ensureSiteLogoTable($pdo);

    try {
        $bytes = $pdo->query("SELECT data FROM site_logo WHERE id = 1")->fetchColumn();
        if ($bytes !== false) {
            return $bytes;
        }
    } catch (PDOException $e) {
        // As in siteLogoInfo().
    }

    $file = siteLogoLegacyPath();
    return is_file($file) ? (string) file_get_contents($file) : null;
}

/** The logo's address with its version, so a new one shows at once, or null. */
function siteLogoUrl(PDO $pdo, $base)
{
    $info = siteLogoInfo($pdo);
    return $info ? $base . "/logo.png?v=" . substr($info["version"], 0, 12) : null;
}
