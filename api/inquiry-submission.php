<?php

/*
|--------------------------------------------------------------------------
| Validating and storing an answer to a consultation inquiry
|--------------------------------------------------------------------------
|
| Shared by the two ways an answer arrives:
|
|   api/public-inquiry.php   POST from inquiry.html            source 'web'
|   api/inquiry-submit.php   POST from the MCP server (AI)     source 'mcp'
|
| Both must accept and refuse exactly the same answers, so the rules live
| here once rather than in each. Moved out of public-inquiry.php unchanged,
| apart from fields now being checked in the order the form shows them -
| the first problem reported is the first one the person would reach.
|
| Requires db.php ($pdo, ensureInquiryTables) to have run first.
*/

function findInquiryByName(PDO $pdo, $name)
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

function inquiryFieldsForSubmission(PDO $pdo, $inquiryId)
{
    $stmt = $pdo->prepare("
        SELECT id, field_label, field_type, required, options
        FROM inquiry_fields
        WHERE inquiry_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$inquiryId]);
    return $stmt->fetchAll();
}

/**
 * Checks $answers ([{fieldId, value}], value a string or, for a 'choice'
 * question, an array) against the inquiry's fields.
 *
 * Returns ["error" => null, "answers" => [fieldId => text]] with every
 * field present - unanswered optional ones as "" - or
 * ["error" => "message for the person", "answers" => []].
 */
function validateInquiryAnswers(array $fields, $answers)
{
    $answersByFieldId = [];

    foreach (is_array($answers) ? $answers : [] as $answer) {
        if (!is_array($answer)) {
            continue;
        }
        $id = (int)($answer["fieldId"] ?? 0);
        $value = $answer["value"] ?? "";

        // A 'choice' question sends an array; everything else sends a string.
        $answersByFieldId[$id] = is_array($value)
            ? array_values(array_filter(array_map(function ($v) { return is_scalar($v) ? trim((string)$v) : ""; }, $value), function ($v) { return $v !== ""; }))
            : (is_scalar($value) ? trim((string)$value) : "");
    }

    $result = [];

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

        $result[$id] = is_array($given) ? implode(", ", $given) : $given;

        /* Fixed by the type: 120 characters for a short answer, 800 for a
           paragraph. maxlength in the browser is a courtesy to the person
           typing; this is the rule.

           Only the free-text types are capped. A list answer is options the
           admin wrote, checked against that list a few lines up, so its length
           is not something the person answering chose. */
        $limit = ($type === "textarea") ? 800 : 120;
        $capped = ($type === "input" || $type === "textarea");

        if ($capped && mb_strlen((string) $result[$id]) > $limit) {
            return [
                "error" => "\"" . $field["field_label"] . "\" must be " . $limit . " characters or fewer.",
                "answers" => []
            ];
        }

        if ((int)$field["required"] === 1 && $result[$id] === "") {
            return [
                "error" => "Please fill in \"" . $field["field_label"] . "\"",
                "answers" => []
            ];
        }
    }

    return ["error" => null, "answers" => $result];
}

/**
 * Which of the optional inquiry_responses columns this database has. They
 * are added lazily by ensureInquiryTables(); a DB user that may not ALTER
 * leaves them missing, and the web form must keep working regardless.
 */
function inquiryResponseColumns(PDO $pdo)
{
    static $columns = null;
    if ($columns === null) {
        try {
            $stmt = $pdo->query("
                SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inquiry_responses'
            ");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            $columns = [];
        }
    }
    return $columns;
}

/**
 * True if a submission carrying this idempotency key is already stored.
 */
function submissionKeyUsed(PDO $pdo, $submissionKey)
{
    $stmt = $pdo->prepare("SELECT 1 FROM inquiry_responses WHERE submission_key = ? LIMIT 1");
    $stmt->execute([$submissionKey]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Stores one validated submission - the invite row, the response, one
 * answer per field - in a single transaction.
 *
 * $inviteId: 0 for a name-only link (an 'answered' invite row is written,
 * because inquiry_responses.invite_id points at one), or a legacy
 * single-use invite to claim.
 *
 * Returns ["success" => bool, "message" => string, "duplicate" => bool].
 * A duplicate is a success: the same confirmed submission already arrived.
 */
function storeInquiryResponse(PDO $pdo, $inquiryId, $inviteId, array $fields, array $answersByFieldId, $source = null, $submissionKey = null)
{
    $columns = inquiryResponseColumns($pdo);
    $hasSource = in_array("source", $columns, true);
    $hasKey = in_array("submission_key", $columns, true);

    // Without the column there is nothing to make a retry safe - refuse
    // rather than risk storing the same lead twice.
    if ($submissionKey !== null && !$hasKey) {
        return ["success" => false, "message" => "Submitting through an assistant is not available right now.", "duplicate" => false];
    }

    if ($submissionKey !== null && submissionKeyUsed($pdo, $submissionKey)) {
        return ["success" => true, "message" => "This submission was already received.", "duplicate" => true];
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
                return ["success" => false, "message" => "This link has already been used", "duplicate" => false];
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
            $inviteStmt->execute([$inquiryId, $slug]);
            $inviteId = $pdo->lastInsertId();
        }

        $cols = ["inquiry_id", "invite_id"];
        $values = [$inquiryId, $inviteId];
        if ($hasSource) {
            $cols[] = "source";
            $values[] = $source;
        }
        if ($hasKey) {
            $cols[] = "submission_key";
            $values[] = $submissionKey;
        }

        $responseStmt = $pdo->prepare(
            "INSERT INTO inquiry_responses (" . implode(", ", $cols) . ") VALUES ("
            . implode(", ", array_fill(0, count($cols), "?")) . ")"
        );
        $responseStmt->execute($values);
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

        return ["success" => true, "message" => "Thanks - your message has been sent.", "duplicate" => false];

    } catch (Exception $e) {
        $pdo->rollBack();

        // Two copies of one confirmed submission racing each other: the
        // unique key let exactly one through, and that one is this one's.
        if ($submissionKey !== null && $e instanceof PDOException && $e->getCode() === "23000"
            && submissionKeyUsed($pdo, $submissionKey)) {
            return ["success" => true, "message" => "This submission was already received.", "duplicate" => true];
        }

        return ["success" => false, "message" => "Failed to submit. Please try again.", "duplicate" => false];
    }
}
