<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../config/constants.php';

$apiToken = REPLICATE_API_TOKEN;
$version = '435061a1b5a4c1e26740464bf786efdfa9cb3a3ac488595a2723f436dcb73b379';
// using a dummy tiny image base64
$base64Image = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

$payload = json_encode([
    'version' => $version,
    'input' => [
        'image' => $base64Image,
        'prompt' => 'anime style, beautiful colors',
    ]
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://api.replicate.com/v1/predictions",
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res = curl_exec($ch);
echo "Result: " . $res;
?>
