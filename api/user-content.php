<?php
// إظهار الأخطاء أثناء التطوير
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


$DB_HOST = "fsook8og8oscgccgcgs88w4o";
$DB_PORT = "3306";
$DB_NAME = "default";
$DB_USER = "mysql";
$DB_PASS = "rCHm3LJRaAa04UAnRtNFPwEk8fSoif40uvP8WAPGgJ18qFzh11vMCeoii9iuX9u1";
try {
    // الاتصال باستخدام PDO بدلاً من mysqli
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit();
}

// 2. قراءة الـ Authorization Header
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized access.", "content" => []]);
    exit();
}

$token = $matches[1];

try {
    // 3. التحقق من التوكن وجلب id اليوزر
    $stmt = $pdo->prepare("SELECT id FROM users WHERE token = :token LIMIT 1");
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Invalid session token.", "content" => []]);
        exit();
    }

    $userId = $user['id'];

    // 4. جلب الكونتنت المربوط بـ user_id
    $stmtContent = $pdo->prepare("SELECT * FROM content WHERE user_id = :user_id ORDER BY id DESC");
    $stmtContent->execute(['user_id' => $userId]);
    $contents = $stmtContent->fetchAll();

    echo json_encode([
        "success" => true,
        "content" => $contents
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Query error: " . $e->getMessage(),
        "content" => []
    ]);
}