<?php
session_start();
if (!isset($_SESSION['user']) || !in_array($_SESSION['role'], ['admin','editor'])) {
    header("Location: index.php");
    exit;
}

require 'config/db.php';

$mensaje = '';
$rolUsuario = $_SESSION['role'];
$areaUsuario = $_SESSION['area'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barcode     = trim($_POST['barcode'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quantity    = is_numeric($_POST['quantity'] ?? '') ? intval($_POST['quantity']) : null;
    $ubicacion   = trim($_POST['ubicacion'] ?? '');

    // Si es admin, puede elegir; si es editor, se fuerza su área
    if ($rolUsuario === 'admin') {
        $area = $_POST['area'] ?? '';
    } else {
        $area = $areaUsuario;
    }

    if ($description !== '' && $ubicacion !== '' && $quantity !== null && $area !== '') {
        // Validar unicidad de barcode si viene
        if ($barcode !== '') {
            $chk = $conn->prepare("SELECT id FROM products WHERE barcode = ?");
            $chk->execute([$barcode]);
            if ($chk->fetch()) {
                $mensaje = '⚠️ Este código de barras ya existe. Usa otro o edita el producto existente.';
            }
        }

        if ($mensaje === '') {
            $stmt = $conn->prepare("
                INSERT INTO products (barcode, description, quantity, ubicacion, area, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$barcode ?: null, $description, $quantity, $ubicacion, $area]);

            // LOG
            if (!empty($_SESSION['user_id'])) {
                $descLog = "Agregó un producto: {$description} ({$quantity}) en {$ubicacion} - Área: {$area}".($barcode ? " [{$barcode}]" : "");
                $log = $conn->prepare("INSERT INTO logs (user_id, action, details, created_at) VALUES (?, 'create_product', ?, NOW())");
                $log->execute([$_SESSION['user_id'], $descLog]);
            }

            $mensaje = '✅ Producto agregado con éxito.';
        }
    } else {
        $mensaje = '⚠️ Todos los campos son obligatorios.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Agregar Producto - Bodega Sonda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>
        <div>
            <span class="me-3 text-white">👤 <?= htmlspecialchars($_SESSION['user']) ?> (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - {$areaUsuario}" : "" ?>)</span>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver</a>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <h3 class="mb-4">Agregar nuevo producto</h3>

    <?php if ($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="row g-3">
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

            <div class="col-md-8">
                <label class="form-label">Descripción</label>
                <textarea name="description" class="form-control" required></textarea>
            </div>

            <div class="col-md-4">
                <label class="form-label">Cantidad</label>
                <input type="number" name="quantity" class="form-control" min="0" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Ubicación</label>
                <input type="text" name="ubicacion" class="form-control" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Área</label>
                <?php if ($rolUsuario === 'admin'): ?>
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
        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-success">Guardar</button>
            <a href="dashboard.php" class="btn btn-secondary">Volver</a>
        </div>
    </form>
</div>
</body>
</html>
