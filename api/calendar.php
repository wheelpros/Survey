<?php
// إعدادات الـ CORS والسماح بالطلبات
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if(isset($_GET["action"]) && $_GET["action"]=="send_appointment"){

    $input=json_decode(file_get_contents("php://input"),true);

    $stmt=$pdo->prepare("
    INSERT INTO appointments
    (
        user_id,
        title,
        date,
        time,
        status
    )
    VALUES(?,?,?,?,?)
    ");

    $stmt->execute([

        $input["user_id"],
        $input["title"],
        $input["date"],
        $input["time"],
        "approved"

    ]);

    echo json_encode([
        "success"=>true,
        "message"=>"Appointment sent successfully."
    ]);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "db.php";

// استخراج الـ Bearer Token من الهيدر
$headers = getallheaders();
$authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? "";
$token = str_replace("Bearer ", "", $authHeader);

if (!$token) {
    echo json_encode(["success" => false, "message" => "No token provided"]);
    exit;
}

// فحص هويّة المستخدم (User أو Admin)
$stmt = $pdo->prepare("SELECT id FROM users WHERE session_token = ? LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$adminId = null;
if (!$user) {
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE session_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        echo json_encode(["success" => false, "message" => "Unauthorized token"]);
        exit;
    }
    $adminId = $admin['id'];
}

// استلام الأكشن سواء من GET أو POST
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// قراءة بيانات الـ JSON Input للطلبات المخفية
$inputData = json_decode(file_get_contents("php://input"), true) ?? [];

// ---------------- USER ACTIONS ----------------

// 1. تحديد وقفل وقت/يوم غير متاح
if ($action === 'set_unavailability' && $user) {
    $date = $inputData['date'] ?? $_POST['date'] ?? '';
    $startTime = $inputData['start_time'] ?? $_POST['start_time'] ?? '00:00:00';
    $endTime = $inputData['end_time'] ?? $_POST['end_time'] ?? '23:59:59';

    if (!$date) {
        echo json_encode(["success" => false, "message" => "Date required"]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO user_unavailability (user_id, date, start_time, end_time) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user['id'], $date, $startTime, $endTime]);

    echo json_encode(["success" => true, "message" => "Time slot blocked successfully"]);
    exit;
}

// 2. تحديث حالة الموعد (قبول / رفض) من اليوزر
if ($action === 'respond_appointment' && $user) {
    $appointmentId = $inputData['id'] ?? $_POST['id'] ?? 0;
    $status = $inputData['status'] ?? $_POST['status'] ?? '';

    if (!in_array($status, ['approved', 'rejected'])) {
        echo json_encode(["success" => false, "message" => "Invalid status"]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$status, $appointmentId, $user['id']]);

    echo json_encode(["success" => true, "message" => "Appointment status updated"]);
    exit;
}

// 3. جلب بيانات التقويم والمواعيد لليوزر
if ($action === 'get_user_calendar' && $user) {
    $stmt = $pdo->prepare("SELECT * FROM user_unavailability WHERE user_id = ? ORDER BY date DESC");
    $stmt->execute([$user['id']]);
    $blocked = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE user_id = ? ORDER BY date DESC, time DESC");
    $stmt->execute([$user['id']]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "blocked" => $blocked,
        "appointments" => $appointments
    ]);
    exit;
}

// ---------------- ADMIN ACTIONS ----------------

// 4. حجز موعد جديد للمستخدم بواسطة الأدمن
if ($action === 'create_appointment' && $adminId) {
    $targetUserId = $inputData['user_id'] ?? $_POST['user_id'] ?? 0;
    $title = $inputData['title'] ?? $_POST['title'] ?? 'Meeting';
    $date = $inputData['date'] ?? $_POST['date'] ?? '';
    $time = $inputData['time'] ?? $_POST['time'] ?? '';

    if (!$targetUserId || !$date || !$time) {
        echo json_encode(["success" => false, "message" => "Missing required appointment fields"]);
        exit;
    }

    // التحقق من أن الوقت غير مغلق من المستخدم
    $stmt = $pdo->prepare("SELECT id FROM user_unavailability WHERE user_id = ? AND date = ? AND (? BETWEEN start_time AND end_time)");
    $stmt->execute([$targetUserId, $date, $time]);
    if ($stmt->fetch()) {
        echo json_encode(["success" => false, "message" => "User is unavailable at this date/time!"]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO appointments (user_id, title, date, time, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->execute([$targetUserId, $title, $date, $time]);

    echo json_encode(["success" => true, "message" => "Appointment request sent to user"]);
    exit;
}

// 5. جلب بيانات التقويم الخاصة بمستخدم معين للأدمن
if ($action === 'get_admin_calendar_data' && $adminId) {
    $targetUserId = $_GET['user_id'] ?? $inputData['user_id'] ?? 0;

    $stmt = $pdo->prepare("SELECT * FROM user_unavailability WHERE user_id = ?");
    $stmt->execute([$targetUserId]);
    $blocked = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE user_id = ?");
    $stmt->execute([$targetUserId]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "blocked" => $blocked,
        "appointments" => $appointments
    ]);
    exit;
}

// في حالة عدم مطابقة أي شرط للأكشن
echo json_encode(["success" => false, "message" => "Invalid action or permission denied"]);
?>