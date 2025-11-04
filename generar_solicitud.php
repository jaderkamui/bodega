<?php
session_start();
if (!isset($_SESSION['user'])) { 
    header("Location: index.php"); 
    exit; 
}
require 'config/db.php';

$userId       = $_SESSION['user_id'];
$rol          = $_SESSION['role'] ?? 'lector';
$esAdmin      = ($rol === 'admin');
$areaUsuario  = $_SESSION['area'] ?? null;
$bodegasIds   = $_SESSION['bodegas_ids'] ?? []; // array de IDs de bodegas permitidas
$divisionName = $_SESSION['division_name'] ?? null;

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$mensaje   = '';

if (!$productId) {
    header("Location: dashboard.php");
    exit;
}

// === Obtener info del producto + bodega ===
$stmt = $conn->prepare("
    SELECT p.*, 
           b.id   AS bodega_id,
           b.nombre AS bodega_nombre,
           b.division AS division_nombre
    FROM products p
    LEFT JOIN bodegas b ON b.id = p.bodega_id
    WHERE p.id = ?
");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $mensaje = "Producto no encontrado.";
}

// === Control de acceso (no admin) ===
// - Debe pertenecer a una bodega asignada al usuario
// - Debe coincidir el área del producto con el área del usuario
if ($product && !$esAdmin) {
    $bodegaOk = in_array((int)$product['bodega_id'], array_map('intval', $bodegasIds), true);
    $areaOk   = ($product['area'] === $areaUsuario);

    if (!$bodegaOk || !$areaOk) {
        $mensaje = "No tienes permisos para solicitar este producto (bodega/área no autorizada).";
        // Para evitar manipulación del form, anulamos el producto
        $product = null;
    }
}

// === Guardar solicitud ===
if ($product && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cantidad = max(0, (int)($_POST['cantidad'] ?? 1));
    $ticket   = trim($_POST['ticket'] ?? "0");
    $detalle  = trim($_POST['detalle'] ?? "");

    if ($cantidad <= 0) {
        $mensaje = "La cantidad debe ser mayor a 0.";
    } else {
        // (Opcional) Validación contra stock actual
        $stockDisponible = (int)($product['quantity'] ?? 0);
        if ($stockDisponible > 0 && $cantidad > $stockDisponible) {
            $mensaje = "La cantidad solicitada excede el stock disponible ({$stockDisponible}).";
        } else {
            // Insertar solicitud
            $insert = $conn->prepare("
                INSERT INTO solicitudes (product_id, user_id, cantidad, ticket, detalle, estado, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, 'Pendiente', NOW(), NOW())
            ");
            $insert->execute([$productId, $userId, $cantidad, $ticket, $detalle]);

            // Obtener ID de la solicitud recién creada
            $solicitudId = $conn->lastInsertId();

            // Insertar en logs
            $log = $conn->prepare("
                INSERT INTO logs (user_id, action, details, created_at) 
                VALUES (?, 'request_created', ?, NOW())
            ");
            $log->execute([
                $userId,
                sprintf(
                    "Usuario %s (ID %d) creó solicitud ID %d: Producto '%s' (ID %d) — Cantidad: %d — Ticket: %s — Bodega: %s — División: %s",
                    $_SESSION['user'],
                    $userId,
                    $solicitudId,
                    $product['description'],
                    $productId,
                    $cantidad,
                    $ticket !== '' ? $ticket : '0',
                    $product['bodega_nombre'] ?? '—',
                    $product['division_nombre'] ?? '—'
                )
            ]);

            // 📌 Notificación a administradores
            $stmtAdmins = $conn->query("SELECT id FROM users WHERE role = 'admin'");
            $admins = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN);

            $mensajeNoti = "📢 Nueva solicitud de {$_SESSION['user']}: "
                         . "{$product['description']} — Cant: {$cantidad} — Ticket: {$ticket} — "
                         . "Bodega: " . ($product['bodega_nombre'] ?? '—') . " — División: " . ($product['division_nombre'] ?? '—');

            foreach ($admins as $adminId) {
                $notif = $conn->prepare("
                    INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at) 
                    VALUES (?, ?, ?, 0, NOW())
                ");
                $notif->execute([$adminId, $mensajeNoti, "admin_solicitudes.php"]);
            }

            header("Location: mis_solicitudes.php?success=1");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Solicitud</title>
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">

  <!-- Bootstrap Icons local -->
  <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <img src="assets/logo.png" alt="Sonda Logo" height="120">
            <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>
            <div>
            <span class="me-3 text-white">
                Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?>
                / (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                <?php if ($divisionName): ?>
                    <span class="badge text-bg-secondary ms-2"><?= htmlspecialchars($divisionName) ?></span>
                <?php endif; ?>
            </span>
            <a href="dashboard.php" class="btn btn-outline-light me-2">🏠 Dashboard</a>
            <a href="logout.php" class="btn btn-outline-light">Cerrar sesión</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <h3>Generar Solicitud de Producto</h3>

        <?php if ($mensaje): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <?php if ($product): ?>
        <form method="post" class="card p-4 shadow-sm">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Producto</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($product['description']) ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Bodega</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($product['bodega_nombre'] ?? '—') ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">División</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($product['division_nombre'] ?? '—') ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Área</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($product['area'] ?? '—') ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Stock disponible</label>
                    <input type="text" class="form-control" value="<?= (int)($product['quantity'] ?? 0) ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Ubicación</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($product['ubicacion'] ?? '') ?>" readonly>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Cantidad</label>
                    <input type="number" name="cantidad" value="1" min="1" class="form-control" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Número de Ticket</label>
                    <input type="text" name="ticket" value="0" class="form-control" required>
                    <small class="text-muted">Si no tienes número aún, deja “0” y actualízalo después.</small>
                </div>

                <div class="col-12">
                    <label class="form-label">Detalle del trabajo</label>
                    <textarea name="detalle" class="form-control" rows="3" placeholder="Ej: Tornillos para trabajo con ticket 123456"></textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">📦 Realizar Solicitud</button>
                <a href="dashboard.php" class="btn btn-secondary">Cancelar</a>
            </div>
        </form>
        <?php else: ?>
            <div class="alert alert-info mt-3">No es posible generar la solicitud para este producto.</div>
        <?php endif; ?>
    </div>
</body>
</html>
