<?php

require_once "db.php";

header("Content-Type: application/json");

ensureInquiryTables($pdo);

$method = $_SERVER["REQUEST_METHOD"];

/*
|------------------------------------------------------------------------------
| The public consultation form
|------------------------------------------------------------------------------
|
| A link is the inquiry's name and nothing else:
|
|     inquiry.html?name=free-30-minute-business-growth-consultation
|
| One link per inquiry, shared with as many people as you like, open for as
| long as the inquiry's status says active. There is no per-person token and
| nothing expires on a clock - closing an inquiry is what closes its link.
|
| That is a deliberate trade. The link is no longer single-use, so anyone
| holding it can submit, and submit again; the inquiry's Status is the only
| thing standing between a link and an open form.
|
| Links handed out before this carried a token instead (?token=, and ?ref=
| before that). Those still resolve, and keep their old single-use behaviour -
| a token that has been answered stays answered.
|
*/

function findByName($pdo, $name)
{
    $stmt = $pdo->prepare("
        SELECT id, title, intro_text, status, slug
        FROM inquiries
        WHERE slug = ?
        LIMIT 1
    ");
    $stmt->execute([$name]);
    return $stmt->fetch();
}

function findByToken($pdo, $token)
{
    $stmt = $pdo->prepare("
        SELECT
            inquiry_invites.id AS invite_id, inquiry_invites.status AS invite_status,
            inquiries.id, inquiries.title, inquiries.intro_text, inquiries.status, inquiries.slug
        FROM inquiry_invites
        LEFT JOIN inquiries ON inquiries.id = inquiry_invites.inquiry_id
        WHERE inquiry_invites.slug = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

/*
| Resolves whichever half of a link was given. Returns the inquiry, plus the
| invite when one was named - the caller needs it to claim a legacy token.
*/
function resolveLink($pdo, $name, $token)
{
    if ($token !== "") {
        $row = findByToken($pdo, $token);

        if (!$row || !$row["title"]) {
            return ["error" => "This link is not valid"];
        }

        // A name alongside a token still has to agree with it.
        if ($name !== "" && $row["slug"] !== $name) {
            return ["error" => "This link is not valid"];
        }

        if ($row["invite_status"] === "answered") {
            return ["error" => "This link has already been used"];
        }

        return ["inquiry" => $row, "inviteId" => (int)$row["invite_id"]];
    }

    if ($name === "") {
        return ["error" => "Missing link"];
    }

    $inquiry = findByName($pdo, $name);

    if (!$inquiry) {
        return ["error" => "This link is not valid"];
    }

    return ["inquiry" => $inquiry, "inviteId" => 0];
}

function closedMessage($inquiry)
{
    return ($inquiry["status"] ?? "active") === "inactive"
        ? "This inquiry is not accepting responses right now"
        : null;
}

if ($method === "GET") {

    $name = trim($_GET["name"] ?? "");
    $token = trim($_GET["token"] ?? $_GET["slug"] ?? "");

    $link = resolveLink($pdo, $name, $token);

    if (isset($link["error"])) {
        echo json_encode(["success" => false, "message" => $link["error"]]);
        exit;
    }

    $closed = closedMessage($link["inquiry"]);

    if ($closed) {
        echo json_encode(["success" => false, "message" => $closed]);
        exit;
    }

    $fieldsStmt = $pdo->prepare("
        SELECT id, field_label, field_type, required, options, sort_order
        FROM inquiry_fields
        WHERE inquiry_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $fieldsStmt->execute([$link["inquiry"]["id"]]);

    echo json_encode([
        "success" => true,
        "inquiry" => [
            "title" => $link["inquiry"]["title"],
            "intro_text" => $link["inquiry"]["intro_text"]
        ],
        "fields" => $fieldsStmt->fetchAll()
    ]);
    exit;
}

if ($method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    $name = trim($input["name"] ?? "");
    $token = trim($input["token"] ?? $input["slug"] ?? "");
    $answers = $input["answers"] ?? [];

    $link = resolveLink($pdo, $name, $token);

    if (isset($link["error"])) {
        echo json_encode(["success" => false, "message" => $link["error"]]);
        exit;
    }

    $inquiry = $link["inquiry"];
    $inviteId = $link["inviteId"];

    $closed = closedMessage($inquiry);

    if ($closed) {
        echo json_encode(["success" => false, "message" => $closed]);
        exit;
    }

    $fieldsStmt = $pdo->prepare("SELECT id, field_label, field_type, required, options FROM inquiry_fields WHERE inquiry_id = ?");
    $fieldsStmt->execute([$inquiry["id"]]);
    $fields = $fieldsStmt->fetchAll();

    $answersByFieldId = [];

    foreach ($answers as $answer) {
        $id = (int)($answer["fieldId"] ?? 0);
        $value = $answer["value"] ?? "";

        // A 'choice' question sends an array; everything else sends a string.
        $answersByFieldId[$id] = is_array($value)
            ? array_values(array_filter(array_map("trim", $value), function ($v) { return $v !== ""; }))
            : trim((string)$value);
    }

    foreach ($fields as $field) {
        $id = (int)$field["id"];
        $given = $answersByFieldId[$id] ?? "";
        $type = $field["field_type"];

        $allowed = ($type === "choice" || $type === "select")
            ? array_values(array_filter(array_map("trim", preg_split("/\r\n|\r|\n/", (string)$field["options"]))))
            : [];

        /* Whatever the page rendered, an answer to a list question has to be on
           the list. A value that is not gets dropped rather than refused: the
           required check below is what decides whether that is fatal. */
        if ($allowed) {
            if (is_array($given)) {
                $given = array_values(array_intersect($given, $allowed));
            } else if ($given !== "" && !in_array($given, $allowed, true)) {
                $given = "";
            }

            // 'select' takes exactly one answer however many arrive.
            if ($type === "select" && is_array($given)) {
                $given = count($given) ? $given[0] : "";
            }
        }

        $answersByFieldId[$id] = is_array($given) ? implode(", ", $given) : $given;

        if ((int)$field["required"] === 1 && $answersByFieldId[$id] === "") {
            echo json_encode([
                "success" => false,
                "message" => "Please fill in \"" . $field["field_label"] . "\""
            ]);
            exit;
        }
    }

    $pdo->beginTransaction();

    try {

        if ($inviteId) {

            /* A legacy token, and it is still one answer only. The conditional
               UPDATE is the guard: two people racing on the same old link, and
               exactly one of them gets through. */
            $claimStmt = $pdo->prepare("
                UPDATE inquiry_invites
                SET status = 'answered'
                WHERE id = ? AND status = 'pending'
            ");
            $claimStmt->execute([$inviteId]);

            if ($claimStmt->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(["success" => false, "message" => "This link has already been used"]);
                exit;
            }

        } else {

            /* A name-only link, which is not single-use - so there is nothing
               to claim. A row is still written per submission, because
               inquiry_responses.invite_id points at one and because it is the
               only per-submission record this schema keeps. */
            do {
                $slug = bin2hex(random_bytes(8));
                $check = $pdo->prepare("SELECT id FROM inquiry_invites WHERE slug = ? LIMIT 1");
                $check->execute([$slug]);
            } while ($check->fetch());

            $inviteStmt = $pdo->prepare("
                INSERT INTO inquiry_invites (inquiry_id, slug, status, expires_at)
                VALUES (?, ?, 'answered', NULL)
            ");
            $inviteStmt->execute([$inquiry["id"], $slug]);
            $inviteId = $pdo->lastInsertId();
        }

        $responseStmt = $pdo->prepare("
            INSERT INTO inquiry_responses (inquiry_id, invite_id)
            VALUES (?, ?)
        ");
        $responseStmt->execute([$inquiry["id"], $inviteId]);
        $responseId = $pdo->lastInsertId();

        $answerStmt = $pdo->prepare("
            INSERT INTO inquiry_response_answers (response_id, field_id, answer_text)
            VALUES (?, ?, ?)
        ");

        foreach ($fields as $field) {
            $value = $answersByFieldId[(int)$field["id"]] ?? "";
            $answerStmt->execute([$responseId, $field["id"], $value]);
        }

        $pdo->commit();

        echo json_encode(["success" => true, "message" => "Thanks - your message has been sent."]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Failed to submit. Please try again."]);
        exit;
    }
}

echo json_encode(["success" => false, "message" => "Invalid request"]);
