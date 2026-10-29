<?php

/*
|--------------------------------------------------------------------------
| Settings: the owner's changes to the portal and its admin team
|--------------------------------------------------------------------------
|
| Shared by the settings endpoints (settings.php, admin-role.php,
| admin-manager-assignments.php, add-user.php, admin-login-slides.php) and the
| AI-facing api/v1, so an assistant changing a setting is held to exactly the
| rules the Settings page follows.
|
| Everything here is the owner's alone - the callers check that first. Each
| check*() function reads and validates without writing, throwing
| PortalWriteError with the reason; the matching write runs only after it.
|
*/

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/mailer.php";
require_once __DIR__ . "/events.php";

// The roles an owner hands out. `owner` is not one of them: it is never given
// from a dropdown, and an owner row is never demoted (see admin-role.php).
const ASSIGNABLE_ADMIN_ROLES = ["seo_admin", "super_admin", "account_manager"];

// How long the "set your password" link in a new admin's welcome email lasts.
const NEW_ADMIN_LINK_TTL = 72 * 3600;

/* The owner is shown as the Director everywhere; the role key stays `owner`. */
function adminRoleName($role)
{
    switch ($role) {
        case "owner": return "Director";
        case "super_admin": return "Super Admin";
        case "account_manager": return "Account Manager";
        case "seo_admin": return "Admin";
        default: return "";
    }
}

/** "Sara Ali (sara@example.com)" - how an admin is named in a summary. */
function adminLabel(array $row)
{
    return trim((string) $row["name"]) . " (" . $row["email"] . ")";
}

function findAdminRow(PDO $pdo, $id)
{
    ensureAdminColumns($pdo);

    $stmt = $pdo->prepare("
        SELECT id, name, email, role, active, managed_by_admin_id, position
        FROM admins
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new PortalWriteError("Admin not found.", 404);
    }

    return $row;
}

/*
|--------------------------------------------------------------------------
| Website address
|--------------------------------------------------------------------------
*/

/** The address as it will be stored: as typed, "" to clear it. */
function checkWebsiteAddress($website)
{
    $website = trim((string) $website);

    if ($website !== "" && normaliseWebUrl($website) === "") {
        throw new PortalWriteError("That does not look like a web address. Try something like wzone.london");
    }

    return $website;
}

function saveWebsiteAddress(PDO $pdo, $website)
{
    // The reason travels with the refusal: the usual one is a database user
    // that may not CREATE the site_settings table.
    $error = "";

    if (!setSiteSetting($pdo, "website_url", $website, $error)) {
        throw new PortalWriteError($error === ""
            ? "Could not save the website address"
            : "Could not save the website address: " . $error, 500);
    }

    logEvent($pdo, EVENT_SETTINGS_CHANGED, "settings", 0, "Website address", "Changed the website address to " . $website);
}

/*
|--------------------------------------------------------------------------
| Logo
|--------------------------------------------------------------------------
|
| The page uploads logo.png at the site root; an assistant gives a link
| (content-image-fetch.php downloads and checks it). PNG or JPG only - the
| file is served as logo.png to every public page.
|
*/

function siteLogoPath()
{
    return dirname(__DIR__) . "/logo.png";
}

function fetchSiteLogo($url)
{
    require_once __DIR__ . "/content-image-fetch.php";

    [$tmp, $extension] = downloadContentImage(0, (string) $url);

    try {
        if (!in_array($extension, ["png", "jpg"], true)) {
            throw new PortalWriteError("The logo must be a PNG or JPG image.");
        }

        if (!rename($tmp, siteLogoPath())) {
            throw new PortalWriteError("Failed to save the logo. Check folder permissions.", 500);
        }

        @chmod(siteLogoPath(), 0644);

        global $pdo;
        logEvent($pdo, EVENT_SETTINGS_CHANGED, "settings", 0, "Logo", "Replaced the portal logo");
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Login page slides
|--------------------------------------------------------------------------
*/

function loginSlidesDir()
{
    return dirname(__DIR__) . "/uploads/login-slides/";
}

function deleteSlideImage($relativePath)
{
    if (!$relativePath) {
        return;
    }

    $fullPath = dirname(__DIR__) . "/" . ltrim($relativePath, "/");

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function loginSlideTitle($title)
{
    return mb_substr(trim((string) $title), 0, 120);
}

function loginSlideSubtitle($subtitle)
{
    return mb_substr(trim((string) $subtitle), 0, 255);
}

function listLoginSlides(PDO $pdo)
{
    ensureLoginSlidesTable($pdo);

    $slides = $pdo->query("
        SELECT id, image_path, title, subtitle, position, created_at
        FROM login_slides
        ORDER BY position ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($slides as &$slide) {
        $slide["id"] = (int) $slide["id"];
        $slide["position"] = (int) $slide["position"];
    }
    unset($slide);

    return $slides;
}

function findLoginSlide(PDO $pdo, $id)
{
    ensureLoginSlidesTable($pdo);

    $stmt = $pdo->prepare("SELECT id, image_path, title, subtitle, position FROM login_slides WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $id]);
    $slide = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slide) {
        throw new PortalWriteError("Slide not found.", 404);
    }

    return $slide;
}

function checkLoginSlideRoom(PDO $pdo)
{
    ensureLoginSlidesTable($pdo);

    if ((int) $pdo->query("SELECT COUNT(*) FROM login_slides")->fetchColumn() >= LOGIN_SLIDES_MAX) {
        throw new PortalWriteError("You already have " . LOGIN_SLIDES_MAX . " slides. Remove one first.", 409);
    }
}

/**
 * Checks a slide image already on disk (an upload's tmp file or a download)
 * and returns its extension. Same floor as settings.html: the slideshow fills
 * half a desktop screen.
 */
function checkLoginSlideImage($path)
{
    $extension = contentImageExtension($path);

    if ($extension === null) {
        throw new PortalWriteError("Only JPG, PNG, WEBP and GIF images are allowed.");
    }

    $size = getimagesize($path);

    if (!$size) {
        throw new PortalWriteError("That image could not be read.");
    }

    if ($size[0] < LOGIN_SLIDE_MIN_WIDTH || $size[1] < LOGIN_SLIDE_MIN_HEIGHT) {
        throw new PortalWriteError("Image is " . $size[0] . " × " . $size[1] . " px. The minimum is "
            . LOGIN_SLIDE_MIN_WIDTH . " × " . LOGIN_SLIDE_MIN_HEIGHT . " px.");
    }

    return $extension;
}

/** Downloads a slide image from a link into uploads/login-slides/. */
function fetchLoginSlideImage($url)
{
    require_once __DIR__ . "/content-image-fetch.php";

    [$tmp, $extension] = downloadContentImage(0, (string) $url);

    try {
        checkLoginSlideImage($tmp);

        $dir = loginSlidesDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new PortalWriteError("Could not create upload directory.", 500);
        }

        $name = bin2hex(random_bytes(16)) . "." . $extension;

        if (!rename($tmp, $dir . $name)) {
            throw new PortalWriteError("Could not save image.", 500);
        }

        @chmod($dir . $name, 0644);

        return "uploads/login-slides/" . $name;
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

/**
 * Adds a slide whose image is already saved. The count is checked again under
 * a lock; on any refusal the image is removed so no file is left orphaned.
 */
function addLoginSlide(PDO $pdo, $imagePath, $title, $subtitle, $adminId)
{
    try {
        $pdo->beginTransaction();

        $count = (int) $pdo->query("SELECT COUNT(*) FROM login_slides FOR UPDATE")->fetchColumn();

        if ($count >= LOGIN_SLIDES_MAX) {
            throw new PortalWriteError("You already have " . LOGIN_SLIDES_MAX . " slides. Remove one first.", 409);
        }

        $position = (int) $pdo
            ->query("SELECT COALESCE(MAX(position), -1) + 1 FROM login_slides")
            ->fetchColumn();

        $pdo->prepare("
            INSERT INTO login_slides (image_path, title, subtitle, position, created_by)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $imagePath,
            $title !== "" ? $title : null,
            $subtitle !== "" ? $subtitle : null,
            $position,
            $adminId,
        ]);

        $id = (int) $pdo->lastInsertId();
        logEvent($pdo, EVENT_SETTINGS_CHANGED, "login_slide", $id, $title,
            "Added a login page slide" . ($title !== "" ? " \"$title\"" : ""));
        $pdo->commit();

        return ["id" => $id, "image_path" => $imagePath, "position" => $position];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        deleteSlideImage($imagePath);

        throw $e instanceof PortalWriteError ? $e : new PortalWriteError("Could not add the slide.", 500);
    }
}

function updateLoginSlideText(PDO $pdo, $id, $title, $subtitle)
{
    findLoginSlide($pdo, $id);

    $pdo->prepare("UPDATE login_slides SET title = ?, subtitle = ? WHERE id = ?")->execute([
        $title !== "" ? $title : null,
        $subtitle !== "" ? $subtitle : null,
        (int) $id,
    ]);

    logEvent($pdo, EVENT_SETTINGS_CHANGED, "login_slide", $id, $title, "Edited the text of a login page slide");
}

/**
 * The order must be exactly the ids we hold. Anything else means the caller's
 * view is stale - a slide was added or removed - and applying it would drop a
 * slide to position 0 rather than say so.
 */
function checkLoginSlideOrder(PDO $pdo, $order)
{
    $stale = new PortalWriteError("Slide order is out of date. Reload the page.");

    if (!is_array($order) || !$order || count($order) > LOGIN_SLIDES_MAX) {
        throw $stale;
    }

    $ids = [];

    foreach ($order as $value) {
        if (!is_int($value) && !ctype_digit((string) $value)) {
            throw $stale;
        }
        $id = (int) $value;
        if ($id < 1 || in_array($id, $ids, true)) {
            throw $stale;
        }
        $ids[] = $id;
    }

    $stored = array_column(listLoginSlides($pdo), "id");
    $sortedSent = $ids;
    sort($stored);
    sort($sortedSent);

    if ($stored !== $sortedSent) {
        throw $stale;
    }

    return $ids;
}

function reorderLoginSlides(PDO $pdo, array $ids)
{
    try {
        $pdo->beginTransaction();

        $stored = array_map("intval", $pdo->query("SELECT id FROM login_slides FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN));
        $sortedSent = $ids;
        sort($stored);
        sort($sortedSent);

        if ($stored !== $sortedSent) {
            throw new PortalWriteError("Slide order is out of date. Reload the page.");
        }

        $stmt = $pdo->prepare("UPDATE login_slides SET position = ? WHERE id = ?");
        foreach ($ids as $index => $id) {
            $stmt->execute([$index, $id]);
        }

        logEvent($pdo, EVENT_SETTINGS_CHANGED, "login_slide", 0, "", "Reordered the login page slides");
        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e instanceof PortalWriteError ? $e : new PortalWriteError("Could not save the slide order.", 500);
    }
}

/** Removes a slide and its image, closing the gap so positions stay 0..n-1. */
function removeLoginSlide(PDO $pdo, $id)
{
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, image_path FROM login_slides WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([(int) $id]);
        $slide = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$slide) {
            throw new PortalWriteError("Slide not found.", 404);
        }

        $pdo->prepare("DELETE FROM login_slides WHERE id = ?")->execute([(int) $id]);

        $remaining = $pdo
            ->query("SELECT id FROM login_slides ORDER BY position ASC, id ASC")
            ->fetchAll(PDO::FETCH_COLUMN);

        $update = $pdo->prepare("UPDATE login_slides SET position = ? WHERE id = ?");
        foreach ($remaining as $index => $remainingId) {
            $update->execute([$index, (int) $remainingId]);
        }

        logEvent($pdo, EVENT_SETTINGS_CHANGED, "login_slide", $id, "", "Removed a login page slide");
        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e instanceof PortalWriteError ? $e : new PortalWriteError("Could not remove the slide.", 500);
    }

    deleteSlideImage($slide["image_path"]);
}

/*
|--------------------------------------------------------------------------
| The admin team
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Photos, and the Director's own card
|--------------------------------------------------------------------------
|
| avatar_path is set by the owner when adding or editing an admin (add-user.php,
| admin-update.php) and shown in the list and the sidebar. The Director edits
| their own name and position on the card at the top of Settings
| (update-my-profile.php). Both columns are ADMIN_EXTRA_COLUMNS in db.php.
|
*/

const ADMIN_AVATAR_MAX_BYTES = 3 * 1024 * 1024;

/**
 * Saves an uploaded photo ($_FILES entry) under uploads/avatars/ and returns
 * its path, or null when no file was sent. The type is read from the bytes.
 */
function saveAdminAvatar($file)
{
    if (!is_array($file) || ($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $error = $file["error"];
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new PortalWriteError("Photo is too large for the server (upload_max_filesize is "
            . ini_get("upload_max_filesize") . ").");
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file["tmp_name"] ?? "")) {
        throw new PortalWriteError("Photo upload failed.");
    }
    if (($file["size"] ?? 0) > ADMIN_AVATAR_MAX_BYTES) {
        throw new PortalWriteError("Photo must be 3 MB or smaller.");
    }

    $ext = contentImageExtension($file["tmp_name"]);
    if ($ext === null || $ext === "gif") {
        throw new PortalWriteError("Only JPG, PNG and WEBP photos are allowed.");
    }

    $dir = dirname(__DIR__) . "/uploads/avatars/";
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new PortalWriteError("Could not create the photo folder.", 500);
    }

    $name = bin2hex(random_bytes(16)) . "." . $ext;
    if (!move_uploaded_file($file["tmp_name"], $dir . $name)) {
        throw new PortalWriteError("Could not save the photo.", 500);
    }

    return "uploads/avatars/" . $name;
}

function deleteAdminAvatar($path)
{
    $path = (string) $path;
    if (strpos($path, "uploads/avatars/") === 0 && strpos($path, "..") === false) {
        $full = dirname(__DIR__) . "/" . $path;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

/**
 * Sets an admin's photo: a saved path, or "" to remove it. Returns the photo
 * it replaced, for deleteAdminAvatar() once the change has landed.
 */
function setAdminAvatar(PDO $pdo, $adminId, $avatar)
{
    ensureAdminColumns($pdo);

    $stmt = $pdo->prepare("SELECT avatar_path FROM admins WHERE id = ?");
    $stmt->execute([(int) $adminId]);
    $old = $stmt->fetchColumn() ?: null;

    $pdo->prepare("UPDATE admins SET avatar_path = ? WHERE id = ?")
        ->execute([$avatar !== "" ? $avatar : null, (int) $adminId]);

    $name = eventAdminName($pdo, $adminId);
    logEvent($pdo, EVENT_ADMIN_UPDATED, "admin", $adminId, $name,
        ($avatar !== "" ? "Changed the photo of " : "Removed the photo of ") . $name);

    return $old;
}

/** The Director's card on Settings: name, email, role, position, photo. */
function adminProfile(PDO $pdo, $adminId)
{
    ensureAdminColumns($pdo);

    $stmt = $pdo->prepare("SELECT id, name, email, role, position, avatar_path FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new PortalWriteError("Admin not found.", 404);
    }

    return [
        "id"          => (int) $row["id"],
        "name"        => (string) $row["name"],
        "email"       => (string) $row["email"],
        "role"        => (string) $row["role"],
        "role_label"  => adminRoleName($row["role"]),
        "position"    => (string) ($row["position"] ?? ""),
        "avatar_path" => $row["avatar_path"] ?: null,
    ];
}

/**
 * The Director changes their own name and position - nothing else: the
 * email is what they sign in with, and the role is fixed. Returns
 * [name, position] cleaned.
 */
function checkOwnProfile($name, $position)
{
    $name = trim((string) $name);

    if ($name === "") {
        throw new PortalWriteError("Name is required.");
    }
    if (mb_strlen($name) > 100) {
        throw new PortalWriteError("Name must be 100 characters or fewer.");
    }

    return [$name, checkAdminPosition($position)];
}

function updateOwnProfile(PDO $pdo, $adminId, $name, $position)
{
    ensureAdminColumns($pdo);

    $pdo->prepare("UPDATE admins SET name = ?, position = ? WHERE id = ?")
        ->execute([$name, $position, (int) $adminId]);

    logEvent($pdo, EVENT_ADMIN_UPDATED, "admin", $adminId, $name, "Updated their own name and position");
}

/** Every admin but the owner, with who manages them - the Available Admins panel. */
function listTeamAdmins(PDO $pdo)
{
    ensureAdminColumns($pdo);

    return $pdo->query("
        SELECT
            admins.id, admins.name, admins.email, admins.role, admins.active,
            admins.managed_by_admin_id, admins.position, admins.avatar_path,
            manager.name AS managed_by_name
        FROM admins
        LEFT JOIN admins manager ON manager.id = admins.managed_by_admin_id
        WHERE admins.role IN ('seo_admin', 'super_admin', 'account_manager')
        ORDER BY admins.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** Returns the admin whose role changes. */
function checkAdminRoleChange(PDO $pdo, array $owner, $targetId, $newRole)
{
    if (!in_array($newRole, ASSIGNABLE_ADMIN_ROLES, true)) {
        throw new PortalWriteError("Role must be one of: " . implode(", ", ASSIGNABLE_ADMIN_ROLES) . ".");
    }

    $target = findAdminRow($pdo, $targetId);

    if ($target["role"] === "owner") {
        throw new PortalWriteError("The Director's role cannot be changed here.", 403);
    }

    if ((int) $target["id"] === (int) $owner["id"]) {
        throw new PortalWriteError("You cannot change your own role.", 403);
    }

    return $target;
}

/**
 * The session is ended with the role: every page reads the role saved in the
 * browser at sign-in, so someone logged in would otherwise keep their old
 * permissions until they next signed in.
 */
function changeAdminRole(PDO $pdo, $targetId, $newRole)
{
    $target = findAdminRow($pdo, $targetId);

    $pdo->prepare("UPDATE admins SET role = ?, session_token = NULL WHERE id = ?")
        ->execute([$newRole, (int) $targetId]);

    // Their exceptions were made against the old role; the new one starts clean.
    foreach (array_keys(permissionOverrides($pdo, $targetId)[(int) $targetId] ?? []) as $perm) {
        storeAdminPermission($pdo, $targetId, $perm, null, 0);
    }

    logEvent($pdo, EVENT_ADMIN_ROLE, "admin", $targetId, $target["name"],
        "Changed " . $target["name"] . "'s role from " . adminRoleName($target["role"]) . " to " . adminRoleName($newRole));
}

/**
 * Activating or deactivating blocks login only - nothing about that admin
 * (assignments, forms, review history) is touched, and reactivating restores
 * access exactly as it was. The owner can toggle anyone but themself.
 */
function checkAdminActiveChange(PDO $pdo, array $owner, $targetId)
{
    if ((int) $targetId === (int) $owner["id"]) {
        throw new PortalWriteError("You can't deactivate your own account", 403);
    }

    return findAdminRow($pdo, $targetId);
}

function setAdminActive(PDO $pdo, $targetId, $active)
{
    $pdo->prepare("UPDATE admins SET active = ? WHERE id = ?")->execute([$active ? 1 : 0, (int) $targetId]);

    $name = eventAdminName($pdo, $targetId);
    logEvent($pdo, EVENT_ADMIN_ACTIVE, "admin", $targetId, $name, ($active ? "Reactivated " : "Deactivated ") . $name);
}

/**
 * Who may sit under a manager: plain Admins for anyone, and super admins as
 * well when the manager is an account manager - the pool settings.html
 * offers. Returns [manager row, the given ids that are allowed, allowed roles];
 * anything outside the pool is dropped.
 */
function checkManagerAssignment(PDO $pdo, $managerId, array $adminIds)
{
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $managerId]);
    $manager = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$manager || !in_array($manager["role"], ["super_admin", "account_manager"], true)) {
        throw new PortalWriteError("That manager was not found.", 404);
    }

    $allowedRoles = $manager["role"] === "account_manager" ? ["seo_admin", "super_admin"] : ["seo_admin"];
    $rolePlaceholders = implode(",", array_fill(0, count($allowedRoles), "?"));

    $valid = $pdo->prepare("SELECT id FROM admins WHERE role IN ($rolePlaceholders) AND id <> ?");
    $valid->execute(array_merge($allowedRoles, [(int) $managerId]));
    $validIds = array_map("intval", $valid->fetchAll(PDO::FETCH_COLUMN));

    $adminIds = array_values(array_unique(array_intersect(array_map("intval", $adminIds), $validIds)));

    return [$manager, $adminIds, $allowedRoles];
}

/**
 * Full replace: clears whoever this manager has now, then sets exactly
 * $adminIds. An admin who belonged to another manager moves over - one Admin
 * only ever has one manager.
 */
function assignAdminsToManager(PDO $pdo, $managerId, array $adminIds, array $allowedRoles)
{
    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE admins SET managed_by_admin_id = NULL WHERE managed_by_admin_id = ?")
            ->execute([(int) $managerId]);

        if ($adminIds) {
            $placeholders = implode(",", array_fill(0, count($adminIds), "?"));
            $rolePlaceholders = implode(",", array_fill(0, count($allowedRoles), "?"));

            $pdo->prepare("
                UPDATE admins
                SET managed_by_admin_id = ?
                WHERE id IN ($placeholders) AND role IN ($rolePlaceholders)
            ")->execute(array_merge([(int) $managerId], $adminIds, $allowedRoles));
        }

        $manager = eventAdminName($pdo, $managerId);
        $names = array_map(function ($id) use ($pdo) {
            return eventAdminName($pdo, $id);
        }, $adminIds);
        logEvent($pdo, EVENT_ADMIN_MANAGER, "admin", $managerId, $manager, $names
            ? "Set the admins under " . $manager . ": " . implode(", ", $names)
            : "Removed every admin from under " . $manager);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new PortalWriteError("Failed to save assignments", 500);
    }
}

/** Returns [name, email] cleaned. */
function checkNewAdmin(PDO $pdo, $name, $email, $role)
{
    $name = trim((string) $name);
    $email = trim((string) $email);

    if ($name === "" || $email === "") {
        throw new PortalWriteError("Name and email are required");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new PortalWriteError("That email address is not valid.");
    }

    if (!in_array($role, ASSIGNABLE_ADMIN_ROLES, true)) {
        throw new PortalWriteError("Role must be one of: " . implode(", ", ASSIGNABLE_ADMIN_ROLES) . ".");
    }

    $taken = $pdo->prepare("SELECT 1 FROM admins WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $taken->execute([$email]);

    if ($taken->fetchColumn()) {
        throw new PortalWriteError("There is already an admin with that email.", 409);
    }

    return [$name, $email];
}

/**
 * An admin's position, trimmed: what the sidebar and the popup show in place
 * of their name. Required when adding someone; at most 100 characters.
 */
function checkAdminPosition($position)
{
    $position = trim(preg_replace('/\s+/', " ", (string) $position));

    if ($position === "") {
        throw new PortalWriteError("Position is required, e.g. SEO Specialist.");
    }

    if (mb_strlen($position) > 100) {
        throw new PortalWriteError("Position must be 100 characters or fewer.");
    }

    return $position;
}

/**
 * $password is the plain password, or null for one nobody knows; $avatar a
 * saved photo path, or null. Returns the id.
 */
function createAdmin(PDO $pdo, $name, $email, $role, $password = null, $position = null, $avatar = null)
{
    ensureAdminColumns($pdo);

    $hash = password_hash($password ?? bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $pdo->prepare("INSERT INTO admins (name, email, password, role, active, position, avatar_path) VALUES (?, ?, ?, ?, 1, ?, ?)")
        ->execute([$name, $email, $hash, $role, $position, $avatar]);

    $id = (int) $pdo->lastInsertId();
    logEvent($pdo, EVENT_ADMIN_CREATED, "admin", $id, $name, "Added " . $name . " (" . $email . ") as " . adminRoleName($role));

    return $id;
}

/**
 * What the Edit popup on Settings changes about an admin: name, email,
 * position and role. Returns [$target, $clean] - the row as it is now and the
 * four values checked and tidied - or throws.
 *
 * The owner's row is never edited here: it is not in the list, and an owner
 * row that could be demoted or emptied of its email is the lockout
 * admin-role.php guards against. The rules for a role are checkAdminRoleChange()'s.
 */
function checkAdminUpdate(PDO $pdo, array $owner, $targetId, array $in)
{
    $target = findAdminRow($pdo, $targetId);

    if ($target["role"] === "owner") {
        throw new PortalWriteError("The Director's account cannot be edited here.", 403);
    }

    $name = trim((string) ($in["name"] ?? ""));
    $email = trim((string) ($in["email"] ?? ""));
    $role = trim((string) ($in["role"] ?? ""));

    if ($name === "" || $email === "") {
        throw new PortalWriteError("Name and email are required");
    }

    if (mb_strlen($name) > 100) {
        throw new PortalWriteError("Name must be 100 characters or fewer.");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new PortalWriteError("That email address is not valid.");
    }

    $taken = $pdo->prepare("SELECT 1 FROM admins WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1");
    $taken->execute([$email, (int) $target["id"]]);

    if ($taken->fetchColumn()) {
        throw new PortalWriteError("There is already an admin with that email.", 409);
    }

    $position = checkAdminPosition($in["position"] ?? "");

    if ($role !== $target["role"]) {
        checkAdminRoleChange($pdo, $owner, $targetId, $role);
    }

    return [$target, ["name" => $name, "email" => $email, "position" => $position, "role" => $role]];
}

/**
 * Saves what checkAdminUpdate() returned. A changed role signs them out
 * (changeAdminRole()); the rest is only labels and contact details. Returns
 * whether they were signed out.
 */
function updateAdmin(PDO $pdo, array $target, array $clean)
{
    ensureAdminColumns($pdo);

    $pdo->prepare("UPDATE admins SET name = ?, email = ?, position = ? WHERE id = ?")
        ->execute([$clean["name"], $clean["email"], $clean["position"], (int) $target["id"]]);

    $changed = [];
    foreach (["name", "email", "position"] as $field) {
        if ((string) ($target[$field] ?? "") !== (string) $clean[$field]) {
            $changed[] = $field;
        }
    }
    if ($changed) {
        logEvent($pdo, EVENT_ADMIN_UPDATED, "admin", $target["id"], $clean["name"],
            "Edited " . $target["name"] . "'s " . implode(", ", $changed));
    }

    if ($clean["role"] === $target["role"]) {
        return false;
    }

    changeAdminRole($pdo, $target["id"], $clean["role"]);

    return true;
}

/** Sets an existing admin's position. Nobody is signed out - it is only a label. */
function setAdminPosition(PDO $pdo, $adminId, $position)
{
    ensureAdminColumns($pdo);

    $position = checkAdminPosition($position);
    $pdo->prepare("UPDATE admins SET position = ? WHERE id = ?")
        ->execute([$position, (int) $adminId]);

    $name = eventAdminName($pdo, $adminId);
    logEvent($pdo, EVENT_ADMIN_POSITION, "admin", $adminId, $name, "Set " . $name . "'s position to " . $position);
}

/**
 * Emails a new admin a link to choose their password - the same reset token
 * reset-password.html takes, just longer-lived. Returns whether it was sent.
 */
function emailNewAdminWelcome(PDO $pdo, $adminId, $name, $email, $role, $ownerName)
{
    $token = bin2hex(random_bytes(32));

    $pdo->prepare("UPDATE admins SET reset_token = ?, reset_expires = ? WHERE id = ?")
        ->execute([$token, date("Y-m-d H:i:s", time() + NEW_ADMIN_LINK_TTL), (int) $adminId]);

    $html = adminEmailHtml(
        "You've been added to the W Zone portal",
        trim((string) $ownerName) . " added you as " . adminRoleName($role)
            . ". Choose a password to sign in. The link works for 3 days.",
        ["Name" => $name, "Email" => $email, "Role" => adminRoleName($role)],
        "reset-password.html?token=" . $token,
        "Choose your password"
    );

    return sendPortalEmail([["email" => $email, "name" => $name]], "Your W Zone portal account", $html) > 0;
}

/*
|--------------------------------------------------------------------------
| Permissions (Settings > Permissions)
|--------------------------------------------------------------------------
|
| What each role may do, and one admin's exceptions to their role - the keys
| in api/permissions.php. $changes is permission => true (allow), false
| (deny) or null (back to the default, or for an admin back to their role).
| A change applies on the person's next request; nobody is signed out.
|
*/

/** Returns $changes cleaned up, or throws with the first thing wrong. */
function checkPermissionChanges($changes)
{
    if (!is_array($changes) || $changes === []) {
        throw new PortalWriteError("Choose at least one permission to change.");
    }

    $clean = [];
    foreach ($changes as $perm => $value) {
        if (!isPermission($perm)) {
            throw new PortalWriteError("Unknown permission: " . mb_substr((string) $perm, 0, 64) . ".");
        }
        if (isOwnerOnlyPermission($perm)) {
            throw new PortalWriteError("“" . PERMISSIONS[$perm]["label"] . "” stays with the Director.", 403);
        }
        if ($value === "default") {
            $value = null;
        }
        if ($value !== null && !is_bool($value)) {
            throw new PortalWriteError("Each permission is true, false or \"default\".");
        }
        $clean[$perm] = $value;
    }
    return $clean;
}

function checkRolePermissionChange($role, $changes)
{
    if (!in_array($role, PERMISSION_ROLES, true)) {
        throw new PortalWriteError("Role must be one of: " . implode(", ", PERMISSION_ROLES) . ".");
    }
    return checkPermissionChanges($changes);
}

/** Returns [the admin, $changes cleaned up]. */
function checkAdminPermissionChange(PDO $pdo, $adminId, $changes)
{
    $target = findAdminRow($pdo, $adminId);

    if ($target["role"] === "owner") {
        throw new PortalWriteError("The Director holds every permission.", 403);
    }
    if (!in_array($target["role"], PERMISSION_ROLES, true)) {
        throw new PortalWriteError("This admin has no role to set permissions for.", 422);
    }

    return [$target, checkPermissionChanges($changes)];
}

/** "Can now create posts; can no longer publish; back to the default for ..." */
function permissionChangeSummary(array $changes, $defaultWord)
{
    $parts = [];
    foreach ($changes as $perm => $value) {
        $label = lcfirst(PERMISSIONS[$perm]["label"]);
        $parts[] = $value === null ? "back to $defaultWord for: $label"
            : ($value ? "can now: $label" : "can no longer: $label");
    }
    return implode("; ", $parts);
}

function setRolePermissions(PDO $pdo, array $by, $role, array $changes)
{
    foreach ($changes as $perm => $value) {
        storeRolePermission($pdo, $role, $perm, $value, $by["id"]);
    }

    $who = adminRoleName($role) . "s";
    logEvent($pdo, EVENT_ROLE_PERMISSIONS, "admin", 0, $who,
        $who . " " . permissionChangeSummary($changes, "the default"));
}

function setAdminPermissions(PDO $pdo, array $by, array $target, array $changes)
{
    foreach ($changes as $perm => $value) {
        storeAdminPermission($pdo, $target["id"], $perm, $value, $by["id"]);
    }

    logEvent($pdo, EVENT_ADMIN_PERMISSIONS, "admin", $target["id"], $target["name"],
        $target["name"] . " " . permissionChangeSummary($changes, "their role"));
}

/**
 * Everything the Permissions panel draws: the catalog, each role's column,
 * and every admin with their exceptions and what they end up holding.
 */
function permissionsOverview(PDO $pdo)
{
    $overrides = permissionOverrides($pdo);

    return [
        "catalog" => permissionCatalog(),
        "roles" => array_map(function ($role) {
            return ["key" => $role, "name" => adminRoleName($role)];
        }, PERMISSION_ROLES),
        "matrix" => rolePermissionMatrix($pdo),
        "admins" => array_map(function ($a) use ($pdo, $overrides) {
            return [
                "id" => (int) $a["id"],
                "name" => $a["name"],
                "role" => $a["role"],
                "role_name" => adminRoleName($a["role"]),
                "active" => (int) $a["active"] === 1,
                "overrides" => (object) ($overrides[(int) $a["id"]] ?? []),
                "permissions" => effectivePermissions($a, $pdo),
            ];
        }, listTeamAdmins($pdo)),
    ];
}
