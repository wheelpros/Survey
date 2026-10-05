<?php

/*
| GET me/projects.php        my_projects
| GET me/projects.php?id=N   get_my_project
|
| The client's own projects and only their live updates - user-projects.php:
| a draft or a future-dated update doesn't exist here. No staff names or
| draft counts, the same as the client's page.
*/

require_once __DIR__ . "/_me.php";

v1RequireMethod("GET");
v1RequireScope($p, "self:read");

ensureProjectTables($pdo);

function meProjectItem(array $r)
{
    return [
        "id" => (int) $r["id"],
        "title" => (string) $r["title"],
        "type_label" => PROJECT_TYPES[$r["project_type"]] ?? (string) $r["project_type"],
        "status" => (string) $r["status"],
        "status_label" => PROJECT_STATUSES[$r["status"]] ?? (string) $r["status"],
        "progress" => (int) $r["progress"],
        "start_date" => (string) $r["start_date"],
        "end_date" => (string) $r["end_date"],
    ];
}

const ME_PROJECT_SELECT = "
    SELECT p.id, p.title, p.description, p.project_type, p.start_date, p.end_date, p.progress, p.status
    FROM projects p
";

$id = v1IntParam("id");
if ($id !== null) {
    $stmt = $pdo->prepare(ME_PROJECT_SELECT . " WHERE p.id = ? AND p.client_id = ? LIMIT 1");
    $stmt->execute([$id, $p["id"]]);
    $project = $stmt->fetch();
    if (!$project) {
        v1Error(404, "not_found", "No project of yours with that id.");
    }

    $stmt = $pdo->prepare("
        SELECT t.id, t.title, t.link, t.description, t.scheduled_date, t.scheduled_time, t.is_complete
        FROM project_tasks t
        INNER JOIN projects p ON p.id = t.project_id AND p.client_id = ?
        WHERE t.project_id = ? AND " . TASK_IS_LIVE_SQL . "
        ORDER BY t.scheduled_date IS NULL, t.scheduled_date ASC, t.id DESC
    ");
    $stmt->execute([$p["id"], $id]);

    $data = meProjectItem($project);
    $data["untrusted_content"] = ["description" => v1PlainText($project["description"] ?? "", 4000)];
    $data["updates"] = array_map(function ($r) {
        return [
            "id" => (int) $r["id"],
            "title" => (string) $r["title"],
            "date" => v1NullableString($r["scheduled_date"]),
            "time" => $r["scheduled_time"] === null ? null : substr((string) $r["scheduled_time"], 0, 5),
            "is_complete" => (int) $r["is_complete"] === 1,
            "link" => v1NullableString($r["link"]),
            "untrusted_content" => ["description" => v1PlainText($r["description"] ?? "", 2000)],
        ];
    }, $stmt->fetchAll());

    v1Reply(200, $data);
}

$stmt = $pdo->prepare(ME_PROJECT_SELECT . " WHERE p.client_id = ? ORDER BY p.start_date DESC, p.id DESC");
$stmt->execute([$p["id"]]);
v1Reply(200, ["items" => array_map("meProjectItem", $stmt->fetchAll())]);
