<?php
require_once "db.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

// 1. التحقق من التوكن واستخراج id المستخدم
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$token = str_replace('Bearer ', '', $authHeader);

if (!$token) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    http_response_code(401);
    exit;
}

try {
    // جلب بيانات المستخدم عبر التوكن (أو حقل session token)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(["success" => false, "message" => "Invalid Token"]);
        http_response_code(401);
        exit;
    }

    $userId = $user['id'];

    // 2. جلب المحتوى المخصص لهذا المستخدم OR المحتوى الموجه للجميع (user_id IS NULL)
    $query = "SELECT * FROM content 
              WHERE (user_id = :user_id OR user_id IS NULL) 
              AND status = 'published' 
              ORDER BY created_at DESC";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute(['user_id' => $userId]);
    $items = $stmt->fetchAll();

    echo json_encode([
        "success" => true,
        "content" => $items
    ]);

} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    http_response_code(500);
}