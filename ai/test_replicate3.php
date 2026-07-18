<?php
require_once '../config/constants.php';
$apiToken = REPLICATE_API_TOKEN;
$version = '435061a1b5a4c1e26740464bf786efdfa9cb3a3ac488595a2723f436dcb73b379';
$base64Image = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

$payload = json_encode([
    'version' => $version,
    'input' => [
        'image' => $base64Image,
        'prompt' => 'test prompt',
        'a_prompt' => 'test a',
        'n_prompt' => 'test n',
        'ddim_steps' => 20,
        'image_resolution' => 512
    ]
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://api.replicate.com/v1/predictions",
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Token ' . $apiToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
]);
echo "POST Result: " . curl_exec($ch);
?>
