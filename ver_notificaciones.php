<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}
require 'config/db.php';

$userId   = $_SESSION['user_id'] ?? null;
$esAdmin  = ($_SESSION['role'] ?? '') === 'admin';
$areaUsuario = $_SESSION['area'] ?? null;
$divisionName = $_SESSION['division_name'] ?? null; // área guardada en sesión

// 1) Marcar todas como leídas al abrir esta página
$stmt = $conn->prepare("UPDATE notificaciones SET leido = 1 WHERE user_id = ?");
$stmt->execute([$userId]);

// 2) Traer todas las notificaciones (más recientes primero)
$stmt = $conn->prepare("SELECT id, mensaje, link, leido, created_at 
                        FROM notificaciones 
                        WHERE user_id = ?
                        ORDER BY created_at DESC");
$stmt->execute([$userId]);
$notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mis Notificaciones</title>
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">

  <!-- Bootstrap Icons local -->
  <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
  <style>
    .notif-item{gap:.75rem}
    .notif-meta{color:#6c757d;font-size:.9rem}
    .notif-link{white-space:nowrap}
  </style>
</head>
<body class="bg-light">

<!-- NAV coherente con el resto -->
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
  <div class="container-fluid d-flex justify-content-between align-items-center">
    <img src="assets/logo.png" alt="Sonda Logo" height="120">
    <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>
    <div class="d-flex align-items-center">
<span class="me-3 text-white">
                Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?>
                / (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                <?php if ($divisionName): ?>
                    <span class="badge text-bg-secondary ms-2"><?= htmlspecialchars($divisionName) ?></span>
                <?php endif; ?>
                
            </span>

      <?php if (!$esAdmin): ?>
        <a href="mis_solicitudes.php" class="btn btn-outline-warning me-2">📑 Mis Solicitudes</a>
      <?php endif; ?>
            <a href="mis_solicitudes.php" class="btn btn-outline-warning me-2">📑 Mis Solicitudes</a>
            <a href="dashboard.php" class="btn btn-outline-light me-2">🏠 Dashboard</a>
            <a href="logout.php" class="btn btn-outline-light">Cerrar sesión</a>
    </div>
  </div>
</nav>

<div class="container mt-5">
  <h3 class="mb-4">🔔 Mis Notificaciones</h3>

  <?php if (empty($notificaciones)): ?>
    <div class="alert alert-info">No tienes notificaciones.</div>
  <?php else: ?>
    <div class="list-group shadow-sm">
      <?php foreach ($notificaciones as $n): ?>
        <div class="list-group-item d-flex justify-content-between align-items-start notif-item">
          <div class="flex-fill">
            <div><?= htmlspecialchars($n['mensaje']) ?></div>
            <div class="notif-meta"><?= htmlspecialchars($n['created_at']) ?></div>
          </div>
          <?php if (!empty($n['link'])): ?>
            <a class="btn btn-sm btn-primary notif-link" href="<?= htmlspecialchars($n['link']) ?>">Abrir</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="mt-3">
    <a href="dashboard.php" class="btn btn-secondary">Volver</a>
  </div>
</div>

</body>
</html>
