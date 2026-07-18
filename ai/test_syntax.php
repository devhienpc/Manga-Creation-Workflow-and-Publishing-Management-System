<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
try {
    require_once '../api/ai_colorize.php';
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine();
}
?>
