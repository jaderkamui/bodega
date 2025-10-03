<?php
session_start();
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'editor') {
    header("Location: dashboard.php");
    exit;
}

require 'config/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: dashboard.php");
    exit;
}

$id = (int)$_GET['id'];
$mensaje = '';

// Obtener producto actual
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$producto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$producto) {
    $mensaje = "Producto no encontrado.";
}

// Diccionario de traducciones de campos
$traducciones = [
    'barcode'     => 'Código de barras',
    'description' => 'Descripción',
    'quantity'    => 'Cantidad',
    'ubicacion'   => 'Ubicación'
];

// Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $producto) {
    $barcode     = trim($_POST['barcode'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quantity    = is_numeric($_POST['quantity'] ?? '') ? intval($_POST['quantity']) : null;
    $ubicacion   = trim($_POST['ubicacion'] ?? '');

    // Validar unicidad de barcode
    if ($barcode !== '') {
        $chk = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND id <> ?");
        $chk->execute([$barcode, $id]);
        if ($chk->fetch()) {
            $mensaje = '⚠️ El código de barras ingresado ya existe en otro producto.';
        }
    }

    if (!$mensaje) {
        // Detectar cambios
        $logsPreparados = [];

        if ($producto['barcode'] != $barcode) {
            $logsPreparados[] = ['campo' => 'barcode', 'old' => $producto['barcode'], 'new' => $barcode];
        }
        if ($producto['description'] != $description) {
            $logsPreparados[] = ['campo' => 'description', 'old' => $producto['description'], 'new' => $description];
        }
        if ($producto['quantity'] != $quantity) {
            $logsPreparados[] = ['campo' => 'quantity', 'old' => $producto['quantity'], 'new' => $quantity];
        }
        if ($producto['ubicacion'] != $ubicacion) {
            $logsPreparados[] = ['campo' => 'ubicacion', 'old' => $producto['ubicacion'], 'new' => $ubicacion];
        }

        // Actualizar producto
        $stmt = $conn->prepare("
            UPDATE products
            SET barcode = ?, description = ?, quantity = ?, ubicacion = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$barcode ?: null, $description, $quantity, $ubicacion, $id]);

        // Registrar en logs solo si hubo cambios
        if (!empty($logsPreparados) && !empty($_SESSION['user_id'])) {
            foreach ($logsPreparados as $c) {
                $nombreCampo = $traducciones[$c['campo']] ?? $c['campo'];
                $log = $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at) 
                                       VALUES (?, 'edit_product', ?, ?, ?, NOW())");
                $log->execute([
                    $_SESSION['user_id'],
                    "Editó producto ID {$id} - Campo {$nombreCampo}",
                    $c['old'],
                    $c['new']
                ]);
            }
        }

        // Refrescar datos
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
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
    <title>Editar Producto - Bodega Sonda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>
        <div>
            <span class="me-3 text-white">👤 <?= htmlspecialchars($_SESSION['user']) ?> (<?= $_SESSION['role'] ?>)</span>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver</a>
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
                    <input type="number" name="quantity" class="form-control" value="<?= (int)$producto['quantity'] ?>" required>
                </div>

                <!-- UBICACIÓN -->
                <div class="col-md-8">
                    <label class="form-label">Ubicación</label>
                    <input type="text" name="ubicacion" class="form-control" value="<?= htmlspecialchars($producto['ubicacion']) ?>" required>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
                <a href="dashboard.php" class="btn btn-secondary">Volver</a>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
