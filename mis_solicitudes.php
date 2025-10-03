<?php
session_start();
if (!isset($_SESSION['user'])) { 
    header("Location: index.php"); 
    exit; 
}
require 'config/db.php';

// 📌 Marcar como leídas SOLO las notificaciones que apuntan a mis_solicitudes.php
$stmt = $conn->prepare("UPDATE notificaciones SET leido = 1 WHERE user_id = ? AND link = 'mis_solicitudes.php'");
$stmt->execute([$_SESSION['user_id']]);

$userId = $_SESSION['user_id'];
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_id'])) {
    $solicitudId = (int)$_POST['solicitud_id'];
    $ticket = trim($_POST['ticket'] ?? "0");
    $detalle = trim($_POST['detalle'] ?? "");

    // Obtener valores actuales antes de actualizar
    $stmt = $conn->prepare("SELECT ticket, detalle, estado FROM solicitudes WHERE id = ? AND user_id = ?");
    $stmt->execute([$solicitudId, $userId]);
    $solicitudActual = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($solicitudActual && !in_array($solicitudActual['estado'], ["Entregado","Rechazada"])) {
        $cambios = [];

        // Verificar cambios
        if ($solicitudActual['ticket'] != $ticket) {
            $cambios[] = [
                'campo' => 'Ticket',
                'old'   => $solicitudActual['ticket'],
                'new'   => $ticket
            ];
        }
        if ($solicitudActual['detalle'] != $detalle) {
            $cambios[] = [
                'campo' => 'Detalle',
                'old'   => $solicitudActual['detalle'],
                'new'   => $detalle
            ];
        }

        // Actualizar solicitud
        $stmt = $conn->prepare("UPDATE solicitudes SET ticket = ?, detalle = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$ticket, $detalle, $solicitudId, $userId]);

        // Registrar en logs y notificar admins
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

                // Notificar a todos los administradores
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

$stmt = $conn->prepare("SELECT s.*, p.description 
                        FROM solicitudes s
                        LEFT JOIN products p ON s.product_id = p.id
                        WHERE s.user_id = ?
                        ORDER BY s.created_at DESC");
$stmt->execute([$userId]);
$solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mis Solicitudes</title>
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
        <h3>Mis Solicitudes</h3>
        <?php if ($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>

        <?php if (empty($solicitudes)): ?>
            <div class="alert alert-warning">No tienes solicitudes registradas.</div>
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

            <table class="table table-bordered table-striped align-middle" id="tablaSolicitudes">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
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
                                <input type="text" name="ticket" value="<?= htmlspecialchars($s['ticket']) ?>" class="form-control form-control-sm" placeholder="Ticket" <?= $cerrada ? 'disabled' : '' ?>>
                        </td>

                        <!-- Detalle -->
                        <td>
                                <textarea name="detalle" class="form-control form-control-sm" <?= $cerrada ? 'disabled' : '' ?>><?= htmlspecialchars($s['detalle']) ?></textarea>
                        </td>

                        <!-- Estado -->
                        <td>
                            <?php
                            $estados = [
                                "Pendiente" => "secondary",
                                "Aprobada" => "primary",
                                "Rechazada" => "danger",
                                "En Bodega" => "warning",
                                "En Curso" => "info",
                                "Entregado" => "success"
                            ];
                            $color = $estados[$estado] ?? "dark";
                            ?>
                            <span class="badge bg-<?= $color ?>"><?= $estado ?></span>
                        </td>

                        <!-- Botón de acción -->
                        <td>
                                <button type="submit" class="btn btn-sm btn-primary w-100" <?= $cerrada ? 'disabled' : '' ?>>Actualizar</button>
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
