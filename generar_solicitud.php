<?php
session_start();
if (!isset($_SESSION['user'])) { 
    header("Location: index.php"); 
    exit; 
}
require 'config/db.php';

$userId       = (int)($_SESSION['user_id'] ?? 0);
$rol          = $_SESSION['role'] ?? 'lector';
$esAdmin      = ($rol === 'admin');
$areaUsuario  = $_SESSION['area'] ?? null;
$bodegasIds   = $_SESSION['bodegas_ids'] ?? [];
$divisionName = $_SESSION['division_name'] ?? null;

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$mensaje   = '';

if (!$productId) {
    header("Location: dashboard.php");
    exit;
}

/* Notificaciones navbar */
$countStmt = $conn->prepare("SELECT COUNT(*) FROM notificaciones WHERE user_id = ? AND leido = 0");
$countStmt->execute([$userId]);
$notiCount = (int)$countStmt->fetchColumn();

$notiStmt = $conn->prepare("SELECT id, mensaje, link, leido, created_at
                            FROM notificaciones
                            WHERE user_id = ?
                            ORDER BY created_at DESC
                            LIMIT 5");
$notiStmt->execute([$userId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);

/* Obtener info del producto + bodega */
$stmt = $conn->prepare("
    SELECT p.*, 
           b.id       AS bodega_id,
           b.nombre   AS bodega_nombre,
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

/* Control de acceso (no admin) */
if ($product && !$esAdmin) {
    $bodegaOk = in_array((int)$product['bodega_id'], array_map('intval', $bodegasIds), true);
    $areaOk   = (($product['area'] ?? null) === $areaUsuario);

    if (!$bodegaOk || !$areaOk) {
        $mensaje = "No tienes permisos para solicitar este producto (bodega/área no autorizada).";
        $product = null;
    }
}

/* Guardar solicitud (cabecera + item) */
if ($product && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cantidad = max(0, (int)($_POST['cantidad'] ?? 1));
    $ticket   = trim($_POST['ticket'] ?? "0");
    $detalle  = trim($_POST['detalle'] ?? "");

    if ($cantidad <= 0) {
        $mensaje = "La cantidad debe ser mayor a 0.";
    } else {
        $stockDisponible = (int)($product['quantity'] ?? 0);
        if ($stockDisponible > 0 && $cantidad > $stockDisponible) {
            $mensaje = "La cantidad solicitada excede el stock disponible ({$stockDisponible}).";
        } else {
            try {
                $conn->beginTransaction();

                // 1) Insert cabecera
                $bodegaId = (int)($product['bodega_id'] ?? 0);

                $insS = $conn->prepare("
                    INSERT INTO solicitudes (user_id, ticket, detalle, bodega_id, estado_general, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 'Pendiente', NOW(), NOW())
                ");
                $insS->execute([
                    $userId,
                    ($ticket !== '' ? $ticket : '0'),
                    $detalle,
                    $bodegaId > 0 ? $bodegaId : null
                ]);

                $solicitudId = (int)$conn->lastInsertId();

                // 2) Insert item
                $insI = $conn->prepare("
                    INSERT INTO solicitud_items
                        (solicitud_id, product_id, cant_solicitada, cant_aprobada, cant_entregada, estado_item, created_at, updated_at)
                    VALUES
                        (?, ?, ?, 0, 0, 'Pendiente', NOW(), NOW())
                ");
                $insI->execute([$solicitudId, $productId, $cantidad]);

                // 3) Log unificado
                $log = $conn->prepare("
                    INSERT INTO logs (user_id, action, details, created_at) 
                    VALUES (?, 'solicitud_creada', ?, NOW())
                ");
                $log->execute([
                    $userId,
                    sprintf(
                        "Solicitud creada ID %d: Producto '%s' (ID %d) — Cantidad: %d — Ticket: %s — Bodega: %s — División: %s — Usuario: %s",
                        $solicitudId,
                        $product['description'],
                        $productId,
                        $cantidad,
                        $ticket !== '' ? $ticket : '0',
                        $product['bodega_nombre'] ?? '—',
                        $product['division_nombre'] ?? '—',
                        $_SESSION['user']
                    )
                ]);

                // 4) Notificar admins
                $admins = $conn->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);

                $mensajeNoti = "📢 Nueva solicitud de {$_SESSION['user']}: "
                             . "{$product['description']} — Cant: {$cantidad} — Ticket: {$ticket} — "
                             . "Bodega: " . ($product['bodega_nombre'] ?? '—') . " — División: " . ($product['division_nombre'] ?? '—')
                             . " — Solicitud #{$solicitudId}";

                $notif = $conn->prepare("
                    INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at) 
                    VALUES (?, ?, ?, 0, NOW())
                ");
                foreach ($admins as $adminId) {
                    $notif->execute([(int)$adminId, $mensajeNoti, "admin_solicitudes.php"]);
                }

                $conn->commit();

                // Redirección con mensaje de éxito
                header("Location: mis_solicitudes.php?success=1&solicitud_id={$solicitudId}");
                exit;

            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $mensaje = "Error al crear la solicitud: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Solicitud</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" class="navbar-logo">
        <span class="navbar-brand text-white mb-0 navbar-brand-title">Sistema de Bodega</span>
        <div class="d-flex align-items-center">
            <span class="me-2 text-white d-none d-md-inline">
                <?= htmlspecialchars($_SESSION['user']) ?>
                (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                <?php if ($divisionName): ?>
                    <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars($divisionName) ?></span>
                <?php endif; ?>
            </span>

            <div class="dropdown me-2">
                <button class="btn btn-outline-light btn-sm position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    🔔
                    <?php if ($notiCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $notiCount ?>
                        </span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="max-height:300px;overflow:auto;">
                    <?php if (empty($notificaciones)): ?>
                        <li><span class="dropdown-item-text text-muted">No tienes notificaciones nuevas</span></li>
                    <?php else: foreach ($notificaciones as $n): ?>
                        <li>
                            <a class="dropdown-item" href="<?= htmlspecialchars($n['link']) ?>">
                                <?= htmlspecialchars($n['mensaje']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($n['created_at']) ?></small>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                    <?php endforeach; endif; ?>
                    <li><a class="dropdown-item text-center" href="ver_notificaciones.php">📜 Ver todas</a></li>
                </ul>
            </div>

            <a href="dashboard.php" class="btn btn-outline-light btn-sm me-1">🏠</a>
            <a href="logout.php" class="btn btn-outline-light btn-sm">⏻</a>
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
                <input type="number" name="cantidad" value="1" min="1" max="<?= (int)($product['quantity'] ?? 9999) ?>" 
                       class="form-control" required>
                <small class="text-muted">Máximo disponible: <?= (int)($product['quantity'] ?? 0) ?></small>
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

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>