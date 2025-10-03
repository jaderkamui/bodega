<?php
session_start();
if (!isset($_SESSION['user'])) { 
    header("Location: index.php"); 
    exit; 
}
require 'config/db.php';

$userId = $_SESSION['user_id'];
$productId = $_GET['product_id'] ?? null;
$mensaje = '';

if (!$productId) {
    header("Location: dashboard.php");
    exit;
}

// Obtener info del producto
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $mensaje = "Producto no encontrado.";
}

// Guardar solicitud
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cantidad = (int)($_POST['cantidad'] ?? 1);
    $ticket   = trim($_POST['ticket'] ?? "0");
    $detalle  = trim($_POST['detalle'] ?? "");

    if ($cantidad > 0) {
        // Insertar solicitud
        $insert = $conn->prepare("INSERT INTO solicitudes (product_id, user_id, cantidad, ticket, detalle, estado, created_at, updated_at) 
                                   VALUES (?, ?, ?, ?, ?, 'Pendiente', NOW(), NOW())");
        $insert->execute([$productId, $userId, $cantidad, $ticket, $detalle]);

        // Obtener ID de la solicitud recién creada
        $solicitudId = $conn->lastInsertId();

        // Insertar en logs
        $log = $conn->prepare("INSERT INTO logs (user_id, action, details, created_at) VALUES (?, 'request_created', ?, NOW())");
        $log->execute([
            $userId,
            "Usuario {$userId} creó la solicitud ID {$solicitudId} para producto {$productId} ({$cantidad} unidades, ticket: {$ticket}, detalle: {$detalle})"
        ]);

        // 📌 Insertar notificación para administradores
        $stmtAdmins = $conn->query("SELECT id FROM users WHERE role = 'admin'");
        $admins = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN);

        foreach ($admins as $adminId) {
            $notif = $conn->prepare("INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at) 
                                     VALUES (?, ?, ?, 0, NOW())");
            $notif->execute([
                $adminId,
                "📢 Nueva solicitud creada por {$_SESSION['user']} - Producto: {$product['description']}, Cantidad: {$cantidad}, Ticket: {$ticket}",
                "admin_solicitudes.php"
            ]);
        }

        header("Location: mis_solicitudes.php?success=1");
        exit;
    } else {
        $mensaje = "La cantidad debe ser mayor a 0.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Solicitud</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
        <nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <img src="assets/logo.png" alt="Sonda Logo" height="120">
            <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>
            <div>
                <span class="me-3 text-white">Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?> / (<?= $_SESSION['role'] ?>)</span>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver</a>
            </div>
        </div>
    </nav>
<div class="container mt-5">
    <h3>Generar Solicitud de Producto</h3>
    <?php if ($mensaje): ?>
        <div class="alert alert-warning"><?= $mensaje ?></div>
    <?php endif; ?>

    <?php if ($product): ?>
    <form method="post" class="card p-4 shadow-sm">
        <div class="mb-3">
            <label class="form-label">Producto</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($product['description']) ?>" readonly>
        </div>
        <div class="mb-3">
            <label class="form-label">Cantidad</label>
            <input type="number" name="cantidad" value="1" min="1" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Número de Ticket</label>
            <input type="text" name="ticket" value="0" class="form-control" required>
            <small class="text-muted">Si no tienes número de ticket aún, puedes dejar "0" y actualizarlo después.</small>
        </div>
        <div class="mb-3">
            <label class="form-label">Detalle del trabajo</label>
            <textarea name="detalle" class="form-control" rows="3" placeholder="Ej: Tornillos para trabajo con ticket 123456"></textarea>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">📦 Realizar Solicitud</button>
            <a href="dashboard.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
