<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

require 'config/db.php';

/* ===== 1) Mapeo de acciones → etiquetas legibles ===== */
$accionesMap = [
    'create_product'        => 'Crear producto',
    'edit_product'          => 'Actualizar producto',
    'delete_product'        => 'Eliminar producto',
    'scan_increment'        => 'Escaneo (+1)',
    'request_created'       => 'Solicitud creada',
    'request_updated'       => 'Solicitud actualizada',
    'request_status'        => 'Cambio de estado de solicitud',
    'request_delivered'     => 'Entrega de producto',
    'user_deleted'          => 'Usuario eliminado',
    'user_registered'       => 'Nuevo usuario',
    // 👇 Nuevos eventos
    'user_bodegas_updated'  => 'Bodegas actualizadas',
    'bodega_updated'        => 'Bodega actualizada',
    'bodega_created'        => 'Bodega creada',
    'user_updated_role'     => 'Rol actualizado',
    'user_row_updated'      => 'Usuario actualizado',
    'bodega_deleted'        => 'Bodega eliminada',
    
];

/* ===== 2) Patrones legacy (si en “action” venían frases) ===== */
$legacyPatterns = [
    'create_product'        => 'Agregó un producto%',
    'edit_product'          => 'Actualizó un producto%',
    'delete_product'        => 'Eliminó el producto%',
    'scan_increment'        => 'Escaneo +1%',
    'request_created'       => 'Creó solicitud%',
    'request_updated'       => 'Actualizó solicitud%',
    'request_status'        => 'Cambio de estado de solicitud%',
    'request_delivered'     => 'Entregó producto%',
    'user_deleted'          => 'Eliminó al usuario%',
    'user_registered'       => 'Registró al usuario%',
    // 👇 por si algún legacy escribió textos similares
    'user_bodegas_updated'  => 'Actualizó bodegas %',
    'user_updated_role'     => 'Actualizó rol %',
    'user_row_updated'      => 'Actualizó usuario%',
    'bodega_updated'        => 'Actualizo Bodega',
    'bodega_created'        => 'Creo Bodega',
    'bodega_deleted'        => 'Elimino Bodega',
];

/* ===== 3) Filtros ===== */
$filtroAccion  = $_GET['filtro']  ?? 'Todas';
$filtroUsuario = $_GET['usuario'] ?? 'Todos';

/* ===== 4) Query con filtros ===== */
$query = "
  SELECT l.id, l.action, l.details, l.old_value, l.new_value, l.created_at, u.username
  FROM logs l
  LEFT JOIN users u ON l.user_id = u.id
  WHERE 1=1
";
$params = [];

/* Acción */
if ($filtroAccion !== 'Todas') {
    if (isset($legacyPatterns[$filtroAccion])) {
        $query .= " AND (l.action = ? OR l.action LIKE ?)";
        $params[] = $filtroAccion;
        $params[] = $legacyPatterns[$filtroAccion];
    } else {
        $query .= " AND l.action = ?";
        $params[] = $filtroAccion;
    }
}

/* Usuario */
if ($filtroUsuario !== 'Todos') {
    $query .= " AND u.username = ?";
    $params[] = $filtroUsuario;
}

$query .= " ORDER BY l.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== 5) Listas para los selects ===== */
$accionesUnicas  = array_keys($accionesMap);
$usuariosUnicos  = $conn->query("SELECT DISTINCT username FROM users ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);

/* ===== 6) Helper de presentación ===== */
function presentarAccionYDetalle(array $row, array $map, array $patterns): array {
    $action  = $row['action']  ?? '';
    $details = $row['details'] ?? '';

    if (isset($map[$action])) {
        return [$map[$action], ($details !== '' ? $details : '-')];
    }
    foreach ($patterns as $code => $like) {
        $prefix = rtrim($like, '%');
        if (stripos($action, $prefix) === 0) {
            return [$map[$code] ?? $code, ($details !== '' ? $details : $action)];
        }
    }
    return [$action, ($details !== '' ? $details : '-')];
}

$areaUsuario   = $_SESSION['area'] ?? null;
$divisionName  = $_SESSION['division_name'] ?? null;
$userId   = $_SESSION['user_id'] ?? null;
$esAdmin  = ($_SESSION['role'] ?? '') === 'admin';

// Notificaciones navbar
$userIdSess = (int)$_SESSION['user_id'];
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$userIdSess]); $notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);
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

<!-- NAV coherente -->
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
            <div class="dropdown me-3">
                <button class="btn btn-outline-light position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    🔔
                    <?php if ($notiCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $notiCount ?></span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="max-height:300px;overflow-y:auto">
                    <?php if ($notiCount === 0): ?>
                        <li><span class="dropdown-item-text text-muted">No tienes notificaciones nuevas</span></li>
                    <?php else: foreach ($notificaciones as $n): ?>
                        <li>
                            <a class="dropdown-item" href="<?= htmlspecialchars($n['link']) ?>">
                                <?= htmlspecialchars($n['mensaje']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($n['created_at']) ?></small>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
            <a href="dashboard.php" class="btn btn-outline-light me-2">🏠 Dashboard</a>
            <a href="logout.php" class="btn btn-outline-light">Cerrar sesión</a>
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
        <?php foreach ($logs as $row): 
          [$accionLabel, $det] = presentarAccionYDetalle($row, $accionesMap, $legacyPatterns);
        ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td><?= $row['username'] !== null ? htmlspecialchars($row['username']) : '(usuario eliminado)' ?></td>
            <td><span class="badge bg-secondary"><?= htmlspecialchars($accionLabel) ?></span></td>
            <td><?= htmlspecialchars($det) ?></td>
            <td><?= $row['old_value'] !== null ? htmlspecialchars($row['old_value']) : '-' ?></td>
            <td><?= $row['new_value'] !== null ? htmlspecialchars($row['new_value']) : '-' ?></td>
            <td><?= htmlspecialchars($row['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <a href="dashboard.php" class="btn btn-secondary mt-3">Volver</a>
</div>

<!-- DataTables -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.0/dist/jquery.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
  $(function(){
    $('#logs').DataTable({
      language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/Spanish.json' },
      pageLength: 10,
      order: [[0, 'desc']]
    });
  });
</script>
</body>
</html>
