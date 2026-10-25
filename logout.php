<?php
require __DIR__ . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (user()) audit('Signed out');
    $_SESSION = [];
    session_destroy();
}
redirect('login.php');
