<?php
require_once "db.php";
require_once "scope.php";

$token = $_GET["token"] ?? "";
$responseId = (int)($_GET["id"] ?? 0);

// A link the browser opens, so the session comes in ?token=. The same
// admins, and the same responses, as response-details.php (scope.php).
$admin = adminFromSessionToken($pdo, $token);

if(!$admin){
    http_response_code(401);
    die("Unauthorized");
}

if(!$responseId || !can($admin, "pages.forms", $pdo) || !canAccessRecord($pdo, $admin, "response", $responseId)){
    http_response_code(404);
    die("Response not found");
}

$stmt = $pdo->prepare("
    SELECT question_label AS question_text, answer
    FROM survey_answers
    WHERE response_id = ?
    ORDER BY id ASC
");
$stmt->execute([$responseId]);
$answers = $stmt->fetchAll();

if(!class_exists("ZipArchive")){
    die("ZipArchive not enabled on server");
}

function x($value){
    return htmlspecialchars($value ?? "", ENT_QUOTES | ENT_XML1, "UTF-8");
}

$rows = '';

$rowNum = 1;
$rows .= '
<row r="1">
  <c r="A1" t="inlineStr"><is><t>Question</t></is></c>
  <c r="B1" t="inlineStr"><is><t>Answer</t></is></c>
</row>';

foreach($answers as $item){
    $rowNum++;
    $rows .= '
<row r="'.$rowNum.'">
  <c r="A'.$rowNum.'" t="inlineStr"><is><t>'.x($item["question_text"]).'</t></is></c>
  <c r="B'.$rowNum.'" t="inlineStr"><is><t>'.x($item["answer"]).'</t></is></c>
</row>';
}

$sheet = '<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>'.$rows.'</sheetData>
</worksheet>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

$workbook = '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Survey Answers" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>';

$tmp = tempnam(sys_get_temp_dir(), "xlsx");

$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);

$zip->addFromString("[Content_Types].xml", $contentTypes);
$zip->addFromString("_rels/.rels", $rels);
$zip->addFromString("xl/workbook.xml", $workbook);
$zip->addFromString("xl/_rels/workbook.xml.rels", $workbookRels);
$zip->addFromString("xl/worksheets/sheet1.xml", $sheet);

$zip->close();

header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"survey-answers.xlsx\"");
header("Content-Length: " . filesize($tmp));

readfile($tmp);
unlink($tmp);
exit;