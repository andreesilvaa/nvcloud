<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: app.php?page=dashboard');
    exit;
}

header('Location: login.php');
exit;