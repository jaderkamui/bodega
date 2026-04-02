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
    'DCHS'=> 'División Chuqui Subterránea'
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
        'DCHS'=> 'División Chuqui Subterránea'
    ];
    foreach ($map as $k => $v) {
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
    <!-- 📱 Mobile first -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de Control - Bodega</title>

    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/datatables/css/dataTables.bootstrap5.min.css">

    <style>
        body{
            background-color:#f5f6fa;
            font-size:14px;
        }

        .navbar-logo{
            height:60px;
        }
        @media (min-width:768px){
            .navbar-logo{height:90px;}
        }

        .navbar-brand-title{
            font-size:1.1rem;
        }
        @media (min-width:768px){
            .navbar-brand-title{font-size:1.3rem;}
        }

        .noti-dropdown { max-height: 300px; overflow-y: auto; }

        .header-card{
            margin-top:1rem;
        }

        .bodega-pill { font-weight: 600; }
        .title-sub { font-size:.9rem; color:#6c757d; }

        .selector-bodega { min-width: 180px; }
        @media (max-width:576px){
            .selector-bodega { min-width: 100%; }
        }

        /* Tabla más amigable para móvil */
        #productos th, #productos td{
            vertical-align: middle;
        }

        /* Ocultar algunas columnas en pantallas muy pequeñas para que respire */
        @media (max-width:576px){
            .col-id,
            .col-area,
            .col-fecha{
                display:none;
            }
        }

        .btn-compact{
            padding:.25rem .45rem;
            font-size:.8rem;
        }

        .page-padding{
            padding-bottom:3.5rem;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" class="navbar-logo">
        <span class="navbar-brand text-white mb-0 navbar-brand-title">Sistema de Bodega</span>
        <div class="d-flex align-items-center">
            <!-- En móvil sólo iconitos, en desktop muestra texto -->
            <span class="me-2 text-white d-none d-md-inline">
                Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?>
                (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                <?php if ($divisionName): ?>
                    <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars($divisionName) ?></span>
                <?php endif; ?>
            </span>

            <!-- 🔔 Notificaciones -->
            <div class="dropdown me-2">
                <button class="btn btn-outline-light btn-sm position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
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
                <!-- Visible en desktop -->
                <a href="mis_solicitudes.php" class="btn btn-outline-warning btn-sm me-1 d-none d-md-inline">📑 Mis solicitudes</a>

                <!-- Visible en móvil (solo iconos) -->
                <a href="mis_solicitudes.php" class="btn btn-outline-warning btn-sm me-1 d-md-none" title="Mis solicitudes">📑</a>
            <?php endif; ?>
            

            <a href="logout.php" class="btn btn-outline-light btn-sm">⏻</a>
        </div>
    </div>
</nav>

<div class="container page-padding">

    <!-- Card con filtro + acciones -->
    <div class="card shadow-sm header-card">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                <div>
                    <h5 class="mb-1">
                        Productos en bodega
                        <?php if ($selectedBodega && isset($todasBodegas[$selectedBodega])): ?>
                            <?php $pillName = expandDivisionSiglas($todasBodegas[$selectedBodega]); ?>
                            <span class="badge text-bg-info bodega-pill ms-1"><?= htmlspecialchars($pillName) ?></span>
                        <?php elseif (!$selectedBodega && !$esAdmin): ?>
                            <span class="badge text-bg-info bodega-pill ms-1">Mis bodegas</span>
                        <?php endif; ?>
                    </h5>
                    <?php if ($divisionName): ?>
                        <div class="title-sub">División: <strong><?= htmlspecialchars($divisionName) ?></strong></div>
                    <?php endif; ?>
                </div>

                <div class="d-flex flex-column flex-md-row gap-2 align-items-stretch align-items-md-center">

                    <!-- Selector de bodega -->
                    <form method="get" class="d-flex flex-row flex-md-row align-items-center gap-2 w-100">
                        <label class="d-none d-md-inline mb-0">Bodega:</label>
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
                        <noscript><button class="btn btn-sm btn-outline-secondary">Filtrar</button></noscript>
                    </form>

                    <!-- Botones de acción -->
<div class="d-flex flex-wrap gap-1 justify-content-end">
    <?php if ($esAdmin): ?>
        <a href="admin_usuarios.php"    class="btn btn-outline-primary btn-sm">👤 Usuarios</a>
        <a href="admin_bodegas.php"     class="btn btn-outline-primary btn-sm">🏬 Bodegas</a>
        <a href="admin_divisiones.php"  class="btn btn-outline-primary btn-sm">🏢 Divisiones</a>
        <a href="reportes_bodega.php"   class="btn btn-outline-primary btn-sm">📊 Reportes</a>
        <a href="ver_logs.php"          class="btn btn-outline-primary btn-sm">📜 Logs</a>
        <a href="admin_solicitudes.php" class="btn btn-outline-warning btn-sm">📑 Solicitudes</a>
    <?php endif; ?>

    <?php if ($puedeEditar): ?>
        <a href="agregar_producto.php" class="btn btn-success btn-sm">➕ Producto</a>
    <?php endif; ?>

    <?php if (!$esAdmin): ?>
        <a href="solicitud_masiva.php" class="btn btn-success btn-sm">🧾 Solicitud masiva</a>
    <?php endif; ?>

    <a href="scan_barcode.php" class="btn btn-outline-primary btn-sm">📷 Escanear</a>

    <?php if (!$esAdmin): ?>
        <a href="mis_solicitudes.php" class="btn btn-outline-warning btn-sm">📑 Mis solicitudes</a>
    <?php endif; ?>
</div>

                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de productos -->
    <div class="mt-3">
        <?php if (empty($products)): ?>
            <div class="alert alert-warning mt-2">No hay productos registrados para el filtro seleccionado.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table id="productos" class="table table-bordered table-striped align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th class="col-id">ID</th>
                            <th>Descripción</th>
                            <th>Código</th>
                            <th>Cant.</th>
                            <th>Ubicación</th>
                            <th class="col-area">Área</th>
                            <th>Bodega</th>
                            <th class="col-fecha">Ingreso</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $prod): ?>
                            <tr>
                                <td class="col-id"><?= (int)$prod['id'] ?></td>
                                <td><?= htmlspecialchars($prod['description']) ?></td>
                                <td><?= htmlspecialchars($prod['barcode'] ?? '') ?></td>
                                <td><?= (int)($prod['quantity'] ?? 0) ?></td>
                                <td>
                                    <?= htmlspecialchars($prod['ubicacion']) ?>
                                    <a href="ver_ubicacion.php?ubicacion=<?= urlencode($prod['ubicacion']) ?>" class="btn btn-info btn-compact ms-1">ver</a>
                                </td>
                                <td class="col-area"><?= htmlspecialchars($prod['area']) ?></td>
                                <td><?= htmlspecialchars($prod['bodega_nombre'] ?? '—') ?></td>
                                <td class="col-fecha"><?= htmlspecialchars($prod['created_at']) ?></td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php if ($puedeEditar): ?>
                                            <a href="editar_producto.php?id=<?= (int)$prod['id'] ?>" class="btn btn-warning btn-compact" title="Editar">✏️</a>
                                            <a href="eliminar_producto.php?id=<?= (int)$prod['id'] ?>" class="btn btn-danger btn-compact"
                                               onclick="return confirm('¿Estás seguro de eliminar este producto?')" title="Eliminar">🗑️</a>
                                        <?php endif; ?>
                                        <?php if (!$esAdmin): ?>
                                            <!-- Solicitud individual (se mantiene) -->
                                            <a href="generar_solicitud.php?product_id=<?= (int)$prod['id'] ?>" class="btn btn-primary btn-compact" title="Solicitar este producto">📦</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<footer class="bg-light text-center text-muted py-2 mt-4 border-top">
    Desarrollado por Jader Muñoz - 2026
</footer>

<!-- JS locales -->
<script src="assets/jquery/jquery-3.7.1.min.js"></script>
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/datatables/js/jquery.dataTables.min.js"></script>
<script src="assets/datatables/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function () {
        $('#productos').DataTable({
            scrollX: true,
            pageLength: 10,
            language: {
                url: 'assets/datatables/i18n/es-ES.json'
            }
        });
    });
</script>

</body>
</html>
