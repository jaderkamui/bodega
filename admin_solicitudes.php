<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require 'config/db.php';

// 📌 Marcar como leídas SOLO las notificaciones que apuntan a mis_solicitudes.php
$stmt = $conn->prepare("UPDATE notificaciones SET leido = 1 WHERE user_id = ? AND link = 'admin_solicitudes.php'");
$stmt->execute([$_SESSION['user_id']]);


$mensaje = '';

// Diccionario de traducciones
$traducciones = [
    'estado'    => 'Estado',
    'cantidad'  => 'Cantidad',
    'producto'  => 'Producto',
    'stock'     => 'Stock'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_id'], $_POST['estado'])) {
    $solicitudId = (int)$_POST['solicitud_id'];
    $nuevoEstado = $_POST['estado'];

    // Obtener datos de la solicitud antes de actualizar
    $stmt = $conn->prepare("SELECT * FROM solicitudes WHERE id = ?");
    $stmt->execute([$solicitudId]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($solicitud) {
        $estadoAnterior = $solicitud['estado'];

        // Obtener stock actual del producto
        $stmt = $conn->prepare("SELECT quantity, description FROM products WHERE id = ?");
        $stmt->execute([$solicitud['product_id']]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        $stockAnterior = $producto ? $producto['quantity'] : 0;
        $stockNuevo = $stockAnterior;

        // Actualizar estado de la solicitud
        $stmt = $conn->prepare("UPDATE solicitudes SET estado = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$nuevoEstado, $solicitudId]);

        if ($nuevoEstado === "Entregado" && $producto) {
            // Calcular nuevo stock
            $stockNuevo = $stockAnterior - $solicitud['cantidad'];
            $stmt = $conn->prepare("UPDATE products SET quantity = ? WHERE id = ?");
            $stmt->execute([$stockNuevo, $solicitud['product_id']]);

            // Log de entrega
            $log = $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at) 
                                   VALUES (?, 'request_delivered', ?, ?, ?, NOW())");
            $log->execute([
                $_SESSION['user_id'],
                "Se entregó la solicitud ID {$solicitudId} del producto '{$producto['description']}' ({$traducciones['cantidad']}: {$solicitud['cantidad']})",
                $stockAnterior,
                $stockNuevo
            ]);

            // Notificación al usuario
            $notif = $conn->prepare("INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at) 
                                     VALUES (?, ?, ?, 0, NOW())");
            $notif->execute([
                $solicitud['user_id'],
                "✅ Tu solicitud ID {$solicitudId} del producto '{$producto['description']}' fue ENTREGADA ({$solicitud['cantidad']} unidades).",
                "mis_solicitudes.php"
            ]);

        } else {
            // Log de cambio de estado
            $log = $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at) 
                                   VALUES (?, 'request_status', ?, ?, ?, NOW())");
            $log->execute([
                $_SESSION['user_id'],
                "Admin cambió el {$traducciones['estado']} de la solicitud ID {$solicitudId}",
                $estadoAnterior,
                $nuevoEstado
            ]);

            // Notificación al usuario
            $notif = $conn->prepare("INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at) 
                                     VALUES (?, ?, ?, 0, NOW())");
            $notif->execute([
                $solicitud['user_id'],
                "📢 El estado de tu solicitud ID {$solicitudId} ha cambiado: {$estadoAnterior} → {$nuevoEstado}.",
                "mis_solicitudes.php"
            ]);
        }

        $mensaje = "Estado de solicitud actualizado.";
    }
}

$stmt = $conn->query("SELECT s.*, u.username, p.description 
                      FROM solicitudes s
                      LEFT JOIN users u ON s.user_id = u.id
                      LEFT JOIN products p ON s.product_id = p.id
                      ORDER BY s.created_at DESC");
$solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Administrar Solicitudes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>
        <div>
            <span class="me-3 text-white">Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?> / (<?= $_SESSION['role'] ?>)</span>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver</a>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <h3>Administración de Solicitudes</h3>
    <?php if ($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>

    <?php if (empty($solicitudes)): ?>
        <div class="alert alert-info">No hay solicitudes registradas.</div>
    <?php else: ?>

        <!-- Tabs de filtro -->
        <ul class="nav nav-tabs mb-3" id="filtroTabs">
            <li class="nav-item">
                <button class="nav-link active" data-filter="todas">Todas</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-filter="activas">Activas</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-filter="cerradas">Cerradas</button>
            </li>
        </ul>

        <table class="table table-bordered table-striped" id="tablaSolicitudes">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Usuario</th>
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th>Ticket</th>
                    <th>Detalle</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($solicitudes as $s): 
                    $estado = $s['estado'];
                    $tipo = in_array($estado, ["Entregado","Rechazada"]) ? "cerradas" : "activas";
                ?>
                <tr data-tipo="<?= $tipo ?>">
                    <td><?= $s['id'] ?></td>
                    <td><?= htmlspecialchars($s['username']) ?></td>
                    <td><?= htmlspecialchars($s['description']) ?></td>
                    <td><?= $s['cantidad'] ?></td>
                    <td><?= htmlspecialchars($s['ticket']) ?></td>
                    <td><?= htmlspecialchars($s['detalle']) ?></td>
                    <td>
                        <form method="post" class="d-flex gap-2">
                            <input type="hidden" name="solicitud_id" value="<?= $s['id'] ?>">
                            <select name="estado" class="form-select form-select-sm">
                                <?php
                                $estados = ["Pendiente","Aprobada","Rechazada","En Bodega","En Curso","Entregado"];
                                foreach ($estados as $estadoOpt): ?>
                                    <option value="<?= $estadoOpt ?>" <?= $s['estado'] === $estadoOpt ? "selected" : "" ?>>
                                        <?= $estadoOpt ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                    </td>
                    <td>
                            <button type="submit" class="btn btn-sm btn-primary">Actualizar</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Volver al Dashboard</a>
</div>

<script>
    document.querySelectorAll('#filtroTabs button').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('#filtroTabs .nav-link').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            const filtro = this.getAttribute('data-filter');
            document.querySelectorAll('#tablaSolicitudes tbody tr').forEach(row => {
                if (filtro === "todas") {
                    row.style.display = "";
                } else {
                    row.style.display = row.getAttribute('data-tipo') === filtro ? "" : "none";
                }
            });
        });
    });
</script>

</body>
</html>
