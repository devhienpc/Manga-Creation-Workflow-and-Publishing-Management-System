<?php
// File test kết nối Cloudflare API - Điền API token của bạn vào đây để test
$apiToken = 'your_cloudflare_api_token_here'; // Thay bằng token thật khi test

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.cloudflare.com/client/v4/user/tokens/verify");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "Authorization: Bearer " . $apiToken
));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$result = curl_exec($ch);
echo $result;
?>
