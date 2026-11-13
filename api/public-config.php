<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['clarity_id' => getenv('zcr1fn30k8') ?: null]);