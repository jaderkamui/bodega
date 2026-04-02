<?php
session_start();
if (!isset($_SESSION['user']) || !in_array($_SESSION['role'], ['admin','editor'])) {
    header("Location: dashboard.php");
    exit;
}
require 'config/db.php';

// Zona horaria Chile (para que las fechas sean correctas)
date_default_timezone_set('America/Santiago');

// Filtros comunes
$tipoReporte   = $_GET['tipo'] ?? 'stock'; // stock | entregados
$bodegaId      = (int)($_GET['bodega_id'] ?? 0);
$areaFiltro    = trim($_GET['area'] ?? '');
$busqueda      = trim($_GET['busqueda'] ?? '');
$desde         = $_GET['desde'] ?? '';
$hasta         = $_GET['hasta'] ?? '';
$solicitante   = trim($_GET['solicitante'] ?? '');

$mensaje = '';
$areaUsuario  = $_SESSION['area'] ?? null;
$divisionName = $_SESSION['division_name'] ?? null;

// Todas las bodegas
$bodegas = $conn->query("SELECT id, nombre FROM bodegas WHERE activa = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Todas las áreas únicas
$areas = $conn->query("SELECT DISTINCT area FROM products WHERE area IS NOT NULL AND area <> '' ORDER BY area")->fetchAll(PDO::FETCH_COLUMN);

// Todos los usuarios (para filtro de solicitante)
$usuarios = $conn->query("SELECT id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Notificaciones navbar (solo no leídas)
$adminId = (int)($_SESSION['user_id'] ?? 0);
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$adminId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);

// Reporte: Stock actual
$stock = [];
if ($tipoReporte === 'stock') {
    $sql = "SELECT p.id, p.description, p.quantity, p.ubicacion, p.area, b.nombre AS bodega
            FROM products p
            LEFT JOIN bodegas b ON b.id = p.bodega_id
            WHERE p.quantity > 0";
    $params = [];

    if ($bodegaId > 0) {
        $sql .= " AND p.bodega_id = ?";
        $params[] = $bodegaId;
    }
    if ($areaFiltro !== '') {
        $sql .= " AND p.area = ?";
        $params[] = $areaFiltro;
    }
    if ($busqueda !== '') {
        $sql .= " AND p.description LIKE ?";
        $params[] = "%$busqueda%";
    }
    $sql .= " ORDER BY b.nombre, p.description";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Reporte: Entregados / Retirados
$entregados = [];
if ($tipoReporte === 'entregados') {
    $sql = "
        SELECT 
            s.id AS solicitud_id, s.created_at AS fecha_solicitud,
            si.cant_entregada, si.estado_item,
            p.description, p.ubicacion, p.area,
            b.nombre AS bodega,
            u.username AS solicitante
        FROM solicitud_items si
        JOIN solicitudes s ON s.id = si.solicitud_id
        JOIN products p ON p.id = si.product_id
        LEFT JOIN bodegas b ON b.id = p.bodega_id
        JOIN users u ON u.id = s.user_id
        WHERE si.cant_entregada > 0
    ";
    $params = [];

    if ($bodegaId > 0) {
        $sql .= " AND p.bodega_id = ?";
        $params[] = $bodegaId;
    }
    if ($desde !== '') {
        $sql .= " AND s.created_at >= ?";
        $params[] = $desde . ' 00:00:00';
    }
    if ($hasta !== '') {
        $sql .= " AND s.created_at <= ?";
        $params[] = $hasta . ' 23:59:59';
    }
    if ($solicitante !== '') {
        $sql .= " AND u.username = ?";
        $params[] = $solicitante;
    }
    $sql .= " ORDER BY s.created_at DESC LIMIT 1000";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $entregados = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reportes de Bodega</title>
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <style>
        body { 
            font-family: Arial, Helvetica, sans-serif; 
            background: #f8f9fa; 
            margin: 0; 
        }
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 1rem; 
        }
        .navbar { 
            padding: 0.5rem 1rem; 
        }
        .navbar-logo { 
            height: 45px; 
        }
        .report-title { 
            text-align: center; 
            margin: 1rem 0; 
        }
        .filters-summary { 
            background: #f1f3f5; 
            padding: 0.75rem; 
            border-radius: 0.25rem; 
            margin-bottom: 1rem; 
            font-size: 0.9rem; 
        }
        table { 
            font-size: 0.9rem; 
        }
        th, td { 
            vertical-align: middle; 
        }
        /* Impresión optimizada */
        @media print {
            body { 
                background: white; 
                font-size: 9pt; 
            }
            .no-print { 
                display: none !important; 
            }
            .container { 
                padding: 0; 
                margin: 0; 
                width: 100%; 
            }
            .report-title h1 { 
                font-size: 14pt; 
                margin: 0 0 8pt 0; 
            }
            .report-meta { 
                font-size: 9pt; 
                color: #444; 
                margin-bottom: 12pt; 
            }
            .filters-summary { 
                font-size: 8pt; 
                margin: 0 0 8pt 0; 
                padding: 0; 
                border: none; 
                background: none; 
            }
            table { 
                width: 100%; 
                border-collapse: collapse; 
                page-break-inside: auto; 
            }
            tr { 
                page-break-inside: avoid; 
                page-break-after: auto; 
            }
            th, td { 
                border: 1px solid #000; 
                padding: 3pt 4pt; 
            }
            th { 
                background: #e9ecef; 
            }
            .text-end { 
                text-align: right; 
            }
            .page-break { 
                page-break-before: always; 
            }
        }
        @page { 
            margin: 10mm; 
        }
    </style>
</head>
<body>

<!-- Navbar (solo en pantalla) -->
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" class="navbar-logo">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega</span>
        <div class="d-flex align-items-center">
            <span class="me-3 text-white">
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
    <!-- Título y botón de imprimir (solo pantalla) -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h3 class="mb-0">Reportes de Bodega</h3>
        <button class="btn btn-outline-dark btn-sm" onclick="window.print()">🖨️ Imprimir listado</button>
    </div>

    <!-- Pestañas (solo pantalla) -->
    <ul class="nav nav-tabs mb-4 no-print">
        <li class="nav-item">
            <a class="nav-link <?= $tipoReporte === 'stock' ? 'active' : '' ?>" href="?tipo=stock">Stock Actual</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tipoReporte === 'entregados' ? 'active' : '' ?>" href="?tipo=entregados">Productos Entregados</a>
        </li>
    </ul>

    <!-- Filtros (solo pantalla) -->
    <form method="get" class="row g-3 mb-4 no-print">
        <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipoReporte) ?>">

        <div class="col-md-3 col-sm-6">
            <label class="form-label">Bodega</label>
            <select name="bodega_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">Todas las bodegas</option>
                <?php foreach ($bodegas as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $bodegaId == $b['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($tipoReporte === 'stock'): ?>
            <div class="col-md-3 col-sm-6">
                <label class="form-label">Área</label>
                <select name="area" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Todas las áreas</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $areaFiltro === $a ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-sm-6">
                <label class="form-label">Buscar producto</label>
                <input type="text" name="busqueda" class="form-control form-control-sm" 
                       value="<?= htmlspecialchars($busqueda) ?>" placeholder="Nombre..." onchange="this.form.submit()">
            </div>
        <?php elseif ($tipoReporte === 'entregados'): ?>
            <div class="col-md-2 col-sm-6">
                <label class="form-label">Desde</label>
                <input type="date" name="desde" class="form-control form-control-sm" 
                       value="<?= htmlspecialchars($desde) ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label">Hasta</label>
                <input type="date" name="hasta" class="form-control form-control-sm" 
                       value="<?= htmlspecialchars($hasta) ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-3 col-sm-6">
                <label class="form-label">Solicitante</label>
                <select name="solicitante" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= htmlspecialchars($u['username']) ?>" <?= $solicitante === $u['username'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="col-md-2 col-sm-6 d-flex align-items-end">
            <a href="?tipo=<?= htmlspecialchars($tipoReporte) ?>" class="btn btn-outline-secondary btn-sm w-100">Limpiar</a>
        </div>
    </form>

    <!-- Contenido del reporte (visible en pantalla e impresión) -->
    <?php if ($tipoReporte === 'stock'): ?>
        <h5 class="mb-3">Stock actual (productos con cantidad > 0)</h5>
        <?php if (empty($stock)): ?>
            <div class="alert alert-info">No hay stock con los filtros aplicados.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>Producto</th>
                            <th>Bodega</th>
                            <th>Área</th>
                            <th>Ubicación</th>
                            <th class="text-end">Cantidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stock as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['description']) ?></td>
                                <td><?= htmlspecialchars($p['bodega'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($p['area'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($p['ubicacion'] ?? '—') ?></td>
                                <td class="text-end fw-bold"><?= number_format($p['quantity'], 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php elseif ($tipoReporte === 'entregados'): ?>
        <h5 class="mb-3">Histórico de productos entregados</h5>
        <?php if (empty($entregados)): ?>
            <div class="alert alert-info">No hay entregas registradas con los filtros aplicados.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>Fecha</th>
                            <th>Solicitud</th>
                            <th>Producto</th>
                            <th class="text-end">Entregado</th>
                            <th>Solicitante</th>
                            <th>Bodega</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entregados as $e): ?>
                            <tr>
                                <td><?= date('d/m/Y H:i', strtotime($e['fecha_solicitud'])) ?></td>
                                <td>#<?= (int)$e['solicitud_id'] ?></td>
                                <td><?= htmlspecialchars($e['description']) ?></td>
                                <td class="text-end"><?= (int)$e['cant_entregada'] ?></td>
                                <td><?= htmlspecialchars($e['solicitante']) ?></td>
                                <td><?= htmlspecialchars($e['bodega'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Botón volver (solo pantalla) -->
    <a href="dashboard.php" class="btn btn-secondary mt-4 no-print">Volver al Dashboard</a>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>