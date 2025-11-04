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

// De sesión: bodegas asignadas al usuario (se setean en el login)
$bodegasIds   = $_SESSION['bodegas_ids'] ?? [];   // array de ids (int)
$bodegasMap   = $_SESSION['bodegas_map'] ?? [];   // [id => nombre]

// ===== Cargar bodegas disponibles para el selector =====
if ($esAdmin) {
    // Admin ve todas las bodegas (SOLO id y nombre)
    $bStmt = $conn->query("SELECT id, nombre FROM bodegas ORDER BY nombre");
    $bodegasDisponibles = $bStmt->fetchAll(PDO::FETCH_KEY_PAIR); // [id=>nombre]
} else {
    // Editor ve solo sus bodegas asignadas (de la sesión)
    $bodegasDisponibles = $bodegasMap; // ya filtrado en login
}

// ======= POST: crear producto =======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barcode     = trim($_POST['barcode'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quantity    = is_numeric($_POST['quantity'] ?? '') ? intval($_POST['quantity']) : null;
    $ubicacion   = trim($_POST['ubicacion'] ?? '');
    $bodegaId    = isset($_POST['bodega_id']) ? (int)$_POST['bodega_id'] : 0;

    // Área: admin elige; editor usa la suya
    if ($esAdmin) {
        $area = $_POST['area'] ?? '';
    } else {
        $area = $areaUsuario;
    }

    // Validaciones básicas
    if ($description === '' || $ubicacion === '' || $quantity === null || $area === '' || $bodegaId === 0) {
        $mensaje = '⚠️ Todos los campos son obligatorios (incluida la Bodega).';
    }

    // Validar bodega permitida
    if ($mensaje === '') {
        if ($esAdmin) {
            // Debe existir en la tabla
            $chkB = $conn->prepare("SELECT COUNT(*) FROM bodegas WHERE id = ?");
            $chkB->execute([$bodegaId]);
            if ($chkB->fetchColumn() == 0) {
                $mensaje = '⚠️ La bodega seleccionada no existe.';
            }
        } else {
            // Debe estar en sus bodegas asignadas
            if (!array_key_exists($bodegaId, $bodegasDisponibles)) {
                $mensaje = '⚠️ No tienes permisos para agregar en la bodega seleccionada.';
            }
        }
    }

    // Validar unicidad de barcode si viene
    if ($mensaje === '' && $barcode !== '') {
        $chk = $conn->prepare("SELECT id FROM products WHERE barcode = ?");
        $chk->execute([$barcode]);
        if ($chk->fetch()) {
            $mensaje = '⚠️ Este código de barras ya existe. Usa otro o edita el producto existente.';
        }
    }

    if ($mensaje === '') {
        // Insertar producto con bodega_id
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

        // LOG
        if (!empty($_SESSION['user_id'])) {
            // obtener nombre bodega para el log
            $nombreBodega = '';
            if ($esAdmin) {
                $nb = $conn->prepare("SELECT nombre FROM bodegas WHERE id = ?");
                $nb->execute([$bodegaId]);
                $nombreBodega = (string)$nb->fetchColumn();
            } else {
                $nombreBodega = $bodegasDisponibles[$bodegaId] ?? ('ID '.$bodegaId);
            }

            $descLog = "Agregó un producto: {$description} ({$quantity}) en {$ubicacion} - Área: {$area} - Bodega: {$nombreBodega}".($barcode ? " [{$barcode}]" : "");
            $log = $conn->prepare("INSERT INTO logs (user_id, action, details, created_at) VALUES (?, 'create_product', ?, NOW())");
            $log->execute([$_SESSION['user_id'], $descLog]);
        }

        $mensaje = '✅ Producto agregado con éxito.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Agregar Producto - Bodega Sonda</title>
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">

  <!-- Bootstrap Icons local -->
  <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
</head>
<body class="bg-light">
<!-- NAVBAR UNIFICADO -->
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>
        <div class="d-flex align-items-center">
            <span class="me-3 text-white">
                Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?>
                / (<?= htmlspecialchars($_SESSION['role']) ?><?= !empty($_SESSION['area']) ? " - ".htmlspecialchars($_SESSION['area']) : "" ?>)
                <?php if (!empty($_SESSION['division_name'])): ?>
                    <span class="badge text-bg-secondary ms-2"><?= htmlspecialchars($_SESSION['division_name']) ?></span>
                <?php endif; ?>
            </span>

            <!-- 🔔 Campanita de Notificaciones -->
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
            <div class="dropdown me-3">
                <button class="btn btn-outline-light position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
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
            <a href="dashboard.php" class="btn btn-outline-light me-2">🏠 Dashboard</a>
            <a href="logout.php" class="btn btn-outline-light">Cerrar sesión</a>
        </div>
    </div>
</nav>


<div class="container mt-5">
    <h3 class="mb-4">Agregar nuevo producto</h3>

    <?php if ($mensaje): ?>
        <div class="alert alert-info"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if (!$esAdmin && empty($bodegasDisponibles)): ?>
        <div class="alert alert-warning">
            No tienes bodegas asignadas. Solicita al administrador que te asigne acceso a una bodega para poder crear productos.
        </div>
    <?php else: ?>
    <form method="POST">
        <div class="row g-3">

            <!-- Código de barras -->
            <div class="col-md-4">
                <label class="form-label">Código de barras (opcional)</label>
                <input
                    type="text"
                    name="barcode"
                    class="form-control"
                    value="<?= htmlspecialchars($_GET['barcode'] ?? '') ?>"
                    autofocus
                >
                <div class="form-text">Escanea aquí con la pistola. Si no tiene código, deja en blanco.</div>
            </div>

            <!-- Descripción -->
            <div class="col-md-8">
                <label class="form-label">Descripción</label>
                <textarea name="description" class="form-control" required></textarea>
            </div>

            <!-- Cantidad -->
            <div class="col-md-4">
                <label class="form-label">Cantidad</label>
                <input type="number" name="quantity" class="form-control" min="0" required>
            </div>

            <!-- Ubicación -->
            <div class="col-md-4">
                <label class="form-label">Ubicación</label>
                <input type="text" name="ubicacion" class="form-control" required>
            </div>

            <!-- Área -->
            <div class="col-md-4">
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
            <div class="col-md-6">
                <label class="form-label">Bodega</label>
                <select name="bodega_id" class="form-select" required <?= (!$esAdmin && empty($bodegasDisponibles)) ? 'disabled' : '' ?>>
                    <option value="">Seleccione una bodega</option>
                    <?php foreach ($bodegasDisponibles as $bid => $bname): ?>
                        <option value="<?= (int)$bid ?>"><?= htmlspecialchars($bname) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$esAdmin): ?>
                    <div class="form-text">Sólo puedes agregar en tus bodegas asignadas.</div>
                <?php endif; ?>
            </div>

        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-success" <?= (!$esAdmin && empty($bodegasDisponibles)) ? 'disabled' : '' ?>>Guardar</button>
            <a href="dashboard.php" class="btn btn-secondary">Volver</a>
        </div>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
