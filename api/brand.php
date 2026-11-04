<?php

/*
|--------------------------------------------------------------------------
| The portal's name, footer and support channels
|--------------------------------------------------------------------------
|
| Public, because the login page shows them before anyone has signed in. Only
| what brandSettings() lists leaves here. assets/brand.js is the reader.
|
*/

require_once "db.php";

header("Cache-Control: no-cache, must-revalidate");

$brand = brandSettings($pdo);

$whatsapp = $brand["support_whatsapp"];
unset($brand["support_whatsapp"]);
$brand["support_whatsapp_link"] = whatsAppLink($whatsapp);

echo json_encode($brand, JSON_UNESCAPED_UNICODE);
