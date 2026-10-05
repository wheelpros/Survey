<?php

/*
| GET /api/v1/me.php - who this token belongs to and what it may do.
|
| Backs the MCP whoami tool: the first thing an assistant can check, and the
| quickest proof that login, consent and role scoping all line up.
*/

require_once __DIR__ . "/_bootstrap.php";

$p = v1Principal($pdo);

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    v1Error(405, "method_not_allowed", "GET only");
}

$data = [
    "kind" => $p["kind"],
    "id" => $p["id"],
    "name" => $p["name"],
    "email" => $p["email"],
    "role" => $p["role"],
    "scopes" => $p["scopes"],
    "via" => $p["via"],
];

if ($p["via"] === "mcp") {
    $data["connected_app"] = $p["client_name"];
}

if ($p["kind"] === "admin") {
    // How many clients this admin can see - all of them for the owner.
    $ids = assignedClientIds($pdo, $p);
    if ($ids === null) {
        $data["visible_clients"] = "all";
        // Every registration, pending ones included - what search_clients
        // and the Clients page list.
        $data["visible_client_count"] = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    } else {
        $data["visible_clients"] = "assigned";
        $data["visible_client_count"] = count($ids);
    }
} else {
    $data["company_name"] = $p["company_name"];
}

v1Reply(200, $data);
