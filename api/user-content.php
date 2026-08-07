<?php
// إظهار الأخطاء أثناء التطوير لتحديد السبب لو حدث خطأ داخلي
ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 1. الاتصال بقاعدة البيانات (غير البيانات حسب سيرفرك)
$db_host = "localhost";
$db_user = "YOUR_DB_USER";
$db_pass = "YOUR_DB_PASSWORD";
$db_name = "YOUR_DB_NAME";

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit();
}

// 2. قراءة الـ Authorization Header
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = '';

if (isset($headers['Authorization'])) {
    $authHeader = $headers['Authorization'];
} elseif (isset($headers['authorization'])) {
    $authHeader = $headers['authorization'];
} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
}

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized access.", "content" => []]);
    exit();
}

$token = $matches[1];

// 3. التحقق من المستخدم بواسطة الـ Token
$stmt = $conn->prepare("SELECT id FROM users WHERE token = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Query preparation failed", "content" => []]);
    exit();
}

$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid session token.", "content" => []]);
    exit();
}

$user = $result->fetch_assoc();
$userId = $user['id'];

// 4. جلب الكونتنت المربوط بالـ user_id
$query = "SELECT id, title, client, caption, content_type, type_label, platform, category, orientation, post_date, post_time, publish_now, status, media_path, created_at 
          FROM content 
          WHERE user_id = ? 
          ORDER BY id DESC";

$stmtContent = $conn->prepare($query);
if ($stmtContent) {
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
} else {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch content",
        "content" => []
    ]);
}

$conn->close();