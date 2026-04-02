<?php
session_start();
if (!isset($_SESSION['user']) || !in_array($_SESSION['role'], ['admin','editor'])) {
    header("Location: index.php");
    exit;
}

require 'config/db.php';

$rol          = $_SESSION['role'];
$esAdmin      = ($rol === 'admin');
$areaUsuario  = $_SESSION['area'] ?? '';
$userId       = $_SESSION['user_id'];
$divisionName = $_SESSION['division_name'] ?? null;

// Bodegas del usuario en sesión (para no-admin)
$bodegasIds = $_SESSION['bodegas_ids'] ?? [];      // [1,5,...]
$bodegasMap = $_SESSION['bodegas_map'] ?? [];      // [id => nombre]

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    header("Location: dashboard.php");
    exit;
}
$id = (int)$_GET['id'];

$mensaje = '';

// Traducción bonita de campos para logs
$traducciones = [
    'barcode'     => 'Código de barras',
    'description' => 'Descripción',
    'quantity'    => 'Cantidad',
    'ubicacion'   => 'Ubicación',
    'area'        => 'Área',
    'bodega_id'   => 'Bodega'
];

// ===== Cargar producto actual con su bodega =====
$stmt = $conn->prepare("
    SELECT p.*, b.nombre AS bodega_nombre
    FROM products p
    LEFT JOIN bodegas b ON b.id = p.bodega_id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$producto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$producto) {
    $mensaje = "Producto no encontrado.";
} else {
    // Si es editor, aseguramos que el producto esté en una de SUS bodegas
    if (!$esAdmin) {
        if (empty($bodegasIds) || !in_array((int)($producto['bodega_id'] ?? 0), $bodegasIds, true)) {
            header("Location: dashboard.php?err=permiso_bodega");
            exit;
        }
    }
}

// ===== Cargar bodegas para el selector =====
if ($esAdmin) {
    $bStmt = $conn->query("SELECT id, nombre FROM bodegas ORDER BY nombre");
    $todasBodegas = $bStmt->fetchAll(PDO::FETCH_KEY_PAIR); // [id => nombre]
} else {
    $todasBodegas = $bodegasMap; // solo las asignadas al usuario
}

// ===== Notificaciones no leídas =====
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$userId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);

// ===== Guardar cambios =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $producto) {
    $barcode     = trim($_POST['barcode'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quantity    = is_numeric($_POST['quantity'] ?? '') ? (int)$_POST['quantity'] : null;
    $ubicacion   = trim($_POST['ubicacion'] ?? '');
    $bodega_id   = isset($_POST['bodega_id']) && ctype_digit($_POST['bodega_id']) ? (int)$_POST['bodega_id'] : null;

    // Área: admin puede cambiar; editor queda fija a su área
    $area = $esAdmin ? ($_POST['area'] ?? '') : $areaUsuario;

    // Validaciones básicas
    if ($description === '' || $ubicacion === '' || $quantity === null || $quantity < 0 || !$bodega_id) {
        $mensaje = '⚠️ Todos los campos obligatorios deben estar completos y válidos.';
    }

    // Permiso: si es editor, la bodega elegida debe estar entre sus bodegas
    if (!$esAdmin && $bodega_id && !in_array($bodega_id, $bodegasIds, true)) {
        $mensaje = '⚠️ No tienes permisos para mover el producto a esa bodega.';
    }

    // Unicidad de barcode
    if (!$mensaje && $barcode !== '') {
        $chk = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND id <> ?");
        $chk->execute([$barcode, $id]);
        if ($chk->fetch()) {
            $mensaje = '⚠️ El código de barras ingresado ya existe en otro producto.';
        }
    }

    if (!$mensaje) {
        // Detectar cambios para log
        $cambios = [];

        if (($producto['barcode'] ?? null) !== ($barcode !== '' ? $barcode : null)) {
            $cambios[] = ['campo' => 'barcode', 'old' => $producto['barcode'], 'new' => ($barcode !== '' ? $barcode : null)];
        }
        if ($producto['description'] !== $description) {
            $cambios[] = ['campo' => 'description', 'old' => $producto['description'], 'new' => $description];
        }
        if ((int)$producto['quantity'] !== (int)$quantity) {
            $cambios[] = ['campo' => 'quantity', 'old' => $producto['quantity'], 'new' => $quantity];
        }
        if ($producto['ubicacion'] !== $ubicacion) {
            $cambios[] = ['campo' => 'ubicacion', 'old' => $producto['ubicacion'], 'new' => $ubicacion];
        }
        if (($producto['area'] ?? '') !== $area) {
            $cambios[] = ['campo' => 'area', 'old' => $producto['area'], 'new' => $area];
        }
        if ((int)($producto['bodega_id'] ?? 0) !== $bodega_id) {
            $oldName = $todasBodegas[(int)($producto['bodega_id'] ?? 0)] ?? '—';
            $newName = $todasBodegas[$bodega_id] ?? '—';
            $cambios[] = ['campo' => 'bodega_id', 'old' => $oldName, 'new' => $newName];
        }

        // Actualizar producto
        $upd = $conn->prepare("
            UPDATE products
            SET barcode = ?, description = ?, quantity = ?, ubicacion = ?, area = ?, bodega_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$barcode !== '' ? $barcode : null, $description, $quantity, $ubicacion, $area, $bodega_id, $id]);

        // Registrar logs unificados (solo si hubo cambios)
        if (!empty($cambios)) {
            foreach ($cambios as $c) {
                $nombreCampo = $traducciones[$c['campo']] ?? ucfirst($c['campo']);
                $detalle = "Producto ID {$id} - Campo '{$nombreCampo}' modificado";
                $oldVal = is_scalar($c['old']) ? (string)$c['old'] : json_encode($c['old']);
                $newVal = is_scalar($c['new']) ? (string)$c['new'] : json_encode($c['new']);

                $log = $conn->prepare("
                    INSERT INTO logs (user_id, action, details, old_value, new_value, created_at)
                    VALUES (?, 'product_modified', ?, ?, ?, NOW())
                ");
                $log->execute([$userId, $detalle, $oldVal, $newVal]);
            }
        }

        // Refrescar producto después de actualización
        $stmt = $conn->prepare("
            SELECT p.*, b.nombre AS bodega_nombre
            FROM products p
            LEFT JOIN bodegas b ON b.id = p.bodega_id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        $mensaje = '✅ Producto actualizado con éxito.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Producto - Bodega</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
    <style>
        body { background-color: #f5f5f5; }
        #logo-egg { height:72px; }
        .navbar-brand { font-size:1.05rem; }
        .user-label { font-size:0.85rem; line-height:1.2; text-align:right; }
        @media (max-width:576px) {
            #logo-egg { height:52px; }
            .navbar-brand { font-size:0.95rem; }
            .user-label { font-size:0.8rem; }
        }
        .noti-dropdown { max-height:300px; overflow-y:auto; }
        .edit-wrapper { max-width:600px; margin:1.5rem auto 2.5rem auto; }
        .edit-card { border-radius:0.85rem; box-shadow:0 3px 12px rgba(0,0,0,0.08); }
        .edit-card .card-header { font-weight:600; }
        label.form-label { font-size:0.9rem; font-weight:600; }
        @media (max-width:576px) {
            .btn-full-mobile { width:100%; }
            .btn-group-mobile { display:flex; flex-direction:column; gap:0.5rem; }
        }
    </style>
</head>
<body class="bg-light">

<!-- NAVBAR -->
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img id="logo-egg" src="assets/logo.png" alt="Sonda Logo">
        <span class="navbar-brand mb-0 text-white">Sistema de Bodega</span>
        <div class="d-flex align-items-center">
            <span class="me-2 text-white user-label">
                <?= htmlspecialchars($_SESSION['user']) ?><br>
                <small>
                    (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                    <?php if ($divisionName): ?> — <span class="badge text-bg-secondary"><?= htmlspecialchars($divisionName) ?></span><?php endif; ?>
                </small>
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

<div class="container">
    <div class="edit-wrapper">
        <div class="card edit-card">
            <div class="card-header">
                Editar producto
            </div>
            <div class="card-body">
                <?php if ($mensaje): ?>
                    <div class="alert alert-info py-2"><?= htmlspecialchars($mensaje) ?></div>
                <?php endif; ?>

                <?php if ($producto): ?>
                <form method="POST">
                    <div class="row g-3">

                        <!-- Código de barras -->
                        <div class="col-12">
                            <label class="form-label">Código de barras</label>
                            <input type="text" name="barcode" class="form-control"
                                   value="<?= htmlspecialchars($producto['barcode'] ?? '') ?>"
                                   placeholder="Escanea o escribe el código (opcional)">
                        </div>

                        <!-- Descripción -->
                        <div class="col-12">
                            <label class="form-label">Descripción</label>
                            <textarea name="description" class="form-control" rows="2" required>
                                <?= htmlspecialchars($producto['description']) ?>
                            </textarea>
                        </div>

                        <!-- Cantidad -->
                        <div class="col-12 col-md-4">
                            <label class="form-label">Cantidad</label>
                            <input type="number" name="quantity" class="form-control"
                                   value="<?= (int)$producto['quantity'] ?>" min="0" required>
                        </div>

                        <!-- Ubicación -->
                        <div class="col-12 col-md-8">
                            <label class="form-label">Ubicación</label>
                            <input type="text" name="ubicacion" class="form-control"
                                   value="<?= htmlspecialchars($producto['ubicacion']) ?>" required>
                        </div>

                        <!-- Área -->
                        <div class="col-12 col-md-4">
                            <label class="form-label">Área</label>
                            <?php if ($esAdmin): ?>
                                <select name="area" class="form-select" required>
                                    <?php
                                    $areas = ['Radios','Redes','SCA','Libreria'];
                                    foreach ($areas as $a):
                                    ?>
                                        <option value="<?= $a ?>" <?= ($producto['area'] === $a ? 'selected':'') ?>>
                                            <?= $a === 'SCA' ? 'SCA (Control de Acceso)' : $a ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($areaUsuario) ?>" readonly>
                                <input type="hidden" name="area" value="<?= htmlspecialchars($areaUsuario) ?>">
                            <?php endif; ?>
                        </div>

                        <!-- Bodega -->
                        <div class="col-12 col-md-8">
                            <label class="form-label">Bodega</label>
                            <select name="bodega_id" class="form-select" required
                                    <?= (!$esAdmin && empty($todasBodegas)) ? 'disabled' : '' ?>>
                                <?php foreach ($todasBodegas as $bid => $bname): ?>
                                    <option value="<?= (int)$bid ?>" <?= ((int)$producto['bodega_id'] === (int)$bid ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($bname) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$esAdmin && empty($todasBodegas)): ?>
                                <div class="form-text text-danger mt-1">
                                    No tienes bodegas asignadas. Solicita acceso al administrador.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-4 btn-group-mobile d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary btn-full-mobile">💾 Guardar cambios</button>
                        <a href="dashboard.php" class="btn btn-secondary btn-full-mobile">⬅ Volver</a>
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