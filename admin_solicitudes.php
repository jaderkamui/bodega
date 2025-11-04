<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require 'config/db.php';

// 📌 Marcar notificaciones de este módulo como leídas
$stmt = $conn->prepare("UPDATE notificaciones SET leido = 1 WHERE user_id = ? AND link = 'admin_solicitudes.php'");
$stmt->execute([$_SESSION['user_id']]);

// 🔔 Obtener notificaciones no leídas
$stmt = $conn->prepare("SELECT id, mensaje, link, created_at FROM notificaciones WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$_SESSION['user_id']]);
$notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count(array_filter($notificaciones, fn($n) => !isset($n['leido']) || $n['leido'] == 0));

$mensaje = '';

// Diccionario de traducciones
$traducciones = [
    'estado'    => 'Estado',
    'cantidad'  => 'Cantidad',
    'producto'  => 'Producto',
    'stock'     => 'Stock'
];

// 📦 Actualizar estado de solicitud
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_id'], $_POST['estado'])) {
    $solicitudId = (int)$_POST['solicitud_id'];
    $nuevoEstado = $_POST['estado'];

    $stmt = $conn->prepare("SELECT * FROM solicitudes WHERE id = ?");
    $stmt->execute([$solicitudId]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($solicitud) {
        $estadoAnterior = $solicitud['estado'];

        $stmt = $conn->prepare("SELECT quantity, description FROM products WHERE id = ?");
        $stmt->execute([$solicitud['product_id']]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        $stockAnterior = $producto ? $producto['quantity'] : 0;
        $stockNuevo = $stockAnterior;

        $stmt = $conn->prepare("UPDATE solicitudes SET estado = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$nuevoEstado, $solicitudId]);

        if ($nuevoEstado === "Entregado" && $producto) {
            $stockNuevo = $stockAnterior - $solicitud['cantidad'];
            $stmt = $conn->prepare("UPDATE products SET quantity = ? WHERE id = ?");
            $stmt->execute([$stockNuevo, $solicitud['product_id']]);

            $log = $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at) 
                                   VALUES (?, 'request_delivered', ?, ?, ?, NOW())");
            $log->execute([
                $_SESSION['user_id'],
                "Se entregó la solicitud ID {$solicitudId} del producto '{$producto['description']}' ({$traducciones['cantidad']}: {$solicitud['cantidad']})",
                $stockAnterior,
                $stockNuevo
            ]);

            $notif = $conn->prepare("INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at) 
                                     VALUES (?, ?, ?, 0, NOW())");
            $notif->execute([
                $solicitud['user_id'],
                "✅ Tu solicitud ID {$solicitudId} del producto '{$producto['description']}' fue ENTREGADA ({$solicitud['cantidad']} unidades).",
                "mis_solicitudes.php"
            ]);

        } else {
            $log = $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at) 
                                   VALUES (?, 'request_status', ?, ?, ?, NOW())");
            $log->execute([
                $_SESSION['user_id'],
                "Admin cambió el {$traducciones['estado']} de la solicitud ID {$solicitudId}",
                $estadoAnterior,
                $nuevoEstado
            ]);

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

// 📋 Consultar solicitudes
$stmt = $conn->query("SELECT s.*, u.username, p.description 
                      FROM solicitudes s
                      LEFT JOIN users u ON s.user_id = u.id
                      LEFT JOIN products p ON s.product_id = p.id
                      ORDER BY s.created_at DESC");
$solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Notificaciones navbar
$userIdSess = (int)$_SESSION['user_id'];
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$userIdSess]); $notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);

$areaUsuario  = $_SESSION['area'] ?? '';
$divisionName = $_SESSION['division_name'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Administrar Solicitudes</title>
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">

  <!-- Bootstrap Icons local -->
  <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
</head>
<body class="bg-light">

<!-- 🔹 NAVBAR con notificaciones -->
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>

        <div class="d-flex align-items-center">
            <span class="me-3 text-white">
                Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?>
                / (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                <?php if ($divisionName): ?><span class="badge text-bg-secondary ms-2"><?= htmlspecialchars($divisionName) ?></span><?php endif; ?>
            </span>

            <!-- 🔔 Notificaciones -->
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

<!-- 🔹 CUERPO -->
<div class="container mt-5">
    <h3>Administración de Solicitudes</h3>
    <?php if ($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>

    <?php if (empty($solicitudes)): ?>
        <div class="alert alert-info">No hay solicitudes registradas.</div>
    <?php else: ?>
        <ul class="nav nav-tabs mb-3" id="filtroTabs">
            <li class="nav-item"><button class="nav-link active" data-filter="todas">Todas</button></li>
            <li class="nav-item"><button class="nav-link" data-filter="activas">Activas</button></li>
            <li class="nav-item"><button class="nav-link" data-filter="cerradas">Cerradas</button></li>
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
                    $tipo = in_array($s['estado'], ["Entregado", "Rechazada"]) ? "cerradas" : "activas";
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
      <a href="dashboard.php" class="btn btn-secondary mt-3">Volver</a>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('#filtroTabs button').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('#filtroTabs .nav-link').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const filtro = this.getAttribute('data-filter');
        document.querySelectorAll('#tablaSolicitudes tbody tr').forEach(row => {
            if (filtro === "todas") row.style.display = "";
            else row.style.display = row.getAttribute('data-tipo') === filtro ? "" : "none";
        });
    });
});
</script>

</body>
</html>
