<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

require 'config/db.php';



// Determinar permisos
$rol = $_SESSION['role'] ?? 'viewer';
$puedeEditar = in_array($rol, ['admin', 'editor']);
$esAdmin = $rol === 'admin';
$areaUsuario = $_SESSION['area'] ?? null;

// Obtener productos según área
if ($esAdmin) {
    $stmt = $conn->query("SELECT * FROM products ORDER BY created_at DESC");
} else {
    $stmt = $conn->prepare("SELECT * FROM products WHERE area = ? ORDER BY created_at DESC");
    $stmt->execute([$areaUsuario]);
}
$products = $stmt->fetchAll();

// 📌 Obtener notificaciones no leídas
$userId = $_SESSION['user_id'];
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$userId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);

// Contar notificaciones no leídas
$notiCount = count($notificaciones);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de Control - Bodega Sonda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        .noti-dropdown {
            max-height: 300px;
            overflow-y: auto;
        }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>
        <div class="d-flex align-items-center">
            <span class="me-3 text-white">
                Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?> 
                / (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - $areaUsuario" : "" ?>)
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
                                    <small class="text-muted"><?= $n['created_at'] ?></small>
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
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h3 class="mb-3 mb-md-0">Productos en Bodega</h3>

        <div class="d-flex gap-2">
            <?php if ($esAdmin): ?>
                <a href="admin_usuarios.php" class="btn btn-secondary">⚙️ Editar Usuarios</a>
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
        <div class="alert alert-warning">No hay productos registrados.</div>
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
                        <th>Fecha Ingreso</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $prod): ?>
                        <tr>
                            <td><?= $prod['id'] ?></td>
                            <td><?= htmlspecialchars($prod['description']) ?></td>
                            <td><?= htmlspecialchars($prod['barcode'] ?? '') ?></td>
                            <td><?= $prod['quantity'] ?? 0 ?></td>
                            <td>
                                <?= htmlspecialchars($prod['ubicacion']) ?>
                                <a href="ver_ubicacion.php?ubicacion=<?= urlencode($prod['ubicacion']) ?>" class="btn btn-info btn-sm ms-2">ver</a>
                            </td>
                            <td><?= htmlspecialchars($prod['area']) ?></td>
                            <td><?= $prod['created_at'] ?></td>
                            <td>
                                <?php if ($puedeEditar): ?>
                                    <a href="editar_producto.php?id=<?= $prod['id'] ?>" class="btn btn-sm btn-warning" title="Editar">✏️</a>
                                    <a href="eliminar_producto.php?id=<?= $prod['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Estás seguro de eliminar este producto?')" title="Eliminar">🗑️</a>
                                <?php endif; ?>

                                <?php if (!$esAdmin): ?>
                                    <a href="generar_solicitud.php?product_id=<?= $prod['id'] ?>" class="btn btn-sm btn-primary">📦 Solicitar</a>
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
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.0/dist/jquery.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    $(document).ready(function () {
        $('#productos').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/Spanish.json'
            },
            pageLength: 10
        });
    });
</script>

<footer class="bg-light text-center text-muted py-3 mt-5 border-top">
    Desarrollado por Jader Muñoz
</footer>

</body>
</html>
