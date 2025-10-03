<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

require 'config/db.php';

/* 1) Mapeo de códigos → etiquetas legibles */
$accionesMap = [
    'create_product'    => 'Crear producto',
    'edit_product'      => 'Actualizar producto',
    'delete_product'    => 'Eliminar producto',
    'scan_increment'    => 'Escaneo (+1)',
    'request_created'   => 'Solicitud creada',
    'request_updated'   => 'Solicitud actualizada',
    'request_status'    => 'Cambio de estado de solicitud',
    'request_delivered' => 'Entrega de producto',
    'user_updated'      => 'Usuario actualizado',
    'user_deleted'      => 'Usuario eliminado',
    'user_registered'   => 'Nuevo usuario',
];

/* 2) Patrones legacy */
$legacyPatterns = [
    'create_product'    => 'Agregó un producto%',
    'edit_product'      => 'Actualizó un producto%',
    'delete_product'    => 'Eliminó el producto%',
    'scan_increment'    => 'Escaneo +1%',
    'request_created'   => 'Creó solicitud%',
    'request_updated'   => 'Actualizó solicitud%',
    'request_status'    => 'Cambio de estado de solicitud%',
    'request_delivered' => 'Entregó producto%',
    'user_updated'      => 'Actualizó al usuario%',
    'user_deleted'      => 'Eliminó al usuario%',
    'user_registered'   => 'Registró al usuario%',
];

/* 3) Filtros seleccionados */
$filtroAccion = $_GET['filtro'] ?? 'Todas';
$filtroUsuario = $_GET['usuario'] ?? 'Todos';

/* 4) Construir consulta */
$query = "
    SELECT logs.id, logs.action, logs.details, logs.old_value, logs.new_value, logs.created_at, users.username
    FROM logs
    LEFT JOIN users ON logs.user_id = users.id
    WHERE 1=1
";
$params = [];

/* filtro por acción */
if ($filtroAccion && $filtroAccion !== 'Todas') {
    if (isset($legacyPatterns[$filtroAccion])) {
        $query .= " AND (logs.action = ? OR logs.action LIKE ?)";
        $params[] = $filtroAccion;
        $params[] = $legacyPatterns[$filtroAccion];
    } else {
        $query .= " AND logs.action = ?";
        $params[] = $filtroAccion;
    }
}

/* filtro por usuario */
if ($filtroUsuario && $filtroUsuario !== 'Todos') {
    $query .= " AND users.username = ?";
    $params[] = $filtroUsuario;
}

$query .= " ORDER BY logs.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* 5) Lista para selectores */
$accionesUnicas = array_keys($accionesMap);
$usuariosUnicos = $conn->query("SELECT DISTINCT username FROM users ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);

function presentarAccionYDetalle(array $row, array $map, array $patterns): array {
    $action  = $row['action'];
    $details = $row['details'] ?? '';

    if (isset($map[$action])) {
        $label = $map[$action];
        $det   = $details !== '' ? $details : '-';
        return [$label, $det];
    }

    foreach ($patterns as $code => $like) {
        $prefix = rtrim($like, '%');
        if (stripos($action, $prefix) === 0) {
            $label = $map[$code] ?? $code;
            $det   = $details !== '' ? $details : $action;
            return [$label, $det];
        }
    }

    return [$action, ($details !== '' ? $details : '-')];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Logs del Sistema</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
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
        <h3 class="mb-4">Historial de Actividades</h3>

        <!-- Filtros -->
        <form method="get" class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label">Filtrar por acción:</label>
                <select name="filtro" onchange="this.form.submit()" class="form-select">
                    <option value="Todas" <?= $filtroAccion === 'Todas' ? 'selected' : '' ?>>Todas</option>
                    <?php foreach ($accionesUnicas as $codigo): ?>
                        <option value="<?= htmlspecialchars($codigo) ?>" <?= $filtroAccion === $codigo ? 'selected' : '' ?>>
                            <?= htmlspecialchars($accionesMap[$codigo]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Filtrar por usuario:</label>
                <select name="usuario" onchange="this.form.submit()" class="form-select">
                    <option value="Todos" <?= $filtroUsuario === 'Todos' ? 'selected' : '' ?>>Todos</option>
                    <?php foreach ($usuariosUnicos as $u): ?>
                        <option value="<?= htmlspecialchars($u) ?>" <?= $filtroUsuario === $u ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if (empty($logs)): ?>
            <div class="alert alert-info">No hay actividades registradas.</div>
        <?php else: ?>
            <table id="logs" class="table table-bordered table-striped align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Acción</th>
                        <th>Detalles</th>
                        <th>Valor anterior</th>
                        <th>Valor nuevo</th>
                        <th>Fecha y hora</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): 
                        [$accionLabel, $det] = presentarAccionYDetalle($log, $accionesMap, $legacyPatterns);
                    ?>
                        <tr>
                            <td><?= (int)$log['id'] ?></td>
                            <td><?= $log['username'] !== null ? htmlspecialchars($log['username']) : '(usuario eliminado)' ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($accionLabel) ?></span></td>
                            <td><?= htmlspecialchars($det) ?></td>
                            <td><?= $log['old_value'] !== null ? htmlspecialchars($log['old_value']) : '-' ?></td>
                            <td><?= $log['new_value'] !== null ? htmlspecialchars($log['new_value']) : '-' ?></td>
                            <td><?= htmlspecialchars($log['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <a href="dashboard.php" class="btn btn-secondary mt-3">← Volver</a>
    </div>

    

    <!-- DataTables -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.0/dist/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function () {
            $('#logs').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/Spanish.json' },
                pageLength: 10,
                order: [[0, "desc"]]
            });
        });
    </script>
</body>
</html>
