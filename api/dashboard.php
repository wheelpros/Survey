<?php

require_once "db.php";

// إرجاع الاستجابة بترميز JSON
header("Content-Type: application/json");

$headers = getallheaders();
$authHeader = $headers["Authorization"] ?? "";
$token = str_replace("Bearer ", "", $authHeader);

if (!$token) {
    echo json_encode([
        "success" => false,
        "message" => "No token provided"
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name, email, approved, profile_image
    FROM users
    WHERE session_token = ?
    LIMIT 1
");

$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user || (int)$user["approved"] !== 1) {
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$userId = $user["id"];

// 1. حساب إحصائيات الاستبيانات
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM surveys
    WHERE assigned_user_id = ?
");
$stmt->execute([$userId]);
$totalSurveys = (int)$stmt->fetch()["total"];

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM surveys
    WHERE assigned_user_id = ? AND status = 'pending'
");
$stmt->execute([$userId]);
$pendingSurveys = (int)$stmt->fetch()["total"];

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM surveys
    WHERE assigned_user_id = ? AND status = 'completed'
");
$stmt->execute([$userId]);
$completedSurveys = (int)$stmt->fetch()["total"];

// 2. جلب قائمة الاستبيانات
$stmt = $pdo->prepare("
    SELECT id, title, status, created_at
    FROM surveys
    WHERE assigned_user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$surveys = $stmt->fetchAll();

// 3. 💥 الجزء الجديد: جلب الملفات المسندة للمستخدم 💥
$stmtFiles = $pdo->prepare("
    SELECT
        sf.id,
        sf.original_name,
        sf.file_size,
        sf.uploaded_at
    FROM user_source_files usf
    JOIN source_files sf ON sf.id = usf.file_id
    WHERE usf.user_id = ?
    ORDER BY usf.assigned_at DESC
");
$stmtFiles->execute([$userId]);
$assignedFiles = $stmtFiles->fetchAll();

// 4. إرجاع النتيجة الكاملة مع قائمة الملفات
echo json_encode([
    "success" => true,
    "user" => [
        "id" => $user["id"],
        "name" => $user["name"],
        "email" => $user["email"],
        "profile_image" => $user["profile_image"]
    ],
    "stats" => [
        "submittedSurveys" => $completedSurveys,
        "pendingResponses" => $pendingSurveys,
        "responsesReceived" => $totalSurveys
    ],
    "surveys" => $surveys,
    "files" => $assignedFiles // تم إضافتها هنا لتصل للفرونت إند
]);