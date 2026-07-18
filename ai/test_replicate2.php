<?php
require_once '../config/constants.php';
$apiToken = REPLICATE_API_TOKEN;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://api.replicate.com/v1/predictions",
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiToken,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res = curl_exec($ch);
echo "Replicate API Status: " . $res;
?>
