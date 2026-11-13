<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['clarity_id' => getenv('CLARITY_PROJECT_ID') ?: null]);