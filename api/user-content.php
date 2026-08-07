<?php
// إظهار الأخطاء لمعرفة السبب الحقيقي لو استمرت المشكلة
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 1. بيانات الاتصال بالداتابيز (تأكد من صحتها)
$db_host = "localhost";
$db_user = "YOUR_DB_USER";
$db_pass = "YOUR_DB_PASSWORD";
$db_name = "YOUR_DB_NAME";

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit();
}

// 2. قراءة الـ Header
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized access.", "content" => []]);
    exit();
}

$token = $matches[1];

// 3. التحقق من التوكن
$stmt = $conn->prepare("SELECT id FROM users WHERE token = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Users table query failed: " . $conn->error]);
    exit();
}

$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid token.", "content" => []]);
    exit();
}

$user = $result->fetch_assoc();
$userId = $user['id'];

// 4. استعلام الكونتنت (استعلام آمن يختار كل الأعمدة الموجودة)
$query = "SELECT * FROM content WHERE user_id = ? ORDER BY id DESC";

$stmtContent = $conn->prepare($query);
if (!$stmtContent) {
    echo json_encode(["success" => false, "message" => "Content table query failed: " . $conn->error]);
    exit();
}

$stmtContent->bind_param("i", $userId);
$stmtContent->execute();
$contentResult = $stmtContent->get_result();

$contents = [];
while ($row = $contentResult->fetch_assoc()) {
    $contents[] = $row;
}

echo json_encode([
    "success" => true,
    "content" => $contents
]);

$conn->close();