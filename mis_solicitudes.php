<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}
require 'config/db.php';

$userId  = (int)($_SESSION['user_id'] ?? 0);
$mensaje = '';

/* Helpers: detectar columnas (compatibilidad) */
function columnExists(PDO $conn, string $table, string $column): bool {
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

$hasEstadoGeneral = columnExists($conn, 'solicitudes', 'estado_general');
$hasEstadoOld     = columnExists($conn, 'solicitudes', 'estado');
$hasProductId     = columnExists($conn, 'solicitudes', 'product_id');

/* Marcar notificaciones leídas */
$stmt = $conn->prepare("UPDATE notificaciones
                        SET leido = 1
                        WHERE user_id = ? AND link = 'mis_solicitudes.php'");
$stmt->execute([$userId]);

/* Notificaciones (campanita) */
$notiStmt = $conn->prepare("SELECT id, mensaje, link, leido, created_at
                            FROM notificaciones
                            WHERE user_id = ?
                            ORDER BY created_at DESC
                            LIMIT 5");
$notiStmt->execute([$userId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count(array_filter($notificaciones, fn($n) => (int)($n['leido'] ?? 0) === 0));

$areaUsuario  = $_SESSION['area'] ?? '';
$divisionName = $_SESSION['division_name'] ?? null;

/* BADGES - solo 4 estados + voucher + calculados */
function badgeColor($estado) {
    $map = [
        "Pendiente"         => "secondary",
        "Aprobada"          => "primary",
        "Rechazada"         => "danger",
        "Entregado"         => "success",

        // Estados visibles calculados
        "Parcial entregada" => "warning",
        "Parcial rechazada" => "warning",
        "Cerrada"           => "success",

        // Voucher
        "PENDIENTE_ADMIN"   => "secondary",
        "PENDIENTE_USUARIO" => "warning",
        "COMPLETADA"        => "success"
    ];
    return $map[$estado] ?? "dark";
}

/* Estado visible para el usuario (simplificado con 4 estados) */
function estadoVisibleUsuario(array $s): array {
    $total = (int)($s['total_items'] ?? 0);
    if ($total <= 0) {
        return ['Pendiente', 'secondary', false];
    }

    $pend = (int)($s['c_pendiente'] ?? 0);
    $apr  = (int)($s['c_aprobada'] ?? 0);
    $rej  = (int)($s['c_rechazada'] ?? 0);
    $ent  = (int)($s['c_entregado'] ?? 0);

    // Rechazada total
    if ($ent === 0 && $rej === $total) {
        return ['Rechazada', 'danger', true];
    }

    // Cerrada: todo resuelto
    if (($ent + $rej) === $total) {
        return ['Cerrada', 'success', true];
    }

    // Parcial entregada: hay entregados + pendientes/aprobados
    if ($ent > 0 && ($pend + $apr) > 0) {
        return ['Parcial entregada', 'warning', false];
    }

    // Parcial rechazada: hay rechazados + pendientes/aprobados (sin entregados)
    if ($rej > 0 && $ent === 0 && ($pend + $apr) > 0) {
        return ['Parcial rechazada', 'warning', false];
    }

    // Aprobada: hay aprobados y nada entregado
    if ($apr > 0 && $ent === 0) {
        return ['Aprobada', 'primary', false];
    }

    // Default
    return ['Pendiente', 'secondary', false];
}

/* Actualizar Ticket/Detalle (solo si NO está cerrada) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_id'])) {
    $solicitudId = (int)($_POST['solicitud_id'] ?? 0);
    $ticket      = trim($_POST['ticket'] ?? "0");
    $detalle     = trim($_POST['detalle'] ?? "");

    $selectEstado = $hasEstadoGeneral ? ", estado_general" : ($hasEstadoOld ? ", estado" : "");

    $st = $conn->prepare("SELECT id, ticket, detalle $selectEstado
                          FROM solicitudes
                          WHERE id = ? AND user_id = ?");
    $st->execute([$solicitudId, $userId]);
    $sol = $st->fetch(PDO::FETCH_ASSOC);

    if ($sol) {
        $cerrada = false;

        if ($hasEstadoGeneral) {
            $cerrada = (($sol['estado_general'] ?? 'Pendiente') === 'Cerrada');
        } elseif ($hasEstadoOld) {
            $cerrada = in_array(($sol['estado'] ?? 'Pendiente'), ["Entregado","Rechazada"], true);
        } else {
            // Fallback: cerrado si todos ítems están resueltos
            $it = $conn->prepare("SELECT
                                    SUM(CASE WHEN estado_item IN ('Entregado','Rechazada') THEN 1 ELSE 0 END) AS cerrados,
                                    COUNT(*) AS total
                                  FROM solicitud_items
                                  WHERE solicitud_id = ?");
            $it->execute([$solicitudId]);
            $row = $it->fetch(PDO::FETCH_ASSOC);
            $cerrada = ($row && (int)$row['total'] > 0 && (int)$row['cerrados'] === (int)$row['total']);
        }

        if (!$cerrada) {
            $conn->prepare("UPDATE solicitudes
                            SET ticket = ?, detalle = ?, updated_at = NOW()
                            WHERE id = ? AND user_id = ?")
                 ->execute([$ticket, $detalle, $solicitudId, $userId]);

            $mensaje = "✅ Solicitud actualizada.";
        } else {
            $mensaje = "⚠️ No puedes modificar Ticket/Detalle en una solicitud cerrada.";
        }
    }
}

/* Cargar solicitudes + conteos (solo 4 estados) */
$stmt = $conn->prepare("
    SELECT
        s.*,
        e.estado AS voucher_estado,
        e.token_usuario,

        COUNT(si.id) AS total_items,
        SUM(si.estado_item='Pendiente')  AS c_pendiente,
        SUM(si.estado_item='Aprobada')   AS c_aprobada,
        SUM(si.estado_item='Rechazada')  AS c_rechazada,
        SUM(si.estado_item='Entregado')  AS c_entregado

    FROM solicitudes s
    LEFT JOIN solicitud_items si ON si.solicitud_id = s.id
    LEFT JOIN solicitud_entregas e ON e.solicitud_id = s.id
    WHERE s.user_id = ?
    GROUP BY s.id
    ORDER BY s.created_at DESC
");
$stmt->execute([$userId]);
$solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Items por solicitud */
$itemsPorSolicitud = [];
if (!empty($solicitudes)) {
    $ids = array_map(fn($s) => (int)$s['id'], $solicitudes);
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $itStmt = $conn->prepare("
        SELECT si.*,
               p.description,
               p.ubicacion
        FROM solicitud_items si
        JOIN products p ON p.id = si.product_id
        WHERE si.solicitud_id IN ($in)
        ORDER BY si.solicitud_id DESC, si.id ASC
    ");
    $itStmt->execute($ids);
    $items = $itStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $it) {
        $sid = (int)$it['solicitud_id'];
        $itemsPorSolicitud[$sid][] = $it;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Mis Solicitudes - Bodega</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
  <style>
    body { background:#f5f5f5; }
    .noti-dropdown { max-height:300px; overflow-y:auto; }
    #logo-egg { height:72px; cursor:pointer; }
    @media (max-width:576px){ #logo-egg{height:52px;} }
    .btn-compact{padding:.25rem .45rem;font-size:.85rem;}
    .items-box{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:10px;}
    .small-muted{font-size:.85rem;color:#6c757d;}
  </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
  <div class="container-fluid d-flex justify-content-between align-items-center">
    <img id="logo-egg" src="assets/logo.png" alt="Sonda Logo">
    <span class="navbar-brand mb-0 text-white">Sistema de Bodega</span>

    <div class="d-flex align-items-center">
      <span class="me-3 text-white d-none d-md-inline">
        <?= htmlspecialchars($_SESSION['user']) ?>
        <small>
          (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
          <?php if ($divisionName): ?> — <span class="badge text-bg-secondary"><?= htmlspecialchars($divisionName) ?></span><?php endif; ?>
        </small>
      </span>

      <div class="dropdown me-2">
        <button class="btn btn-outline-light btn-sm position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown">
          🔔
          <?php if ($notiCount > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $notiCount ?></span>
          <?php endif; ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end noti-dropdown">
          <?php if ($notiCount === 0): ?>
            <li><span class="dropdown-item-text text-muted">No tienes notificaciones nuevas</span></li>
          <?php else: ?>
            <?php foreach ($notificaciones as $n): ?>
              <li>
                <a class="dropdown-item" href="<?= htmlspecialchars($n['link']) ?>">
                  <?= htmlspecialchars($n['mensaje']) ?><br>
                  <small class="text-muted"><?= htmlspecialchars($n['created_at']) ?></small>
                </a>
              </li>
              <li><hr class="dropdown-divider"></li>
            <?php endforeach; ?>
            <li><a class="dropdown-item text-center" href="ver_notificaciones.php">📜 Ver todas</a></li>
          <?php endif; ?>
        </ul>
      </div>

      <a href="dashboard.php" class="btn btn-outline-light btn-sm me-1">🏠</a>
      <a href="logout.php" class="btn btn-outline-light btn-sm">⏻</a>
    </div>
  </div>
</nav>

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h3 class="mb-0">Mis Solicitudes</h3>
    <a href="dashboard.php" class="btn btn-secondary btn-sm">Volver</a>
  </div>

  <?php if ($mensaje): ?>
    <div class="alert <?= str_starts_with($mensaje,'✅') ? 'alert-success' : 'alert-warning' ?> mt-3">
      <?= htmlspecialchars($mensaje) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($solicitudes)): ?>
    <div class="alert alert-warning mt-3">No tienes solicitudes registradas.</div>
  <?php else: ?>

    <ul class="nav nav-tabs mb-3 mt-3" id="filtroTabs">
      <li class="nav-item"><button class="nav-link active" type="button" data-filter="todas">Todas</button></li>
      <li class="nav-item"><button class="nav-link" type="button" data-filter="activas">Activas</button></li>
      <li class="nav-item"><button class="nav-link" type="button" data-filter="cerradas">Cerradas</button></li>
    </ul>

    <div class="table-responsive">
      <table class="table table-bordered table-striped align-middle" id="tablaSolicitudes">
        <thead class="table-dark">
          <tr>
            <th style="width:70px">ID</th>
            <th>Resumen</th>
            <th style="width:170px">Estado</th>
            <th style="width:230px">Voucher</th>
            <th style="width:240px">Ticket/Detalle</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($solicitudes as $s):
          $sid = (int)$s['id'];

          // Estado visible (con 4 estados)
          [$estado, $badge, $cerrada] = estadoVisibleUsuario($s);

          $tipo = $cerrada ? "cerradas" : "activas";

          $voucherEstado = $s['voucher_estado'] ?? null;
          $voucherToken  = $s['token_usuario'] ?? null;

          $items = $itemsPorSolicitud[$sid] ?? [];
        ?>
          <tr data-tipo="<?= $tipo ?>">
            <td><strong>#<?= $sid ?></strong></td>

            <td>
              <div class="small-muted"><strong>Creada:</strong> <?= htmlspecialchars($s['created_at'] ?? '') ?></div>

              <button class="btn btn-outline-secondary btn-compact mt-2" type="button"
                      data-bs-toggle="collapse" data-bs-target="#items<?= $sid ?>">
                Ver ítems (<?= count($items) ?>)
              </button>

              <div class="collapse mt-2" id="items<?= $sid ?>">
                <div class="items-box">
                  <?php if (empty($items)): ?>
                    <div class="text-muted">Sin ítems asociados.</div>
                  <?php else: ?>
                    <table class="table table-sm table-bordered mb-0">
                      <thead class="table-light">
                        <tr>
                          <th>Producto</th>
                          <th class="text-end">Solic</th>
                          <th class="text-end">Aprob</th>
                          <th class="text-end">Ent</th>
                          <th>Estado ítem</th>
                        </tr>
                      </thead>
                      <tbody>
                      <?php foreach($items as $it): ?>
                        <tr>
                          <td><?= htmlspecialchars($it['description']) ?></td>
                          <td class="text-end"><?= (int)$it['cant_solicitada'] ?></td>
                          <td class="text-end"><?= (int)$it['cant_aprobada'] ?></td>
                          <td class="text-end"><?= (int)$it['cant_entregada'] ?></td>
                          <td>
                            <span class="badge bg-<?= badgeColor($it['estado_item'] ?? 'Pendiente') ?>">
                              <?= htmlspecialchars($it['estado_item'] ?? 'Pendiente') ?>
                            </span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>

                    <div class="small-muted mt-2">
                      <strong>Resumen:</strong>
                      Entregados <?= (int)($s['c_entregado'] ?? 0) ?> /
                      Rechazados <?= (int)($s['c_rechazada'] ?? 0) ?> /
                      Pendientes <?= (int)($s['c_pendiente'] ?? 0) ?> /
                      Aprobados <?= (int)($s['c_aprobada'] ?? 0) ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </td>

            <td>
              <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($estado) ?></span>
            </td>

            <td>
              <?php if (!$voucherEstado): ?>
                <span class="text-muted">Sin voucher</span>
              <?php else: ?>
                <div class="d-flex flex-column gap-2">
                  <span class="badge bg-<?= badgeColor($voucherEstado) ?>"><?= htmlspecialchars($voucherEstado) ?></span>

                  <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-dark btn-compact" href="voucher_entrega.php?solicitud_id=<?= $sid ?>">🧾 Ver voucher</a>

                    <?php if ($voucherEstado === 'PENDIENTE_USUARIO' && $voucherToken): ?>
                      <a class="btn btn-primary btn-compact" href="firmar_recepcion.php?token=<?= urlencode($voucherToken) ?>">✍️ Firmar recepción</a>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
            </td>

            <td>
              <form method="post" class="d-flex flex-column gap-2 mb-0">
                <input type="hidden" name="solicitud_id" value="<?= $sid ?>">

                <input type="text" name="ticket" value="<?= htmlspecialchars($s['ticket'] ?? '0') ?>"
                       class="form-control form-control-sm" placeholder="Ticket" <?= $cerrada ? 'disabled' : '' ?>>

                <textarea name="detalle" rows="2" class="form-control form-control-sm"
                          placeholder="Detalle" <?= $cerrada ? 'disabled' : '' ?>><?= htmlspecialchars($s['detalle'] ?? '') ?></textarea>

                <button type="submit" class="btn btn-sm btn-primary" <?= $cerrada ? 'disabled' : '' ?>>Actualizar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('#filtroTabs button').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('#filtroTabs .nav-link').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    const filtro = this.getAttribute('data-filter');
    document.querySelectorAll('#tablaSolicitudes tbody tr').forEach(row => {
      if (filtro === "todas") row.style.display = "";
      else row.style.display = (row.getAttribute('data-tipo') === filtro) ? "" : "none";
    });
  });
});
</script>
</body>
</html>