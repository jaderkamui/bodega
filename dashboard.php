<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

require 'config/db.php';

// ===== Sesión base =====
$rol           = $_SESSION['role'] ?? 'lector';
$puedeEditar   = in_array($rol, ['admin', 'editor']);
$esAdmin       = ($rol === 'admin');
$areaUsuario   = $_SESSION['area'] ?? null;

$divisionNameRaw  = $_SESSION['division_name'] ?? null; // Puede venir como CHQ/RT/DMH/GM o ya con nombre
$bodegasIds       = $_SESSION['bodegas_ids'] ?? [];     // [1,5,...]
$bodegasMap       = $_SESSION['bodegas_map'] ?? [];     // [id => nombre]

// Mapas de siglas -> nombres completos
$divisionLabelMap = [
    'CHQ' => 'Chuquicamata',
    'RT'  => 'Radomiro Tomic',
    'DMH' => 'División Ministro Hales',
    'GM'  => 'Gabriela Mistral',
];

// Normaliza la etiqueta de división si viene en sigla
$divisionName = $divisionLabelMap[$divisionNameRaw] ?? $divisionNameRaw;

// Helper para expandir siglas dentro de un nombre de bodega (para el “pill”)
function expandDivisionSiglas($name) {
    $map = [
        'CHQ' => 'Chuquicamata',
        'RT'  => 'Radomiro Tomic',
        'DMH' => 'División Ministro Hales',
        'GM'  => 'Gabriela Mistral',
    ];
    foreach ($map as $k => $v) {
        // Reemplaza la sigla como palabra independiente
        $name = preg_replace('/\b' . preg_quote($k, '/') . '\b/u', $v, $name);
    }
    return $name;
}

// ===== Filtro de bodega en UI =====
$selectedBodega = isset($_GET['bodega']) && $_GET['bodega'] !== ''
    ? (int)$_GET['bodega']
    : null;

// Si NO es admin, aseguramos que sólo pueda filtrar por bodegas permitidas
if (!$esAdmin && $selectedBodega && !in_array($selectedBodega, $bodegasIds, true)) {
    $selectedBodega = null; // ignora selección inválida
}

// ===== Helper para placeholders IN (?) =====
function inPlaceholders(array $arr) {
    return implode(',', array_fill(0, count($arr), '?'));
}

// ===== Consultar productos según permisos/filtros =====
if ($esAdmin) {
    if ($selectedBodega) {
        $stmt = $conn->prepare("
            SELECT p.*, b.nombre AS bodega_nombre
            FROM products p
            LEFT JOIN bodegas b ON b.id = p.bodega_id
            WHERE p.bodega_id = ?
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$selectedBodega]);
    } else {
        $stmt = $conn->query("
            SELECT p.*, b.nombre AS bodega_nombre
            FROM products p
            LEFT JOIN bodegas b ON b.id = p.bodega_id
            ORDER BY p.created_at DESC
        ");
    }
} else {
    if ($selectedBodega) {
        $stmt = $conn->prepare("
            SELECT p.*, b.nombre AS bodega_nombre
            FROM products p
            LEFT JOIN bodegas b ON b.id = p.bodega_id
            WHERE p.area = ? AND p.bodega_id = ?
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$areaUsuario, $selectedBodega]);
    } else {
        if (!empty($bodegasIds)) {
            $in = inPlaceholders($bodegasIds);
            $sql = "
                SELECT p.*, b.nombre AS bodega_nombre
                FROM products p
                LEFT JOIN bodegas b ON b.id = p.bodega_id
                WHERE p.area = ? AND p.bodega_id IN ($in)
                ORDER BY p.created_at DESC
            ";
            $params = array_merge([$areaUsuario], $bodegasIds);
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
        } else {
            $products = [];
        }
    }
}

if (!isset($products)) {
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== Notificaciones no leídas =====
$userId = $_SESSION['user_id'];
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$userId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);

// ===== Cargar todas las bodegas para el selector (admin ve todas; usuario sólo sus bodegas) =====
if ($esAdmin) {
    $bStmt = $conn->query("SELECT id, nombre FROM bodegas ORDER BY nombre");
    $todasBodegas = $bStmt->fetchAll(PDO::FETCH_KEY_PAIR); // [id=>nombre]
} else {
    $todasBodegas = $bodegasMap; // ya viene filtrado por permisos
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de Control - Bodega</title>
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">

  <!-- Bootstrap Icons local -->
  <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
<link rel="stylesheet" href="assets/datatables/css/dataTables.bootstrap5.min.css">

    <style>
        .noti-dropdown { max-height: 300px; overflow-y: auto; }
        .bodega-pill { font-weight: 600; }
        .header-bar { row-gap: .75rem; }
        .selector-bodega { min-width: 240px; }
        .title-stack h3 { margin-bottom: .25rem; }
        .title-sub { margin-top: .25rem; font-size: .95rem; color: #6c757d; }
        @media (max-width: 576px){
            .selector-bodega { min-width: 100%; }
        }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega</span>
        <div class="d-flex align-items-center">
            <span class="me-3 text-white">
                Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?>
                / (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                <?php if ($divisionName): ?>
                    <span class="badge text-bg-secondary ms-2"><?= htmlspecialchars($divisionName) ?></span>
                <?php endif; ?>
            </span>

            <!-- 🔔 Campanita de Notificaciones -->
            <div class="dropdown me-3">
                <button class="btn btn-outline-light position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    🔔
                    <?php if ($notiCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $notiCount ?>
                        </span>
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

            <?php if (!$esAdmin): ?>
                <a href="mis_solicitudes.php" class="btn btn-outline-warning me-2">📑 Mis Solicitudes</a>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-outline-light">Cerrar sesión</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <!-- Barra superior -->
    <div class="d-flex flex-wrap justify-content-between align-items-start align-items-md-center mb-4 header-bar">
        <div class="title-stack">
            <h3 class="mb-0">
                Productos en Bodega
                <?php if ($selectedBodega && isset($todasBodegas[$selectedBodega])): ?>
                    <?php
                        // Muestra nombre completo en el “pill”, aunque el selector tenga siglas
                        $pillName = expandDivisionSiglas($todasBodegas[$selectedBodega]);
                    ?>
                    <span class="badge text-bg-info bodega-pill ms-2"><?= htmlspecialchars($pillName) ?></span>
                <?php elseif (!$selectedBodega && !$esAdmin): ?>
                    <span class="badge text-bg-info bodega-pill ms-2">Mis bodegas</span>
                <?php endif; ?>
            </h3>
            <?php if ($divisionName): ?>
                <div class="title-sub">División asignada — <strong><?= htmlspecialchars($divisionName) ?></strong></div>
            <?php endif; ?>
        </div>

        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
            <form method="get" class="d-flex align-items-center me-md-2">
                <label class="me-2">Bodega:</label>
                <select name="bodega" class="form-select form-select-sm selector-bodega" onchange="this.form.submit()">
                    <?php if ($esAdmin): ?>
                        <option value="">(Todas)</option>
                    <?php else: ?>
                        <option value="">(Mis bodegas)</option>
                    <?php endif; ?>
                    <?php foreach ($todasBodegas as $bid => $bname): ?>
                        <option value="<?= (int)$bid ?>" <?= ($selectedBodega===(int)$bid ? 'selected' : '') ?>>
                            <?= htmlspecialchars($bname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-sm btn-outline-secondary ms-2">Filtrar</button></noscript>
            </form>

            <?php if ($esAdmin): ?>
                <a href="admin_usuarios.php" class="btn btn-secondary">⚙️ Editar Usuarios</a>
            <?php endif; ?>
            <?php if ($esAdmin): ?>
            <a href="admin_bodegas.php" class="btn btn-outline-secondary">🏬 Bodegas</a>
            <?php endif; ?>

            <?php if ($puedeEditar): ?>
                <a href="agregar_producto.php" class="btn btn-success">➕ Agregar nuevo producto</a>
            <?php endif; ?>

            <a href="scan_barcode.php" class="btn btn-outline-primary">📷 Escaneo rápido</a>

            <?php if ($esAdmin): ?>
                <a href="ver_logs.php" class="btn btn-info">📜 Ver logs</a>
                <a href="admin_solicitudes.php" class="btn btn-warning">📑 Solicitudes</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($products)): ?>
        <div class="alert alert-warning">No hay productos registrados para el filtro seleccionado.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table id="productos" class="table table-bordered table-striped align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Descripción</th>
                        <th>Código</th>
                        <th>Cantidad</th>
                        <th>Ubicación</th>
                        <th>Área</th>
                        <th>Bodega</th>
                        <th>Fecha Ingreso</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $prod): ?>
                        <tr>
                            <td><?= (int)$prod['id'] ?></td>
                            <td><?= htmlspecialchars($prod['description']) ?></td>
                            <td><?= htmlspecialchars($prod['barcode'] ?? '') ?></td>
                            <td><?= (int)($prod['quantity'] ?? 0) ?></td>
                            <td>
                                <?= htmlspecialchars($prod['ubicacion']) ?>
                                <a href="ver_ubicacion.php?ubicacion=<?= urlencode($prod['ubicacion']) ?>" class="btn btn-info btn-sm ms-2">ver</a>
                            </td>
                            <td><?= htmlspecialchars($prod['area']) ?></td>
                            <td><?= htmlspecialchars($prod['bodega_nombre'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($prod['created_at']) ?></td>
                            <td>
                                <?php if ($puedeEditar): ?>
                                    <a href="editar_producto.php?id=<?= (int)$prod['id'] ?>" class="btn btn-sm btn-warning" title="Editar">✏️</a>
                                    <a href="eliminar_producto.php?id=<?= (int)$prod['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Estás seguro de eliminar este producto?')" title="Eliminar">🗑️</a>
                                <?php endif; ?>
                                <?php if (!$esAdmin): ?>
                                    <a href="generar_solicitud.php?product_id=<?= (int)$prod['id'] ?>" class="btn btn-sm btn-primary">📦 Solicitar</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- DataTables JS -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/datatables/js/jquery.dataTables.min.js"></script>
<script src="assets/datatables/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function () {
        $('#productos').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/Spanish.json' },
            pageLength: 10
        });
    });
</script>

<footer class="bg-light text-center text-muted py-3 mt-5 border-top">
    Desarrollado por Jader Muñoz - 2025
</footer>

</body>
</html>
