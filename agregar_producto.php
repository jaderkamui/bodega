<?php
session_start();
if (!isset($_SESSION['user']) || !in_array($_SESSION['role'], ['admin','editor'])) {
    header("Location: index.php");
    exit;
}

require 'config/db.php';

$mensaje      = '';
$rolUsuario   = $_SESSION['role'];
$areaUsuario  = $_SESSION['area'] ?? '';
$esAdmin      = ($rolUsuario === 'admin');

// Bodegas del usuario en sesión
$bodegasIds   = $_SESSION['bodegas_ids'] ?? [];
$bodegasMap   = $_SESSION['bodegas_map'] ?? [];

// Cargar bodegas disponibles
if ($esAdmin) {
    $bStmt = $conn->query("SELECT id, nombre FROM bodegas ORDER BY nombre");
    $bodegasDisponibles = $bStmt->fetchAll(PDO::FETCH_KEY_PAIR); // [id => nombre]
} else {
    $bodegasDisponibles = $bodegasMap;
}

// POST: crear producto
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barcode     = trim($_POST['barcode'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quantity    = is_numeric($_POST['quantity'] ?? '') ? (int)$_POST['quantity'] : null;
    $ubicacion   = trim($_POST['ubicacion'] ?? '');
    $bodegaId    = isset($_POST['bodega_id']) && ctype_digit($_POST['bodega_id']) ? (int)$_POST['bodega_id'] : 0;

    // Área: admin elige; editor usa la suya
    $area = $esAdmin ? (trim($_POST['area'] ?? '')) : $areaUsuario;

    // Validaciones
    if ($description === '' || $ubicacion === '' || $quantity === null || $quantity < 0 || $area === '' || $bodegaId === 0) {
        $mensaje = '⚠️ Todos los campos son obligatorios (incluida la Bodega y Área).';
    }

    // Permiso de bodega
    if ($mensaje === '') {
        if ($esAdmin) {
            $chkB = $conn->prepare("SELECT COUNT(*) FROM bodegas WHERE id = ?");
            $chkB->execute([$bodegaId]);
            if ($chkB->fetchColumn() == 0) {
                $mensaje = '⚠️ La bodega seleccionada no existe.';
            }
        } else {
            if (!array_key_exists($bodegaId, $bodegasDisponibles)) {
                $mensaje = '⚠️ No tienes permisos para agregar en la bodega seleccionada.';
            }
        }
    }

    // Unicidad de barcode dentro de la misma bodega
    if ($mensaje === '' && $barcode !== '') {
        $chk = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND bodega_id = ?");
        $chk->execute([$barcode, $bodegaId]);
        if ($chk->fetch()) {
            $mensaje = '⚠️ Este código de barras ya existe en esta bodega. Puedes usarlo en otra bodega distinta.';
        }
    }

    if ($mensaje === '') {
        // Insertar producto
        $stmt = $conn->prepare("
            INSERT INTO products (barcode, description, quantity, ubicacion, area, bodega_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $barcode !== '' ? $barcode : null,
            $description,
            $quantity,
            $ubicacion,
            $area,
            $bodegaId
        ]);

        $nuevoId = (int)$conn->lastInsertId();

        // LOG unificado
        if (!empty($_SESSION['user_id'])) {
            // Nombre de bodega para el log
            $nombreBodega = $bodegasDisponibles[$bodegaId] ?? 'ID ' . $bodegaId;

            $detalleLog = "Producto creado: ID {$nuevoId} - {$description} ({$quantity}) en {$ubicacion} - Área: {$area} - Bodega: {$nombreBodega}";
            if ($barcode !== '') {
                $detalleLog .= " - Código: {$barcode}";
            }

            $log = $conn->prepare("
                INSERT INTO logs (user_id, action, details, created_at)
                VALUES (?, 'product_created', ?, NOW())
            ");
            $log->execute([$_SESSION['user_id'], $detalleLog]);
        }

        $mensaje = '✅ Producto agregado con éxito. ID: ' . $nuevoId;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agregar Producto - Bodega</title>
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
    <style>
        body { background-color: #f5f6fa; }
        .navbar-logo { height: 60px; }
        @media (min-width: 768px) { .navbar-logo { height: 90px; } }
        .form-wrapper { max-width: 600px; margin: 1.5rem auto; }
        .form-control, .form-select { font-size: 1rem; padding: 0.6rem 0.75rem; }
        .btn-large { padding: 0.7rem 1.2rem; font-size: 1.05rem; }
        .page-padding { padding-bottom: 4rem; }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" class="navbar-logo">
        <span class="navbar-brand text-white mb-0">Sistema de Bodega</span>
        <div class="d-flex align-items-center">
            <span class="me-2 text-white d-none d-md-inline">
                <?= htmlspecialchars($_SESSION['user']) ?>
                (<?= htmlspecialchars($_SESSION['role']) ?><?= !empty($_SESSION['area']) ? " - ".htmlspecialchars($_SESSION['area']) : "" ?>)
                <?php if (!empty($_SESSION['division_name'])): ?>
                    <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars($_SESSION['division_name']) ?></span>
                <?php endif; ?>
            </span>

            <?php
            $userId = $_SESSION['user_id'] ?? null;
            $notiCount = 0;
            $notificaciones = [];

            if ($userId) {
                $stmtNoti = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
                $stmtNoti->execute([$userId]);
                $notificaciones = $stmtNoti->fetchAll(PDO::FETCH_ASSOC);
                $notiCount = count($notificaciones);
            }
            ?>
            <div class="dropdown me-2">
                <button class="btn btn-outline-light btn-sm position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    🔔
                    <?php if ($notiCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $notiCount ?>
                        </span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end noti-dropdown" style="max-height:300px;overflow-y:auto;">
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

<div class="container page-padding">
    <div class="form-wrapper">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-3 text-center">Agregar nuevo producto</h5>

                <?php if ($mensaje): ?>
                    <div class="alert alert-info py-2"><?= htmlspecialchars($mensaje) ?></div>
                <?php endif; ?>

                <?php if (!$esAdmin && empty($bodegasDisponibles)): ?>
                    <div class="alert alert-warning">
                        No tienes bodegas asignadas. Solicita al administrador que te asigne acceso a una bodega para poder crear productos.
                    </div>
                <?php else: ?>
                    <form method="POST" autocomplete="off">
                        <!-- Código de barras -->
                        <div class="mb-3">
                            <label class="form-label">Código de barras (opcional)</label>
                            <input type="text" name="barcode" class="form-control"
                                   value="<?= htmlspecialchars($_GET['barcode'] ?? '') ?>"
                                   autofocus placeholder="Escanea con PDA o escribe">
                        </div>

                        <!-- Descripción -->
                        <div class="mb-3">
                            <label class="form-label">Descripción</label>
                            <textarea name="description" class="form-control" rows="2" required></textarea>
                        </div>

                        <div class="row g-3">
                            <!-- Cantidad -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Cantidad</label>
                                <input type="number" name="quantity" class="form-control" min="0" required>
                            </div>

                            <!-- Ubicación -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Ubicación</label>
                                <input type="text" name="ubicacion" class="form-control" required>
                            </div>
                        </div>

                        <div class="row g-3 mt-3">
                            <!-- Área -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Área</label>
                                <?php if ($esAdmin): ?>
                                    <select name="area" class="form-select" required>
                                        <option value="">Seleccione un área</option>
                                        <option value="Radios">Radios</option>
                                        <option value="Redes">Redes</option>
                                        <option value="SCA">SCA (Control de Acceso)</option>
                                        <option value="Libreria">Librería</option>
                                    </select>
                                <?php else: ?>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($areaUsuario) ?>" readonly>
                                    <input type="hidden" name="area" value="<?= htmlspecialchars($areaUsuario) ?>">
                                <?php endif; ?>
                            </div>

                            <!-- Bodega -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">Bodega</label>
                                <select name="bodega_id" class="form-select" required <?= (!$esAdmin && empty($bodegasDisponibles)) ? 'disabled' : '' ?>>
                                    <option value="">Seleccione una bodega</option>
                                    <?php foreach ($bodegasDisponibles as $bid => $bname): ?>
                                        <option value="<?= (int)$bid ?>"><?= htmlspecialchars($bname) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$esAdmin): ?>
                                    <div class="form-text">Solo puedes agregar en tus bodegas asignadas.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-4 d-flex justify-content-between gap-2">
                            <a href="dashboard.php" class="btn btn-secondary btn-large w-50">Volver</a>
                            <button type="submit" class="btn btn-success btn-large w-50" <?= (!$esAdmin && empty($bodegasDisponibles)) ? 'disabled' : '' ?>>
                                Guardar producto
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>