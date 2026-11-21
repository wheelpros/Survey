<?php

/*
|--------------------------------------------------------------------------
| Who is on whose team
|--------------------------------------------------------------------------
|
| The people an admin manages, from admins.managed_by_admin_id:
|
|   the Director         every Account Manager, Super Admin and Admin
|   an Account Manager   the Super Admins they manage, and the Admins they
|                        manage directly or through one of those Super Admins
|   a Super Admin        the Admins they manage
|   an Admin             nobody
|
| Used by api/client-team.php (who may be put on a client's team) and the
| activity reports (who may be planned for and reviewed by whom).
|
*/

require_once __DIR__ . "/db.php";

/*
| Who may sit under whom: a manager's role => the roles they can be given.
| A Super Admin manages Admins; an Account Manager, Admins and Super Admins.
| Links only run down this list, so a chain can't loop back on itself. A
| system rule of the hierarchy, not a permission: checkManagerAssignment()
| (admin-settings-writes.php) and client-team.php read it.
*/
const MANAGER_CAN_MANAGE = [
    "super_admin" => ["seo_admin"],
    "account_manager" => ["seo_admin", "super_admin"],
];

/**
 * Everyone the actor manages, keyed by id: [id => [id, name, role, active]].
 */
function teamPoolFor(PDO $pdo, array $actor)
{
    $actorId = (int) $actor["id"];

    if (isDirector($actor)) {
        $stmt = $pdo->query("
            SELECT id, name, role, active
            FROM admins
            WHERE role IN ('account_manager', 'super_admin', 'seo_admin')
        ");

    } elseif ($actor["role"] === "account_manager") {
        $stmt = $pdo->prepare("
            SELECT id, name, role, active
            FROM admins
            WHERE (role = 'super_admin' AND managed_by_admin_id = ?)
               OR (role = 'seo_admin' AND (
                       managed_by_admin_id = ?
                    OR managed_by_admin_id IN (
                           SELECT sa.id FROM admins sa
                           WHERE sa.role = 'super_admin' AND sa.managed_by_admin_id = ?
                       )
                  ))
        ");
        $stmt->execute([$actorId, $actorId, $actorId]);

    } elseif ($actor["role"] === "super_admin") {
        $stmt = $pdo->prepare("
            SELECT id, name, role, active
            FROM admins
            WHERE role = 'seo_admin' AND managed_by_admin_id = ?
        ");
        $stmt->execute([$actorId]);

    } else {
        return [];
    }

    $pool = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pool[(int) $row["id"]] = $row;
    }

    return $pool;
}
