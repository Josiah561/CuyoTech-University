<?php
require __DIR__ . '/includes/bootstrap.php';
$u = user();
redirect($u ? home_for($u['role']) : 'login.php');
