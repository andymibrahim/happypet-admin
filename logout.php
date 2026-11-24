<?php
require_once __DIR__ . '/config/database.php';

if (!empty($_SESSION['happypet_admin_id'])) {
    $db = HappyPetDB::getInstance();
    $adminId = (int)$_SESSION['happypet_admin_id'];
    $db->logActivity($adminId, 'admin_logout', 'Administrator keluar dari Panel Admin HappyPet.', 'admin_auth', (string)$adminId);
}

unset($_SESSION['happypet_admin_id'], $_SESSION['happypet_admin_name'], $_SESSION['happypet_admin_email']);

header('Location: login.php?logged_out=1');
exit;
