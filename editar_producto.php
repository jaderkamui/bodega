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
            // Sin permiso para editar esta bodega
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
$userId = $_SESSION['user_id'];
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$userId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);

// Traducción bonita de campos para logs
$traducciones = [
    'barcode'     => 'Código de barras',
    'description' => 'Descripción',
    'quantity'    => 'Cantidad',
    'ubicacion'   => 'Ubicación',
    'area'        => 'Área',
    'bodega_id'   => 'Bodega'
];

// ===== Guardar cambios =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $producto) {
    $barcode     = trim($_POST['barcode'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quantity    = is_numeric($_POST['quantity'] ?? '') ? (int)$_POST['quantity'] : null;
    $ubicacion   = trim($_POST['ubicacion'] ?? '');
    $bodega_id   = isset($_POST['bodega_id']) && ctype_digit($_POST['bodega_id']) ? (int)$_POST['bodega_id'] : null;

    // Área: admin puede cambiar; editor queda fija a su área
    if ($esAdmin) {
        $area = $_POST['area'] ?? '';
    } else {
        $area = $areaUsuario;
    }

    // Validaciones básicas
    if ($description === '' || $ubicacion === '' || $quantity === null || $quantity < 0 || !$bodega_id) {
        $mensaje = '⚠️ Todos los campos son obligatorios y válidos.';
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
        // Detectar cambios
        $logsPreparados = [];

        if (($producto['barcode'] ?? null) !== ($barcode !== '' ? $barcode : null)) {
            $logsPreparados[] = ['campo' => 'barcode', 'old' => $producto['barcode'], 'new' => ($barcode !== '' ? $barcode : null)];
        }
        if ($producto['description'] != $description) {
            $logsPreparados[] = ['campo' => 'description', 'old' => $producto['description'], 'new' => $description];
        }
        if ((int)$producto['quantity'] !== (int)$quantity) {
            $logsPreparados[] = ['campo' => 'quantity', 'old' => $producto['quantity'], 'new' => $quantity];
        }
        if ($producto['ubicacion'] != $ubicacion) {
            $logsPreparados[] = ['campo' => 'ubicacion', 'old' => $producto['ubicacion'], 'new' => $ubicacion];
        }
        if (($producto['area'] ?? '') !== $area) {
            $logsPreparados[] = ['campo' => 'area', 'old' => $producto['area'], 'new' => $area];
        }
        if ((int)($producto['bodega_id'] ?? 0) !== (int)$bodega_id) {
            // Para el log, mostramos el nombre legible
            $oldName = $todasBodegas[(int)($producto['bodega_id'] ?? 0)] ?? $producto['bodega_nombre'] ?? '—';
            // Como admin puede ver todas, resolvemos nombre de la nueva bodega:
            $newName = $todasBodegas[$bodega_id] ?? (function($conn,$bodega_id){
                $q=$conn->prepare("SELECT nombre FROM bodegas WHERE id=?"); $q->execute([$bodega_id]); return $q->fetchColumn() ?: '—';
            })($conn,$bodega_id);
            $logsPreparados[] = ['campo' => 'bodega_id', 'old' => $oldName, 'new' => $newName];
        }

        // Actualizar
        $upd = $conn->prepare("
            UPDATE products
            SET barcode = ?, description = ?, quantity = ?, ubicacion = ?, area = ?, bodega_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$barcode !== '' ? $barcode : null, $description, $quantity, $ubicacion, $area, $bodega_id, $id]);

        // Logs (si hubo cambios)
        if (!empty($logsPreparados) && !empty($_SESSION['user_id'])) {
            foreach ($logsPreparados as $c) {
                $nombreCampo = $traducciones[$c['campo']] ?? $c['campo'];
                $log = $conn->prepare("
                    INSERT INTO logs (user_id, action, details, old_value, new_value, created_at)
                    VALUES (?, 'edit_product', ?, ?, ?, NOW())
                ");
                $log->execute([
                    $_SESSION['user_id'],
                    "Editó producto ID {$id} — Campo {$nombreCampo}",
                    is_scalar($c['old']) ? (string)$c['old'] : json_encode($c['old']),
                    is_scalar($c['new']) ? (string)$c['new'] : json_encode($c['new']),
                ]);
            }
        }

        // Refrescar producto
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
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">

  <!-- Bootstrap Icons local -->
  <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
    <style>.noti-dropdown{max-height:300px;overflow-y:auto;}</style>
</head>
<body class="bg-light">

<!-- NAVBAR igual al dashboard -->
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
          <a href="dashboard.php" class="btn btn-outline-light me-2">🏠 Dashboard</a>
            <a href="logout.php" class="btn btn-outline-light">Cerrar sesión</a>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <h3 class="mb-4">Editar producto</h3>

    <?php if ($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <?php if ($producto): ?>
    <form method="POST">
        <div class="row g-3">

            <!-- BARCODE -->
            <div class="col-md-4">
                <label class="form-label">Código de barras</label>
                <input type="text" name="barcode" class="form-control"
                       value="<?= htmlspecialchars($producto['barcode'] ?? '') ?>"
                       placeholder="Escanea o escribe el código (opcional)">
            </div>

            <!-- DESCRIPCIÓN -->
            <div class="col-md-8">
                <label class="form-label">Descripción</label>
                <textarea name="description" class="form-control" required><?= htmlspecialchars($producto['description']) ?></textarea>
            </div>

            <!-- CANTIDAD -->
            <div class="col-md-4">
                <label class="form-label">Cantidad</label>
                <input type="number" name="quantity" class="form-control" value="<?= (int)$producto['quantity'] ?>" min="0" required>
            </div>

            <!-- UBICACIÓN -->
            <div class="col-md-8">
                <label class="form-label">Ubicación</label>
                <input type="text" name="ubicacion" class="form-control" value="<?= htmlspecialchars($producto['ubicacion']) ?>" required>
            </div>

            <!-- ÁREA -->
            <div class="col-md-4">
                <label class="form-label">Área</label>
                <?php if ($esAdmin): ?>
                    <select name="area" class="form-select" required>
                        <?php
                        $areas = ['Radios','Redes','SCA','Libreria'];
                        foreach ($areas as $a):
                        ?>
                            <option value="<?= $a ?>" <?= ($producto['area']===$a ? 'selected':'') ?>><?= $a === 'SCA' ? 'SCA (Control de Acceso)' : $a ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($areaUsuario) ?>" readonly>
                    <input type="hidden" name="area" value="<?= htmlspecialchars($areaUsuario) ?>">
                <?php endif; ?>
            </div>

            <!-- BODEGA -->
            <div class="col-md-8">
                <label class="form-label">Bodega</label>
                <select name="bodega_id" class="form-select" required <?= (!$esAdmin && empty($todasBodegas)) ? 'disabled' : '' ?>>
                    <?php foreach ($todasBodegas as $bid => $bname): ?>
                        <option value="<?= (int)$bid ?>" <?= ((int)$producto['bodega_id']===(int)$bid ? 'selected':'') ?>>
                            <?= htmlspecialchars($bname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$esAdmin && empty($todasBodegas)): ?>
                    <div class="form-text text-danger">No tienes bodegas asignadas. Solicita acceso al administrador.</div>
                <?php endif; ?>
            </div>

        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-primary">Guardar cambios</button>
            <a href="dashboard.php" class="btn btn-secondary">Volver</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
