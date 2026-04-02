<?php
session_start();
if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}
require 'config/db.php';

$adminId = (int)($_SESSION['user_id'] ?? 0);
$mensaje = '';
$areaUsuario  = $_SESSION['area'] ?? '';
$divisionName = $_SESSION['division_name'] ?? null;

// NOTA: Comentado para que las notificaciones NO se marquen como leídas automáticamente al entrar
// Si quieres marcarlas solo al verlas individualmente, crea una página aparte o botón "Marcar como leídas"
// $stmt = $conn->prepare("UPDATE notificaciones SET leido = 1 WHERE user_id = ? AND link = 'admin_solicitudes.php'");
// $stmt->execute([$adminId]);

// Notificaciones navbar (solo no leídas)
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$adminId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);

// Helpers de colores y estado (sin cambios)
function badgeColorEstadoGeneral($estado) {
    return match($estado) {
        'Pendiente' => 'secondary',
        'Parcial'   => 'warning',
        'Cerrada'   => 'success',
        default     => 'dark'
    };
}

function badgeColorEstadoItem($estado) {
    $map = [
        "Pendiente" => "secondary",
        "Aprobada"  => "primary",
        "Rechazada" => "danger",
        "Entregado" => "success"
    ];
    return $map[$estado] ?? 'dark';
}

function recalcularEstadoGeneral(PDO $conn, int $solicitudId): string {
    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN estado_item = 'Pendiente' THEN 1 ELSE 0 END) AS c_pend,
            SUM(CASE WHEN estado_item = 'Entregado' THEN 1 ELSE 0 END) AS c_ent,
            SUM(CASE WHEN estado_item = 'Rechazada' THEN 1 ELSE 0 END) AS c_rech,
            COUNT(*) AS total
        FROM solicitud_items
        WHERE solicitud_id = ?
    ");
    $stmt->execute([$solicitudId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    $total   = (int)($r['total'] ?? 0);
    $cPend   = (int)($r['c_pend'] ?? 0);
    $cEnt    = (int)($r['c_ent'] ?? 0);
    $cRech   = (int)($r['c_rech'] ?? 0);

    if ($total > 0 && ($cEnt + $cRech) === $total) return 'Cerrada';
    if ($total > 0 && $cPend === $total) return 'Pendiente';
    return 'Parcial';
}

function isSolicitudBloqueada(PDO $conn, int $solicitudId): bool {
    $stmt = $conn->prepare("
        SELECT 1 
        FROM solicitud_entregas 
        WHERE solicitud_id = ? AND estado = 'COMPLETADA' 
        LIMIT 1
    ");
    $stmt->execute([$solicitudId]);
    return (bool) $stmt->fetchColumn();
}

// Procesar actualización de ítem (sin cambios importantes, pero agrego log extra si quieres 'solicitud_estado')
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'], $_POST['solicitud_id'])) {
    $itemId      = (int)$_POST['item_id'];
    $solicitudId = (int)$_POST['solicitud_id'];

    if (isSolicitudBloqueada($conn, $solicitudId)) {
        $mensaje = "❌ Esta solicitud está bloqueada porque el comprobante de entrega ya fue firmado por ambas partes. No se permiten modificaciones.";
    } else {
        $nuevoEstado   = trim($_POST['estado_item'] ?? 'Pendiente');
        $cantAprobada  = isset($_POST['cant_aprobada'])  ? (int)$_POST['cant_aprobada']  : 0;
        $cantEntregada = isset($_POST['cant_entregada']) ? (int)$_POST['cant_entregada'] : 0;

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("
                SELECT
                  si.*,
                  s.user_id AS solicitante_id,
                  s.ticket,
                  s.detalle,
                  p.description,
                  p.quantity AS stock_actual
                FROM solicitud_items si
                JOIN solicitudes s ON s.id = si.solicitud_id
                JOIN products p ON p.id = si.product_id
                WHERE si.id = ? AND si.solicitud_id = ?
            ");
            $stmt->execute([$itemId, $solicitudId]);
            $it = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$it) throw new Exception("Ítem no encontrado.");

            $estadoAnterior   = $it['estado_item'];
            $solicitanteId    = (int)$it['solicitante_id'];
            $productoNombre   = $it['description'];
            $stockActual      = (int)$it['stock_actual'];
            $cantSolicitada   = (int)$it['cant_solicitada'];
            $cantAprobadaOld  = (int)$it['cant_aprobada'];
            $cantEntregadaOld = (int)$it['cant_entregada'];

            if ($cantAprobada < 0) $cantAprobada = 0;
            if ($cantEntregada < 0) $cantEntregada = 0;
            if ($cantAprobada > $cantSolicitada) $cantAprobada = $cantSolicitada;
            if ($cantEntregada > $cantAprobada)  $cantEntregada = $cantAprobada;

            if ($nuevoEstado === 'Entregado' && $cantEntregada <= 0) {
                throw new Exception("Para marcar como Entregado, la cantidad entregada debe ser mayor a 0.");
            }

            $detalleLog = "Ítem #{$itemId} - Producto: '{$productoNombre}' - Solicitud #{$solicitudId}";

            // Descontar stock
            $deltaEntrega = $cantEntregada - $cantEntregadaOld;
            if ($deltaEntrega > 0) {
                if ($stockActual < $deltaEntrega) {
                    throw new Exception("Stock insuficiente. Actual: {$stockActual}, a entregar: {$deltaEntrega}.");
                }
                $nuevoStock = $stockActual - $deltaEntrega;
                $up = $conn->prepare("UPDATE products SET quantity = ? WHERE id = ?");
                $up->execute([$nuevoStock, (int)$it['product_id']]);

                $log = $conn->prepare("
                    INSERT INTO logs (user_id, action, details, old_value, new_value, created_at)
                    VALUES (?, 'product_modified', ?, ?, ?, NOW())
                ");
                $log->execute([
                    $adminId,
                    $detalleLog . " - Stock modificado (entrega)",
                    $stockActual,
                    $nuevoStock
                ]);
            }

            // Actualizar ítem
            $upItem = $conn->prepare("
                UPDATE solicitud_items
                SET estado_item = ?, cant_aprobada = ?, cant_entregada = ?, updated_at = NOW()
                WHERE id = ? AND solicitud_id = ?
            ");
            $upItem->execute([$nuevoEstado, $cantAprobada, $cantEntregada, $itemId, $solicitudId]);

            // Log de producto modificado (ya lo tenías)
            $log = $conn->prepare("
                INSERT INTO logs (user_id, action, details, old_value, new_value, created_at)
                VALUES (?, 'product_modified', ?, ?, ?, NOW())
            ");
            $log->execute([
                $adminId,
                $detalleLog . " - Estado: {$estadoAnterior} → {$nuevoEstado}, Aprob: {$cantAprobadaOld} → {$cantAprobada}, Ent: {$cantEntregadaOld} → {$cantEntregada}",
                json_encode(['estado' => $estadoAnterior, 'aprob' => $cantAprobadaOld, 'ent' => $cantEntregadaOld]),
                json_encode(['estado' => $nuevoEstado, 'aprob' => $cantAprobada, 'ent' => $cantEntregada])
            ]);

            // Log adicional para solicitud/ítem (si quieres que aparezca 'solicitud_estado')
            $logSol = $conn->prepare("
                INSERT INTO logs (user_id, action, details, old_value, new_value, created_at)
                VALUES (?, 'solicitud_estado', ?, ?, ?, NOW())
            ");
            $logSol->execute([
                $adminId,
                "Cambio en solicitud #{$solicitudId} - Ítem #{$itemId}: Estado {$estadoAnterior} → {$nuevoEstado}, Aprob {$cantAprobadaOld} → {$cantAprobada}, Ent {$cantEntregadaOld} → {$cantEntregada}",
                $estadoAnterior,
                $nuevoEstado
            ]);

            // Recalcular estado general
            $nuevoEstadoGeneral = recalcularEstadoGeneral($conn, $solicitudId);
            $upSol = $conn->prepare("UPDATE solicitudes SET estado_general = ?, updated_at = NOW() WHERE id = ?");
            $upSol->execute([$nuevoEstadoGeneral, $solicitudId]);

            // Notificar al usuario (solicitante)
            $msg = "📢 Solicitud #{$solicitudId} — '{$productoNombre}': {$estadoAnterior} → {$nuevoEstado} (Aprob.: {$cantAprobada}, Entreg.: {$cantEntregada}). Estado general ahora: {$nuevoEstadoGeneral}";
            $notif = $conn->prepare("INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at)
                                     VALUES (?, ?, 'mis_solicitudes.php', 0, NOW())");
            $notif->execute([$solicitanteId, $msg]);

            $conn->commit();
            $mensaje = "✅ Ítem actualizado correctamente. Estado general: {$nuevoEstadoGeneral}";
        } catch (Throwable $e) {
            $conn->rollBack();
            $mensaje = "❌ Error al actualizar: " . $e->getMessage();
        }
    }
}

// Listar solicitudes + resumen (con LEFT JOIN para que aparezcan aunque usuario no exista)
$stmt = $conn->query("
    SELECT
        s.*,
        u.username,
        COUNT(si.id) AS total_items,
        SUM(CASE WHEN si.estado_item = 'Entregado' THEN 1 ELSE 0 END) AS items_entregados,
        SUM(CASE WHEN si.estado_item IN ('Pendiente','Aprobada') THEN 1 ELSE 0 END) AS items_pendientes
    FROM solicitudes s
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN solicitud_items si ON si.solicitud_id = s.id
    GROUP BY s.id
    ORDER BY s.created_at DESC
");
$solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cargar ítems
$itemsBySolicitud = [];
$ids = array_map(fn($s) => (int)$s['id'], $solicitudes);

if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
        SELECT
            si.*,
            p.description AS producto,
            p.quantity AS stock_actual,
            p.ubicacion AS ubicacion
        FROM solicitud_items si
        JOIN products p ON p.id = si.product_id
        WHERE si.solicitud_id IN ($in)
        ORDER BY si.solicitud_id DESC, si.id ASC
    ");
    $stmt->execute($ids);
    $allItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allItems as $it) {
        $sid = (int)$it['solicitud_id'];
        $itemsBySolicitud[$sid][] = $it;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Administrar Solicitudes</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
    <style>
        .noti-dropdown { max-height: 300px; overflow-y: auto; }
        .btn-compact { padding:.25rem .45rem; font-size:.8rem; }
        .table td, .table th { vertical-align: middle; }
        @media (max-width:576px){ .col-fecha { display:none; } }
        .bloqueado-info { background-color: #e7f3ff; border-left: 4px solid #2196F3; }
    </style>
</head>
<body class="bg-light">

<!-- Navbar (sin cambios) -->
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega</span>
        <div class="d-flex align-items-center">
            <span class="me-3 text-white d-none d-md-inline">
                <?= htmlspecialchars($_SESSION['user']) ?>
                / (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                <?php if ($divisionName): ?>
                    <span class="badge text-bg-secondary ms-2"><?= htmlspecialchars($divisionName) ?></span>
                <?php endif; ?>
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
            <a href="logout.php" class="btn btn-outline-light btn-sm">⏻</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <h3>Administración de Solicitudes</h3>

    <?php if ($mensaje): ?>
        <div class="alert <?= strpos($mensaje, '✅') === 0 ? 'alert-success' : 'alert-danger' ?>">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($solicitudes)): ?>
        <div class="alert alert-info">No hay solicitudes registradas.</div>
    <?php else: ?>
        <ul class="nav nav-tabs mb-3" id="filtroTabs">
            <li class="nav-item"><button class="nav-link active" data-filter="todas" type="button">Todas</button></li>
            <li class="nav-item"><button class="nav-link" data-filter="activas" type="button">Activas</button></li>
            <li class="nav-item"><button class="nav-link" data-filter="cerradas" type="button">Cerradas</button></li>
        </ul>

        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="tablaSolicitudes">
                <thead class="table-dark">
                    <tr>
                        <th style="width:70px;">ID</th>
                        <th>Usuario</th>
                        <th style="width:140px;">Ticket</th>
                        <th>Detalle</th>
                        <th style="width:150px;">Resumen</th>
                        <th style="width:120px;">Estado</th>
                        <th class="col-fecha" style="width:170px;">Fecha</th>
                        <th style="width:140px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($solicitudes as $s):
                        $sid = (int)$s['id'];
                        $estadoGeneral = $s['estado_general'] ?? 'Pendiente';
                        $tipo = ($estadoGeneral === 'Cerrada') ? "cerradas" : "activas";

                        $totalItems    = (int)($s['total_items'] ?? 0);
                        $entregados    = (int)($s['items_entregados'] ?? 0);
                        $pendientes    = (int)($s['items_pendientes'] ?? 0);

                        $cGeneral = badgeColorEstadoGeneral($estadoGeneral);
                        $bloqueada = isSolicitudBloqueada($conn, $sid);
                        $usernameDisplay = $s['username'] ?? '(usuario eliminado)';
                    ?>
                    <tr data-tipo="<?= $tipo ?>">
                        <td><?= $sid ?></td>
                        <td><?= htmlspecialchars($usernameDisplay) ?></td>
                        <td><?= htmlspecialchars($s['ticket'] ?? '0') ?></td>
                        <td><?= htmlspecialchars($s['detalle'] ?? '') ?></td>
                        <td class="small">
                            <div><strong>Ítems:</strong> <?= $totalItems ?></div>
                            <div><strong>Pendientes:</strong> <?= $pendientes ?> | <strong>Entregados:</strong> <?= $entregados ?></div>
                        </td>
                        <td><span class="badge bg-<?= $cGeneral ?>"><?= htmlspecialchars($estadoGeneral) ?></span></td>
                        <td class="col-fecha"><?= htmlspecialchars($s['created_at'] ?? '') ?></td>
                        <td>
                            <div class="d-grid gap-2">
                                <button class="btn btn-sm btn-outline-secondary"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#items<?= $sid ?>">
                                    Detalles
                                </button>
                                <a class="btn btn-sm btn-outline-dark"
                                   href="voucher_entrega.php?solicitud_id=<?= $sid ?>">
                                    🧾 Voucher
                                </a>
                            </div>
                        </td>
                    </tr>

                    <tr class="collapse" id="items<?= $sid ?>">
                        <td colspan="8" class="bg-white">
                            <?php $items = $itemsBySolicitud[$sid] ?? []; ?>
                            <?php if (empty($items)): ?>
                                <div class="text-muted">No hay ítems para esta solicitud.</div>
                            <?php else: ?>
                                <?php if ($bloqueada): ?>
                                    <div class="alert alert-info bloqueado-info mt-2">
                                        <strong>🔒 Solicitud bloqueada</strong><br>
                                        El comprobante de entrega ya fue firmado por el administrador y el receptor. No se permiten modificaciones.<br>
                                        Puedes ver los detalles e imprimir el voucher.
                                    </div>
                                <?php endif; ?>

                                <div class="table-responsive">
                                    <table class="table table-sm table-striped mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Producto</th>
                                                <th style="width:90px;" class="text-end">Stock</th>
                                                <th style="width:100px;" class="text-end">Solic.</th>
                                                <th style="width:110px;" class="text-end">Aprob.</th>
                                                <th style="width:120px;" class="text-end">Entreg.</th>
                                                <th style="width:140px;">Estado</th>
                                                <th style="width:160px;">Ubicación</th>
                                                <th style="width:140px;">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($items as $it):
                                                $estadoItem = $it['estado_item'] ?? 'Pendiente';
                                                $cItem = badgeColorEstadoItem($estadoItem);
                                                $cantSolic = (int)($it['cant_solicitada'] ?? 0);
                                                $cantApr   = (int)($it['cant_aprobada'] ?? 0);
                                                $cantEnt   = (int)($it['cant_entregada'] ?? 0);
                                            ?>
                                            <tr>
                                                <td><?= htmlspecialchars($it['producto'] ?? '') ?></td>
                                                <td class="text-end"><?= (int)($it['stock_actual'] ?? 0) ?></td>
                                                <td class="text-end"><?= $cantSolic ?></td>

                                                <td class="text-end">
                                                    <?php if ($bloqueada): ?>
                                                        <?= $cantApr ?>
                                                    <?php else: ?>
                                                        <form method="post" class="d-flex gap-2 align-items-center justify-content-end mb-0">
                                                            <input type="hidden" name="solicitud_id" value="<?= $sid ?>">
                                                            <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                                                            <input type="number" min="0" max="<?= $cantSolic ?>" name="cant_aprobada" value="<?= $cantApr ?>" class="form-control form-control-sm" style="width:90px;">
                                                    <?php endif; ?>
                                                </td>

                                                <td class="text-end">
                                                    <?php if ($bloqueada): ?>
                                                        <?= $cantEnt ?>
                                                    <?php else: ?>
                                                        <input type="number" min="0" max="<?= $cantSolic ?>" name="cant_entregada" value="<?= $cantEnt ?>" class="form-control form-control-sm" style="width:90px;">
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php if ($bloqueada): ?>
                                                        <span class="badge bg-<?= $cItem ?>"><?= htmlspecialchars($estadoItem) ?></span>
                                                    <?php else: ?>
                                                        <select name="estado_item" class="form-select form-select-sm">
                                                            <?php
                                                            $estados = ["Pendiente", "Aprobada", "Rechazada", "Entregado"];
                                                            foreach ($estados as $e):
                                                            ?>
                                                                <option value="<?= $e ?>" <?= ($estadoItem === $e) ? 'selected' : '' ?>><?= $e ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <div class="mt-1">
                                                            <span class="badge bg-<?= $cItem ?>"><?= htmlspecialchars($estadoItem) ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="text-muted"><?= htmlspecialchars($it['ubicacion'] ?? '—') ?></td>

                                                <td>
                                                    <?php if ($bloqueada): ?>
                                                        <button type="button" class="btn btn-sm btn-secondary w-100" disabled>Bloqueado</button>
                                                    <?php else: ?>
                                                        <button type="submit" class="btn btn-sm btn-primary w-100">Guardar</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="text-muted small mt-2">
                                    Tip: para entregar, ajusta “Entreg.” y cambia estado a <strong>Entregado</strong>. El sistema descuenta stock solo por la diferencia entregada.
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <a href="dashboard.php" class="btn btn-secondary mt-3">Volver</a>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
// Filtro de tabs (sin cambios)
document.querySelectorAll('#filtroTabs button').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('#filtroTabs .nav-link').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const filtro = this.getAttribute('data-filter');

        document.querySelectorAll('#tablaSolicitudes tbody tr').forEach(row => {
            if (!row.hasAttribute('data-tipo')) return;

            if (filtro === "todas") row.style.display = "";
            else row.style.display = row.getAttribute('data-tipo') === filtro ? "" : "none";

            const sidCell = row.querySelector('td');
            const sid = sidCell ? sidCell.textContent.trim() : null;
            if (sid) {
                const detail = document.getElementById('items' + sid);
                if (detail) {
                    detail.classList.remove('show');
                    detail.style.display = (row.style.display === "") ? "" : "none";
                }
            }
        });
    });
});
</script>
</body>
</html>