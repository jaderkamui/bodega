<?php
session_start();
if (!isset($_SESSION['user'])) { 
    header("Location: index.php"); 
    exit; 
}
require 'config/db.php';

$esAdmin = ($_SESSION['role'] ?? '') === 'admin';
if ($esAdmin) {
    header("Location: dashboard.php");
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$areaUsuario = $_SESSION['area'] ?? null;
$divisionName = $_SESSION['division_name'] ?? null;
$bodegasIds = $_SESSION['bodegas_ids'] ?? [];

// Notificaciones
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$userId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);

$mark = $conn->prepare("UPDATE notificaciones SET leido = 1 WHERE user_id = ? AND link = 'solicitud_masiva.php'");
$mark->execute([$userId]);

// Bodegas disponibles
$bodegas = [];
$selectedBodega = isset($_GET['bodega_id']) ? (int)$_GET['bodega_id'] : 0;

if (!empty($bodegasIds)) {
    $in = implode(',', array_fill(0, count($bodegasIds), '?'));
    $stmtB = $conn->prepare("SELECT id, nombre FROM bodegas WHERE activa = 1 AND id IN ($in) ORDER BY nombre");
    $stmtB->execute($bodegasIds);
    $bodegas = $stmtB->fetchAll(PDO::FETCH_ASSOC);

    if ($selectedBodega === 0 && !empty($bodegas)) {
        $selectedBodega = (int)$bodegas[0]['id'];
    }
}

// Productos por bodega + área
$products = [];
if ($selectedBodega && $areaUsuario) {
    $stmtP = $conn->prepare("
        SELECT p.*, b.nombre AS bodega_nombre
        FROM products p
        LEFT JOIN bodegas b ON b.id = p.bodega_id
        WHERE p.area = ? AND p.bodega_id = ?
        ORDER BY p.description ASC
    ");
    $stmtP->execute([$areaUsuario, $selectedBodega]);
    $products = $stmtP->fetchAll(PDO::FETCH_ASSOC);
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket   = trim($_POST['ticket'] ?? '0');
    $detalle  = trim($_POST['detalle'] ?? '');
    $bodegaId = (int)($_POST['bodega_id'] ?? 0);

    $qty = $_POST['qty'] ?? [];
    $items = [];
    foreach ($qty as $pid => $q) {
        $pid = (int)$pid;
        $q   = (int)$q;
        if ($pid > 0 && $q > 0) $items[$pid] = $q;
    }

    if ($bodegaId <= 0) $errores[] = "Debes seleccionar una bodega.";
    if (empty($items)) $errores[] = "Debes ingresar cantidad mayor a 0 en al menos un producto.";
    if (!$areaUsuario) $errores[] = "Tu usuario no tiene área asignada.";
    if (!empty($bodegasIds) && $bodegaId > 0 && !in_array($bodegaId, $bodegasIds, true)) {
        $errores[] = "Bodega no autorizada para tu usuario.";
    }

    if (empty($errores)) {
        $conn->beginTransaction();
        try {
            // Insert cabecera
            $ins = $conn->prepare("
                INSERT INTO solicitudes (user_id, ticket, detalle, bodega_id, estado_general, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'Pendiente', NOW(), NOW())
            ");
            $ins->execute([$userId, $ticket ?: '0', $detalle ?: null, $bodegaId]);
            $solicitudId = (int)$conn->lastInsertId();

            // Insert ítems
            $insIt = $conn->prepare("
                INSERT INTO solicitud_items (solicitud_id, product_id, cant_solicitada, cant_aprobada, cant_entregada, estado_item, created_at)
                VALUES (?, ?, ?, 0, 0, 'Pendiente', NOW())
            ");
            foreach ($items as $pid => $q) {
                $insIt->execute([$solicitudId, $pid, $q]);
            }

            // Log unificado
            $log = $conn->prepare("
                INSERT INTO logs (user_id, action, details, created_at) 
                VALUES (?, 'solicitud_creada', ?, NOW())
            ");
            $log->execute([
                $userId,
                sprintf(
                    "Solicitud masiva creada ID %d: %d ítems — Ticket: %s — Usuario: %s — Área: %s — Bodega ID %d",
                    $solicitudId,
                    count($items),
                    $ticket ?: '0',
                    $_SESSION['user'],
                    $areaUsuario,
                    $bodegaId
                )
            ]);

            // Notificar admins
            $admins = $conn->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);

            $mensajeNoti = "📢 Nueva solicitud **masiva** #{$solicitudId} de {$_SESSION['user']} — "
                         . count($items) . " ítems — Ticket: " . ($ticket ?: '0') . " — Área: {$areaUsuario}";

            $notif = $conn->prepare("
                INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at) 
                VALUES (?, ?, 'admin_solicitudes.php', 0, NOW())
            ");
            foreach ($admins as $adminId) {
                $notif->execute([(int)$adminId, $mensajeNoti]);
            }

            $conn->commit();

            header("Location: mis_solicitudes.php?success=mass&sid={$solicitudId}");
            exit;

        } catch (Throwable $e) {
            $conn->rollBack();
            $errores[] = "Error al crear la solicitud masiva: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Solicitud Masiva</title>
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
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $notiCount ?></span>
          <?php endif; ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php if ($notiCount === 0): ?>
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
        </ul>
      </div>

      <a href="dashboard.php" class="btn btn-outline-light btn-sm me-1">🏠</a>
      <a href="mis_solicitudes.php" class="btn btn-outline-warning btn-sm me-1">📑</a>
      <a href="logout.php" class="btn btn-outline-light btn-sm">⏻</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">🧾 Crear Solicitud Masiva</h4>
    <form method="get" class="d-flex gap-2">
      <select name="bodega_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($bodegas as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id'] === (int)$selectedBodega) ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (!empty($errores)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="card shadow-sm">
    <div class="card-body">
      <input type="hidden" name="bodega_id" value="<?= (int)$selectedBodega ?>">

      <div class="row g-2 mb-3">
        <div class="col-md-3">
          <label class="form-label">Ticket</label>
          <input type="text" name="ticket" class="form-control" value="<?= htmlspecialchars($_POST['ticket'] ?? '0') ?>">
          <div class="form-text">Si no tienes número aún, deja 0.</div>
        </div>
        <div class="col-md-9">
          <label class="form-label">Detalle del trabajo</label>
          <input type="text" name="detalle" class="form-control" value="<?= htmlspecialchars($_POST['detalle'] ?? '') ?>">
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead class="table-dark">
            <tr>
              <th>Producto</th>
              <th>Ubicación</th>
              <th class="text-end">Stock</th>
              <th class="text-end" style="width:120px;">Cantidad</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($products)): ?>
              <tr><td colspan="4" class="text-muted">No hay productos disponibles para esta bodega/área.</td></tr>
            <?php else: foreach ($products as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['description']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($p['ubicacion'] ?? '—') ?></td>
                <td class="text-end"><?= (int)$p['quantity'] ?></td>
                <td>
                  <input type="number" min="0" step="1" class="form-control form-control-sm text-end" 
                         name="qty[<?= (int)$p['id'] ?>]" value="<?= htmlspecialchars($_POST['qty'][(int)$p['id']] ?? '0') ?>">
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="d-flex justify-content-end gap-2 mt-3">
        <a href="dashboard.php" class="btn btn-outline-secondary">Cancelar</a>
        <button type="submit" class="btn btn-success">Enviar solicitud masiva</button>
      </div>
    </div>
  </form>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>