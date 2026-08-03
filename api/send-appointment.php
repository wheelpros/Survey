<?php
require 'db.php';

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data['user_id'];
$date = $data['date'];
$time = $data['time'];

$stmt = $pdo->prepare("INSERT INTO appointments (user_id, date, time, status) VALUES (?, ?, ?, 'approved')");
$stmt->execute([$user_id, $date, $time]);

echo json_encode([
  "success" => true,
  "message" => "Appointment sent مباشرة ✅"
]);