<?php
// admin/index.php - /admin/ adresi: oturum varsa panele, yoksa girişe (ilk kurulumda kurulum sayfasına) yönlendirir.
define('PANEL_GIRISSIZ', true);
require_once __DIR__ . '/_baslat.php';

Panel::yonlendir(!empty($_SESSION['admin_id']) ? 'dashboard' : 'login');
