<?php
session_start();
if (!isset($_SESSION['user'])) { 
    header("Location: index.php"); 
    exit; 
}
require 'config/db.php';

// ✅ Marcar como leídas las notificaciones de esta vista
$stmt = $conn->prepare("UPDATE notificaciones SET leido = 1 WHERE user_id = ? AND link = 'mis_solicitudes.php'");
$stmt->execute([$_SESSION['user_id']]);

$userId  = $_SESSION['user_id'];
$mensaje = '';
$divisionName = $_SESSION['division_name'] ?? null;

// 🔔 Cargar notificaciones (para la campanita)
$notiStmt = $conn->prepare("SELECT id, mensaje, link, leido, created_at 
                            FROM notificaciones 
                            WHERE user_id = ? 
                            ORDER BY created_at DESC 
                            LIMIT 5");
$notiStmt->execute([$userId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count(array_filter($notificaciones, fn($n) => (int)($n['leido'] ?? 0) === 0));

// 📝 Actualizaciones del usuario sobre sus solicitudes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_id'])) {
    $solicitudId = (int)$_POST['solicitud_id'];
    $ticket      = trim($_POST['ticket'] ?? "0");
    $detalle     = trim($_POST['detalle'] ?? "");

    // Valores actuales
    $stmt = $conn->prepare("SELECT ticket, detalle, estado FROM solicitudes WHERE id = ? AND user_id = ?");
    $stmt->execute([$solicitudId, $userId]);
    $solicitudActual = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($solicitudActual && !in_array($solicitudActual['estado'], ["Entregado","Rechazada"])) {
        $cambios = [];

        if ($solicitudActual['ticket'] !== $ticket) {
            $cambios[] = ['campo'=>'Ticket','old'=>$solicitudActual['ticket'],'new'=>$ticket];
        }
        if ($solicitudActual['detalle'] !== $detalle) {
            $cambios[] = ['campo'=>'Detalle','old'=>$solicitudActual['detalle'],'new'=>$detalle];
        }

        // Actualizar
        $stmt = $conn->prepare("UPDATE solicitudes 
                                SET ticket = ?, detalle = ?, updated_at = NOW() 
                                WHERE id = ? AND user_id = ?");
        $stmt->execute([$ticket, $detalle, $solicitudId, $userId]);

        // Log + notificar admins
        if (!empty($cambios)) {
            foreach ($cambios as $c) {
                // Log
                $log = $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at) 
                                       VALUES (?, 'request_updated', ?, ?, ?, NOW())");
                $log->execute([
                    $userId,
                    "Usuario actualizó la solicitud ID {$solicitudId} - Campo {$c['campo']}",
                    $c['old'],
                    $c['new']
                ]);

                // Notificar admins
                $admins = $conn->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($admins as $admin) {
                    $notif = $conn->prepare("INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at) 
                                             VALUES (?, ?, ?, 0, NOW())");
                    $notif->execute([
                        $admin['id'],
                        "📌 El usuario {$_SESSION['user']} modificó su solicitud ID {$solicitudId}: {$c['campo']} ({$c['old']} → {$c['new']})",
                        "admin_solicitudes.php"
                    ]);
                }
            }
        }

        $mensaje = "Solicitud actualizada.";
    }
}

// 📦 Cargar mis solicitudes
$stmt = $conn->prepare("SELECT s.*, p.description 
                        FROM solicitudes s
                        LEFT JOIN products p ON s.product_id = p.id
                        WHERE s.user_id = ?
                        ORDER BY s.created_at DESC");
$stmt->execute([$userId]);
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
    <title>Mis Solicitudes</title>
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">

  <!-- Bootstrap Icons local -->
  <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
    <style>.noti-dropdown{max-height:300px;overflow-y:auto}</style>
</head>
<body class="bg-light">

<!-- NAV igual al dashboard (con campanita) -->
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>

        <div class="d-flex align-items-center">
            <span class="me-3 text-white">
                Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?>
                / (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                <?php if ($divisionName): ?>
                    <span class="badge text-bg-secondary ms-2"><?= htmlspecialchars($divisionName) ?></span>
                <?php endif; ?>
            </span>

            <!-- 🔔 Notificaciones -->
            <div class="dropdown me-3">
                <button class="btn btn-outline-light position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    🔔
                    <?php if ($notiCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $notiCount ?></span>
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
    </div>
</nav>

<div class="container mt-5">
    <h3>Mis Solicitudes</h3>
    <?php if ($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>

    <?php if (empty($solicitudes)): ?>
        <div class="alert alert-warning">No tienes solicitudes registradas.</div>
    <?php else: ?>
        <!-- Filtros -->
        <ul class="nav nav-tabs mb-3" id="filtroTabs">
            <li class="nav-item"><button class="nav-link active" data-filter="todas">Todas</button></li>
            <li class="nav-item"><button class="nav-link" data-filter="activas">Activas</button></li>
            <li class="nav-item"><button class="nav-link" data-filter="cerradas">Cerradas</button></li>
        </ul>

        <table class="table table-bordered table-striped align-middle" id="tablaSolicitudes">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th>Ticket</th>
                    <th>Detalle</th>
                    <th>Estado</th>
                    <th style="width:120px">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($solicitudes as $s): 
                    $estado = $s['estado'];
                    $tipo = in_array($estado, ["Entregado","Rechazada"]) ? "cerradas" : "activas";
                    $cerrada = in_array($estado, ["Entregado","Rechazada"]);
                ?>
                <tr data-tipo="<?= $tipo ?>">
                    <td><?= $s['id'] ?></td>
                    <td><?= htmlspecialchars($s['description']) ?></td>
                    <td><?= $s['cantidad'] ?></td>

                    <!-- Ticket -->
                    <td>
                        <form method="post" class="mb-0 d-flex flex-column gap-2">
                            <input type="hidden" name="solicitud_id" value="<?= $s['id'] ?>">
                            <input type="text" name="ticket" value="<?= htmlspecialchars($s['ticket']) ?>" 
                                   class="form-control form-control-sm" placeholder="Ticket" <?= $cerrada ? 'disabled' : '' ?>>
                    </td>

                    <!-- Detalle -->
                    <td>
                            <textarea name="detalle" rows="2" class="form-control form-control-sm" 
                                      <?= $cerrada ? 'disabled' : '' ?>><?= htmlspecialchars($s['detalle']) ?></textarea>
                    </td>

                    <!-- Estado -->
                    <td>
                        <?php
                        $estados = [
                            "Pendiente" => "secondary",
                            "Aprobada"  => "primary",
                            "Rechazada" => "danger",
                            "En Bodega" => "warning",
                            "En Curso"  => "info",
                            "Entregado" => "success"
                        ];
                        $color = $estados[$estado] ?? "dark";
                        ?>
                        <span class="badge bg-<?= $color ?>"><?= htmlspecialchars($estado) ?></span>
                    </td>

                    <!-- Botón -->
                    <td>
                            <button type="submit" class="btn btn-sm btn-primary w-100" <?= $cerrada ? 'disabled' : '' ?>>Actualizar</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <a href="dashboard.php" class="btn btn-secondary">Volver</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
