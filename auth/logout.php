<?php
require_once __DIR__ . '/../config/constants.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION = array();
session_destroy();
header('Location: ' . BASE_URL . 'auth/login.php');
exit();
