<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

require 'config/db.php';

$userId = $_SESSION['user_id'];

// 1. Marcar todas las notificaciones como leídas al abrir esta página
$stmt = $conn->prepare("UPDATE notificaciones SET leido = 1 WHERE user_id = ?");
$stmt->execute([$userId]);

// 2. Obtener notificaciones del usuario (ordenadas de más reciente a más antigua)
$stmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mis Notificaciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>
        <div>
            <span class="me-3 text-white">
                Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?> (<?= htmlspecialchars($_SESSION['role']) ?>)
            </span>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver</a>
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
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <?= htmlspecialchars($n['mensaje']) ?>
                        <br>
                        <small class="text-muted"><?= $n['created_at'] ?></small>
                    </div>
                    <?php if ($n['leido'] == 0): ?>
                        <span class="badge bg-danger">Nuevo</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
