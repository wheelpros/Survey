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
require_once __DIR__ . "/team-scope.php"; // MANAGER_CAN_MANAGE
require_once __DIR__ . "/mailer.php";
require_once __DIR__ . "/events.php";
require_once __DIR__ . "/site-logo-store.php";

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
        SELECT id, name, email, role, active, managed_by_admin_id, position, department_id
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
        throw new PortalWriteError("That does not look like a web address. Try something like example.com");
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
| Branding
|--------------------------------------------------------------------------
*/

/** The four values as they will be stored, "" clearing each one. */
function checkBrandSettings(array $input)
{
    $brand = [
        "portal_name" => trim((string) ($input["portal_name"] ?? "")),
        "legal_name" => trim((string) ($input["legal_name"] ?? "")),
        "support_email" => strtolower(trim((string) ($input["support_email"] ?? ""))),
        "support_whatsapp" => trim((string) ($input["support_whatsapp"] ?? "")),
    ];

    if (mb_strlen($brand["portal_name"]) > 80 || mb_strlen($brand["legal_name"]) > 120) {
        throw new PortalWriteError("Keep the portal name under 80 characters and the legal name under 120.");
    }

    if ($brand["support_email"] !== "" && !filter_var($brand["support_email"], FILTER_VALIDATE_EMAIL)) {
        throw new PortalWriteError("That does not look like an email address.");
    }

    if ($brand["support_whatsapp"] !== "") {
        $brand["support_whatsapp"] = normaliseWhatsApp($brand["support_whatsapp"]);

        if ($brand["support_whatsapp"] === "") {
            throw new PortalWriteError("Start the WhatsApp number with its country code, like +44 7911 123456.");
        }
    }

    return $brand;
}

function saveBrandSettings(PDO $pdo, array $brand)
{
    $error = "";

    foreach ($brand as $key => $value) {
        if (!setSiteSetting($pdo, $key, $value, $error)) {
            throw new PortalWriteError($error === ""
                ? "Could not save the branding"
                : "Could not save the branding: " . $error, 500);
        }
    }

    logEvent($pdo, EVENT_SETTINGS_CHANGED, "settings", 0, "Branding", "Changed the portal's branding");
}

/*
|--------------------------------------------------------------------------
| Logo
|--------------------------------------------------------------------------
|
| The page uploads a file (upload-logo.php); an assistant gives a link
| (content-image-fetch.php downloads and checks it), PNG or JPG only. Both end
| up in site-logo-store.php, and every page shows it as logo.png.
|
*/

function fetchSiteLogo($url)
{
    require_once __DIR__ . "/content-image-fetch.php";

    [$tmp, $extension] = downloadContentImage(0, (string) $url);

    try {
        if (!in_array($extension, ["png", "jpg"], true)) {
            throw new PortalWriteError("The logo must be a PNG or JPG image.");
        }

        $bytes = (string) file_get_contents($tmp);
        $mime = siteLogoMime($bytes);

        if (!$mime) {
            throw new PortalWriteError("The logo must be a PNG or JPG image.");
        }

        global $pdo;

        try {
            saveSiteLogo($pdo, $bytes, $mime);
        } catch (PDOException $e) {
            error_log("set_logo: " . $e->getMessage());
            throw new PortalWriteError("The logo couldn't be saved. Please try again.", 500);
        }

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
    ensureDepartmentsTable($pdo);

    return $pdo->query("
        SELECT
            admins.id, admins.name, admins.email, admins.role, admins.active,
            admins.managed_by_admin_id, admins.position, admins.avatar_path,
            manager.name AS managed_by_name,
            admins.department_id, department.name AS department_name
        FROM admins
        LEFT JOIN admins manager ON manager.id = admins.managed_by_admin_id
        LEFT JOIN departments department ON department.id = admins.department_id
        WHERE admins.role IN ('seo_admin', 'super_admin', 'account_manager')
        ORDER BY admins.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Throws unless $actor may take $action on the admin $target - the system
 * rules in permissions.php adminTargetRefusal(): never the Director, never
 * yourself, and only the Director touches someone holding a Director-only
 * key. Every check below that names an admin goes through here.
 */
function checkAdminTarget(PDO $pdo, array $actor, array $target, $action)
{
    $why = adminTargetRefusal($actor, $target, $action, $pdo);
    if ($why !== null) {
        throw new PortalWriteError($why, 403);
    }
    return $target;
}

/** Returns the admin whose role changes. */
function checkAdminRoleChange(PDO $pdo, array $owner, $targetId, $newRole)
{
    if (!in_array($newRole, ASSIGNABLE_ADMIN_ROLES, true)) {
        throw new PortalWriteError("Role must be one of: " . implode(", ", ASSIGNABLE_ADMIN_ROLES) . ".");
    }

    return checkAdminTarget($pdo, $owner, findAdminRow($pdo, $targetId), "role");
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

    // Manager links only run down MANAGER_CAN_MANAGE (team-scope.php), which
    // is what keeps a chain from looping back. Links the new role breaks go:
    // their own manager if that manager can't take the new role, and the
    // people under them the new role can't manage. The Director re-assigns.
    $pdo->prepare("
        UPDATE admins a JOIN admins m ON m.id = a.managed_by_admin_id
        SET a.managed_by_admin_id = NULL
        WHERE a.id = ? AND NOT FIND_IN_SET(?, CASE m.role
            WHEN 'super_admin' THEN ? WHEN 'account_manager' THEN ? ELSE '' END)
    ")->execute([(int) $targetId, $newRole,
        implode(",", MANAGER_CAN_MANAGE["super_admin"]), implode(",", MANAGER_CAN_MANAGE["account_manager"])]);
    $keep = MANAGER_CAN_MANAGE[$newRole] ?? [];
    $pdo->prepare("UPDATE admins SET managed_by_admin_id = NULL WHERE managed_by_admin_id = ?"
        . ($keep ? " AND role NOT IN (" . implode(",", array_fill(0, count($keep), "?")) . ")" : ""))
        ->execute(array_merge([(int) $targetId], $keep));

    logEvent($pdo, EVENT_ADMIN_ROLE, "admin", $targetId, $target["name"],
        "Changed " . $target["name"] . "'s role from " . adminRoleName($target["role"]) . " to " . adminRoleName($newRole));
}

/**
 * Activating or deactivating blocks login - nothing about that admin
 * (assignments, forms, review history) is touched, and reactivating restores
 * access exactly as it was. Never the Director, never yourself
 * (checkAdminTarget()).
 */
function checkAdminActiveChange(PDO $pdo, array $owner, $targetId)
{
    return checkAdminTarget($pdo, $owner, findAdminRow($pdo, $targetId), "active");
}

/**
 * Deactivating also ends their session, as a role change does, so a tab
 * that is already open stops working on its next request rather than only
 * at the next sign-in. Their AI connections stop too: loadPrincipal()
 * refuses an inactive admin.
 */
function setAdminActive(PDO $pdo, $targetId, $active)
{
    $pdo->prepare($active ? "UPDATE admins SET active = 1 WHERE id = ?"
                          : "UPDATE admins SET active = 0, session_token = NULL WHERE id = ?")
        ->execute([(int) $targetId]);

    $name = eventAdminName($pdo, $targetId);
    logEvent($pdo, EVENT_ADMIN_ACTIVE, "admin", $targetId, $name, ($active ? "Reactivated " : "Deactivated ") . $name);
}

/**
 * Who may sit under a manager: plain Admins for anyone, and super admins as
 * well when the manager is an account manager - the pool settings.html
 * offers. Returns [manager row, the given ids that are allowed, allowed roles];
 * anything outside the pool is dropped.
 */
function checkManagerAssignment(PDO $pdo, array $actor, $managerId, array $adminIds)
{
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $managerId]);
    $manager = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$manager || !isset(MANAGER_CAN_MANAGE[$manager["role"]])) {
        throw new PortalWriteError("That manager was not found.", 404);
    }
    checkAdminTarget($pdo, $actor, $manager, "manager");

    $allowedRoles = MANAGER_CAN_MANAGE[$manager["role"]];
    $rolePlaceholders = implode(",", array_fill(0, count($allowedRoles), "?"));

    $valid = $pdo->prepare("SELECT id, role FROM admins WHERE role IN ($rolePlaceholders) AND id <> ?");
    $valid->execute(array_merge($allowedRoles, [(int) $managerId]));
    // Each admin moved is checked on their own, so a bulk save can't carry
    // someone the caller may not touch - themself, for one.
    $validIds = [];
    foreach ($valid->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (adminTargetRefusal($actor, $row, "manager", $pdo) === null) {
            $validIds[] = (int) $row["id"];
        }
    }

    $adminIds = array_values(array_unique(array_intersect(array_map("intval", $adminIds), $validIds)));

    // The save is a full replace, so leaving someone out moves them too. Whoever
    // sits under this manager now and may not be touched by the caller (the
    // caller themself, a holder of a Director-only key) stays where they are.
    $current = $pdo->prepare("SELECT id, role FROM admins WHERE managed_by_admin_id = ?");
    $current->execute([(int) $managerId]);
    foreach ($current->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!in_array((int) $row["id"], $adminIds, true) && in_array($row["role"], $allowedRoles, true)
            && adminTargetRefusal($actor, $row, "manager", $pdo) !== null) {
            $adminIds[] = (int) $row["id"];
        }
    }

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
 * The department picked on the Add / Edit admin popup: "" or 0 for none, else
 * an active department - or the one they already have, archived or not, so
 * saving an edit never quietly takes it away. Returns the id or null.
 */
function checkAdminDepartment(PDO $pdo, $departmentId, $currentId = null)
{
    ensureDepartmentsTable($pdo);

    $departmentId = (int) $departmentId;
    if (!$departmentId) {
        return null;
    }
    if ($currentId !== null && $departmentId === (int) $currentId) {
        return $departmentId;
    }
    $stmt = $pdo->prepare("SELECT active FROM departments WHERE id = ?");
    $stmt->execute([$departmentId]);
    $active = $stmt->fetchColumn();
    if ($active === false) {
        throw new PortalWriteError("That department doesn't exist.");
    }
    if (!(int) $active) {
        throw new PortalWriteError("That department is archived. Pick another, or restore it first.");
    }
    return $departmentId;
}

/**
 * $password is the plain password, or null for one nobody knows; $avatar a
 * saved photo path, or null; $departmentId from checkAdminDepartment().
 * Returns the id.
 */
function createAdmin(PDO $pdo, $name, $email, $role, $password = null, $position = null, $avatar = null, $departmentId = null)
{
    ensureAdminColumns($pdo);

    $hash = password_hash($password ?? bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $pdo->prepare("INSERT INTO admins (name, email, password, role, active, position, avatar_path, department_id) VALUES (?, ?, ?, ?, 1, ?, ?, ?)")
        ->execute([$name, $email, $hash, $role, $position, $avatar, $departmentId]);

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
    $target = checkAdminTarget($pdo, $owner, findAdminRow($pdo, $targetId), "edit");

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

    $clean = ["name" => $name, "email" => $email, "position" => $position, "role" => $role];

    // Callers that don't send a department (older ones, the MCP) leave it as it is.
    if (array_key_exists("department_id", $in)) {
        $clean["department_id"] = checkAdminDepartment($pdo, $in["department_id"], $target["department_id"] ?? null);
    }

    return [$target, $clean];
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

    if (array_key_exists("department_id", $clean)
        && (int) ($target["department_id"] ?? 0) !== (int) $clean["department_id"]) {
        $pdo->prepare("UPDATE admins SET department_id = ? WHERE id = ?")
            ->execute([$clean["department_id"], (int) $target["id"]]);
        $dept = $clean["department_id"] ? $pdo->query("SELECT name FROM departments WHERE id = " . (int) $clean["department_id"])->fetchColumn() : "";
        logEvent($pdo, EVENT_ADMIN_DEPARTMENT, "admin", $target["id"], $clean["name"],
            $dept ? "Moved " . $target["name"] . " to the department \"" . $dept . "\"" : "Took " . $target["name"] . " out of their department");
    }

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
        "You've been added to " . portalName($pdo),
        trim((string) $ownerName) . " added you as " . adminRoleName($role)
            . ". Choose a password to sign in. The link works for 3 days.",
        ["Name" => $name, "Email" => $email, "Role" => adminRoleName($role)],
        "reset-password.html?token=" . $token,
        "Choose your password"
    );

    return sendPortalEmail([["email" => $email, "name" => $name]], "Your " . portalName($pdo) . " account", $html) > 0;
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

/**
 * Returns $changes cleaned up, or throws with the first thing wrong. $role is
 * the role row being changed, or null for one admin's exceptions. The
 * Director-only keys go to one admin at a time, never to a whole role; only
 * the Director hands them out, and nobody else changes their own role's row
 * (permissions.php permissionChangeRefusal()).
 */
function checkPermissionChanges(array $actor, $changes, $role = null)
{
    $forOneAdmin = $role === null;
    if (!is_array($changes) || $changes === []) {
        throw new PortalWriteError("Choose at least one permission to change.");
    }

    $clean = [];
    foreach ($changes as $perm => $value) {
        if (!isPermission($perm)) {
            throw new PortalWriteError("Unknown permission: " . mb_substr((string) $perm, 0, 64) . ".");
        }
        if (isOwnerOnlyPermission($perm) && !$forOneAdmin) {
            throw new PortalWriteError("“" . PERMISSIONS[$perm]["label"] . "” can be given to one person, not to a whole role.", 403);
        }
        if ($value === "default") {
            $value = null;
        }
        if ($value !== null && !is_bool($value)) {
            throw new PortalWriteError("Each permission is true, false or \"default\".");
        }
        $clean[$perm] = $value;
    }

    $why = permissionChangeRefusal($actor, $clean, $role);
    if ($why !== null) {
        throw new PortalWriteError($why, 403);
    }
    return $clean;
}

function checkRolePermissionChange(array $actor, $role, $changes)
{
    if (!in_array($role, PERMISSION_ROLES, true)) {
        throw new PortalWriteError("Role must be one of: " . implode(", ", PERMISSION_ROLES) . ".");
    }
    return checkPermissionChanges($actor, $changes, $role);
}

/** Returns [the admin, $changes cleaned up]. */
function checkAdminPermissionChange(PDO $pdo, array $actor, $adminId, $changes)
{
    $target = checkAdminTarget($pdo, $actor, findAdminRow($pdo, $adminId), "permissions");

    if (!in_array($target["role"], PERMISSION_ROLES, true)) {
        throw new PortalWriteError("This admin has no role to set permissions for.", 422);
    }

    return [$target, checkPermissionChanges($actor, $changes)];
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
 * and every admin with their exceptions, what they end up holding, and where
 * each answer came from (permissionDecision(): override, role or default).
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
            $held = [];
            $sources = [];
            foreach (array_keys(PERMISSIONS) as $perm) {
                $decision = permissionDecision($a, $perm, $pdo);
                if ($decision["allowed"]) {
                    $held[] = $perm;
                }
                $sources[$perm] = $decision["source"];
            }
            return [
                "id" => (int) $a["id"],
                "name" => $a["name"],
                "role" => $a["role"],
                "role_name" => adminRoleName($a["role"]),
                "active" => (int) $a["active"] === 1,
                "overrides" => (object) ($overrides[(int) $a["id"]] ?? []),
                "permissions" => $held,
                "sources" => $sources,
            ];
        }, permissionPeopleInOrder(listTeamAdmins($pdo))),
    ];
}

/**
 * permissionsOverview() as $actor may edit it: which role columns and which
 * people they may not change, and why - the same refusals a save would get
 * (permissionChangeRefusal(), adminTargetRefusal()), so the page greys out
 * exactly what the server would turn down.
 */
function permissionsPanel(PDO $pdo, array $actor)
{
    $o = permissionsOverview($pdo);
    $o["me"] = ["id" => (int) $actor["id"], "director" => isDirector($actor)];
    foreach ($o["roles"] as &$r) {
        $r["locked"] = permissionChangeRefusal($actor, [], $r["key"]);
    }
    unset($r);
    foreach ($o["admins"] as &$a) {
        $a["locked"] = adminTargetRefusal($actor, $a, "permissions", $pdo);
    }
    unset($a);
    return $o;
}

/**
 * One Save on the Permissions panel: every role column and person that
 * changed, as [{"role": ..., "changes": {...}} | {"admin_id": ..., ...}].
 * Each is checked and stored in the caller's transaction, so one refusal
 * leaves nothing stored. Returns the names that changed, for the reply.
 */
function savePermissionBatch(PDO $pdo, array $actor, $batch)
{
    if (!is_array($batch) || $batch === [] || count($batch) > 100) {
        throw new PortalWriteError("Send between 1 and 100 sets of changes.");
    }

    $seen = [];
    $names = [];
    foreach (array_values($batch) as $item) {
        if (!is_array($item)) {
            throw new PortalWriteError("Each set of changes names a role or an admin.");
        }
        $id = isset($item["role"]) ? "role:" . json_encode($item["role"])
            : (isset($item["admin_id"]) ? "admin:" . (int) $item["admin_id"] : null);
        if ($id === null) {
            throw new PortalWriteError("Say which role or admin to change.");
        }
        if (isset($seen[$id])) {
            throw new PortalWriteError("Each role or admin can appear once in a save.");
        }
        $seen[$id] = true;

        $changes = $item["changes"] ?? null;
        if (isset($item["role"])) {
            $role = is_string($item["role"]) ? $item["role"] : "";
            setRolePermissions($pdo, $actor, $role, checkRolePermissionChange($actor, $role, $changes));
            $names[] = "every " . adminRoleName($role);
        } else {
            [$target, $clean] = checkAdminPermissionChange($pdo, $actor, (int) $item["admin_id"], $changes);
            setAdminPermissions($pdo, $actor, $target, $clean);
            $names[] = $target["name"];
        }
    }
    return $names;
}

/* The person picker's order: by role as the columns run (Admin, Super Admin,
   Account Manager), then by name. */
function permissionPeopleInOrder(array $admins)
{
    usort($admins, function ($a, $b) {
        $byRole = array_search($a["role"], PERMISSION_ROLES, true) <=> array_search($b["role"], PERMISSION_ROLES, true);
        return $byRole ?: strcasecmp($a["name"], $b["name"]);
    });
    return $admins;
}
