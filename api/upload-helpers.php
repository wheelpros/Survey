<?php

/*
|--------------------------------------------------------------------------
| Attachments and phase galleries
|--------------------------------------------------------------------------
|
| Shared by api/project-tasks.php (phases), api/admin-content.php (posts),
| api/attachment.php (the download) and api/v1, so every way in lets in the
| same files under the same caps.
|
| Attachments are staff-only working files - briefs, sheets, documents. They
| never reach a client: the client endpoints do not select them, and the
| download needs an admin session. They are stored under a random name in
| uploads/attachments/, whose .htaccess refuses direct requests, so a file is
| only ever read back through api/attachment.php.
|
| Refusals throw PortalWriteError (db.php); each endpoint turns that into its
| own response.
|
*/

require_once __DIR__ . "/db.php";

const ATTACHMENT_MAX_BYTES = 8 * 1024 * 1024;
const ATTACHMENT_MAX_FILES = 5;

/* What a phase or post may carry: the extension the file is saved under, and
   the types finfo may report for it. Office files are zip or OLE containers,
   so finfo sometimes reports the container instead of the document type -
   those are accepted only together with the matching extension. */
const ATTACHMENT_TYPES = [
    "pdf"  => ["application/pdf"],
    "txt"  => ["text/plain"],
    "md"   => ["text/plain", "text/markdown", "text/x-markdown"],
    "doc"  => ["application/msword", "application/vnd.ms-office", "application/CDFV2", "application/x-ole-storage"],
    "docx" => ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "application/zip", "application/octet-stream"],
    "xls"  => ["application/vnd.ms-excel", "application/vnd.ms-office", "application/CDFV2", "application/x-ole-storage"],
    "xlsx" => ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/zip", "application/octet-stream"],
];

/* The type each one is served back as. */
const ATTACHMENT_SERVE_TYPES = [
    "pdf"  => "application/pdf",
    "txt"  => "text/plain; charset=UTF-8",
    "md"   => "text/markdown; charset=UTF-8",
    "doc"  => "application/msword",
    "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "xls"  => "application/vnd.ms-excel",
    "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
];

/* A phase's gallery, beside its cover: shown on the phase page only. */
const TASK_GALLERY_MAX_IMAGES = 5;

function ensureAttachmentsTable(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS attachments (
              id            INT AUTO_INCREMENT PRIMARY KEY,
              owner_type    VARCHAR(12)  NOT NULL,
              owner_id      INT          NOT NULL,
              path          VARCHAR(255) NOT NULL,
              original_name VARCHAR(255) NOT NULL,
              ext           VARCHAR(8)   NOT NULL,
              size          INT          NOT NULL DEFAULT 0,
              uploaded_by   INT              NULL,
              created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_owner (owner_type, owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Read-only DB user: the first query says so.
    }
}

/** The directory files are saved in, created with its .htaccess on first use. */
function attachmentsDir()
{
    $dir = dirname(__DIR__) . "/uploads/attachments/";

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new PortalWriteError("Could not create the attachments folder.", 500);
    }

    if (!is_file($dir . ".htaccess")) {
        @file_put_contents($dir . ".htaccess", "Require all denied\n");
    }

    return $dir;
}

/**
 * $_FILES[$name] as a plain list of files. A field[] input arrives as one
 * array per key (name[], tmp_name[], ...); a single field as one file.
 * Empty inputs are dropped.
 */
function uploadedFiles($name)
{
    $field = $_FILES[$name] ?? null;

    if (!is_array($field) || !isset($field["error"])) {
        return [];
    }

    if (!is_array($field["error"])) {
        return $field["error"] === UPLOAD_ERR_NO_FILE ? [] : [$field];
    }

    $files = [];
    foreach (array_keys($field["error"]) as $i) {
        if ($field["error"][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            "name"     => $field["name"][$i] ?? "",
            "tmp_name" => $field["tmp_name"][$i] ?? "",
            "error"    => $field["error"][$i],
            "size"     => $field["size"][$i] ?? 0,
        ];
    }

    return $files;
}

/** Throws unless PHP received the file whole. $what names it in the message. */
function checkUploadArrived(array $file, $what)
{
    $error = $file["error"] ?? UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new PortalWriteError("$what is too large for the server (upload_max_filesize is "
            . ini_get("upload_max_filesize") . ").");
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file["tmp_name"] ?? "")) {
        throw new PortalWriteError("$what upload failed.");
    }
}

/**
 * The extension to save an attachment under. The name's extension must be
 * on the list and the bytes must agree with it, so a renamed program is
 * refused.
 */
function checkAttachment(array $file)
{
    checkUploadArrived($file, "File");

    $name = (string) ($file["name"] ?? "");

    if (($file["size"] ?? 0) > ATTACHMENT_MAX_BYTES) {
        throw new PortalWriteError("\"" . $name . "\" is larger than 8 MB.");
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(ATTACHMENT_TYPES[$ext])) {
        throw new PortalWriteError("Only PDF, TXT, MD, Word and Excel files can be attached.");
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
    if (!in_array($mime, ATTACHMENT_TYPES[$ext], true)) {
        throw new PortalWriteError("\"" . $name . "\" is not a real ." . $ext . " file.");
    }

    return $ext;
}

/** A display name without path parts or control characters. */
function cleanAttachmentName($name)
{
    $name = basename(str_replace("\\", "/", (string) $name));
    $name = preg_replace('/[\x00-\x1F\x7F"]/u', "", $name);

    return mb_substr(trim($name), 0, 200) ?: "file";
}

/**
 * The attachments of each owner: owner_id => [{id, name, ext, size,
 * created_at}], oldest first. Owners with none are absent.
 */
function attachmentsFor(PDO $pdo, $ownerType, array $ownerIds)
{
    $ownerIds = array_values(array_unique(array_filter(array_map("intval", $ownerIds))));
    if (!$ownerIds) {
        return [];
    }

    $marks = implode(",", array_fill(0, count($ownerIds), "?"));

    try {
        $stmt = $pdo->prepare("
            SELECT id, owner_id, original_name, ext, size, created_at
            FROM attachments
            WHERE owner_type = ? AND owner_id IN ($marks)
            ORDER BY id ASC
        ");
        $stmt->execute(array_merge([$ownerType], $ownerIds));
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r["owner_id"]][] = [
            "id"         => (int) $r["id"],
            "name"       => (string) $r["original_name"],
            "ext"        => (string) $r["ext"],
            "size"       => (int) $r["size"],
            "created_at" => (string) $r["created_at"],
        ];
    }

    return $out;
}

/**
 * Applies a save's attachment changes to one phase or post:
 *   $keepIds  the ids it keeps (null = keep them all)
 *   $files    new uploads, from uploadedFiles()
 * Every new file is checked, and the total capped, before anything is
 * written or removed. Returns the paths of removed files, to unlink once
 * the caller's own save has succeeded (deleteUploadedFiles()).
 */
function saveAttachments(PDO $pdo, $ownerType, $ownerId, $keepIds, array $files, $adminId)
{
    $current = attachmentsFor($pdo, $ownerType, [$ownerId])[(int) $ownerId] ?? [];
    $currentIds = array_column($current, "id");

    $keep = $keepIds === null
        ? $currentIds
        : array_values(array_intersect($currentIds, array_map("intval", (array) $keepIds)));

    if (count($keep) + count($files) > ATTACHMENT_MAX_FILES) {
        throw new PortalWriteError("Up to " . ATTACHMENT_MAX_FILES . " files can be attached.");
    }

    $exts = array_map("checkAttachment", $files);
    $dir = attachmentsDir();

    $saved = [];
    $insert = $pdo->prepare("
        INSERT INTO attachments (owner_type, owner_id, path, original_name, ext, size, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($files as $i => $file) {
        $filename = bin2hex(random_bytes(16)) . "." . $exts[$i];
        if (!move_uploaded_file($file["tmp_name"], $dir . $filename)) {
            deleteUploadedFiles($saved);
            throw new PortalWriteError("Could not save \"" . cleanAttachmentName($file["name"]) . "\".", 500);
        }
        $path = "uploads/attachments/" . $filename;
        $saved[] = $path;
        $insert->execute([$ownerType, (int) $ownerId, $path, cleanAttachmentName($file["name"]),
            $exts[$i], (int) $file["size"], $adminId ? (int) $adminId : null]);
    }

    $gone = array_values(array_diff($currentIds, $keep));
    return deleteAttachmentRows($pdo, $ownerType, $ownerId, $gone);
}

/**
 * Deletes the given attachment rows of one owner (null = all of them) and
 * returns their paths, for deleteUploadedFiles().
 */
function deleteAttachmentRows(PDO $pdo, $ownerType, $ownerId, $ids = null)
{
    if ($ids !== null && !$ids) {
        return [];
    }

    $sql = "FROM attachments WHERE owner_type = ? AND owner_id = ?";
    $params = [$ownerType, (int) $ownerId];
    if ($ids !== null) {
        $sql .= " AND id IN (" . implode(",", array_fill(0, count($ids), "?")) . ")";
        $params = array_merge($params, array_map("intval", $ids));
    }

    try {
        $stmt = $pdo->prepare("SELECT path " . $sql);
        $stmt->execute($params);
        $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare("DELETE " . $sql)->execute($params);
    } catch (PDOException $e) {
        return [];
    }

    return $paths;
}

/** Unlinks files under uploads/ by their stored relative path. */
function deleteUploadedFiles(array $paths)
{
    foreach ($paths as $path) {
        $path = (string) $path;
        if ($path === "" || strpos($path, "uploads/") !== 0 || strpos($path, "..") !== false) {
            continue;
        }
        $full = dirname(__DIR__) . "/" . $path;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

/*
|--------------------------------------------------------------------------
| A phase's gallery
|--------------------------------------------------------------------------
*/

/** The gallery of a phase row, in order. */
function taskGallery(array $task)
{
    $list = json_decode((string) ($task["gallery_paths"] ?? ""), true);

    return is_array($list) ? array_values(array_filter($list, "is_string")) : [];
}

/**
 * Checks and saves new gallery images (from uploadedFiles()), returning
 * their paths. Every file is checked before any is saved.
 */
function saveTaskGalleryImages(array $files)
{
    $exts = [];
    foreach ($files as $file) {
        checkUploadArrived($file, "Image");
        if (($file["size"] ?? 0) > CONTENT_IMAGE_MAX_BYTES) {
            throw new PortalWriteError("Each image must be 3 MB or smaller.");
        }
        $ext = contentImageExtension($file["tmp_name"]);
        if ($ext === null) {
            throw new PortalWriteError("Only JPG, PNG, WEBP and GIF images are allowed.");
        }
        $exts[] = $ext;
    }

    $dir = dirname(__DIR__) . "/uploads/project-tasks/";
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new PortalWriteError("Could not create upload directory.", 500);
    }

    $saved = [];
    foreach ($files as $i => $file) {
        $filename = bin2hex(random_bytes(16)) . "." . $exts[$i];
        if (!move_uploaded_file($file["tmp_name"], $dir . $filename)) {
            deleteUploadedFiles($saved);
            throw new PortalWriteError("Could not save image.", 500);
        }
        $saved[] = "uploads/project-tasks/" . $filename;
    }

    return $saved;
}
