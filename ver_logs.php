<?php
session_start();
if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}

require 'config/db.php';

/* Helpers: detectar columnas */
function columnExists(PDO $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $st = $conn->prepare($sql);
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

$adminId = (int)($_SESSION['user_id'] ?? 0);
$mensaje = '';
$areaUsuario  = $_SESSION['area'] ?? '';
$divisionName = $_SESSION['division_name'] ?? null;

/* Detectar soporte de divisiones en users */
$hasUserDivisionId   = columnExists($conn, 'users', 'division_id');
$hasUserDivisionName = columnExists($conn, 'users', 'division_name');
$hasDivisionesTable  = true;
try {
    $conn->query("SELECT 1 FROM divisiones LIMIT 1");
} catch (Throwable $e) {
    $hasDivisionesTable = false;
}

/* Acciones unificadas y etiquetas legibles */
$accionesMap = [
    'product_created'     => 'Creación de producto',
    'product_modified'    => 'Modificación de producto',      // unifica: stock_update, edit_product, item_status
    'product_deleted'     => 'Eliminación de producto',
    'product_incremented' => 'Incremento por escaneo',

    'solicitud_creada'    => 'Solicitud creada',
    'solicitud_estado'    => 'Cambio de estado en solicitud/ítem',
    'entrega_registrada'  => 'Entrega registrada',

    'user_registered'     => 'Nuevo usuario registrado',
    'user_updated'        => 'Usuario actualizado',
    'user_deleted'        => 'Usuario eliminado',

    'bodega_created'      => 'Bodega creada',
    'bodega_updated'      => 'Bodega actualizada',
    'bodega_deleted'      => 'Bodega eliminada',

    'division_created'    => 'División creada',
    'division_updated'    => 'División actualizada',
    'division_deleted'    => 'División eliminada',
];

/* Filtros */
$filtroAccion   = $_GET['filtro']   ?? 'Todas';
$filtroUsuario  = $_GET['usuario']  ?? 'Todos';
$filtroDivision = $_GET['division'] ?? 'Todas';

/* Construir query con filtros */
$selectDivision = "NULL AS division_label";
$joinDivision   = "";

if ($hasUserDivisionId && $hasDivisionesTable) {
    $selectDivision = "d.nombre AS division_label";
    $joinDivision   = "LEFT JOIN divisiones d ON d.id = u.division_id";
} elseif ($hasUserDivisionName) {
    $selectDivision = "u.division_name AS division_label";
}

$query = "
    SELECT
        l.id, l.action, l.details, l.old_value, l.new_value, l.created_at,
        u.username,
        $selectDivision
    FROM logs l
    LEFT JOIN users u ON l.user_id = u.id
    $joinDivision
    WHERE 1=1
";
$params = [];

/* Filtro por acción */
if ($filtroAccion !== 'Todas') {
    $query .= " AND l.action = ?";
    $params[] = $filtroAccion;
}

/* Filtro por usuario */
if ($filtroUsuario !== 'Todos') {
    $query .= " AND u.username = ?";
    $params[] = $filtroUsuario;
}

/* Filtro por división */
if ($filtroDivision !== 'Todas') {
    if ($hasUserDivisionId && $hasDivisionesTable) {
        $query .= " AND d.nombre = ?";
        $params[] = $filtroDivision;
    } elseif ($hasUserDivisionName) {
        $query .= " AND u.division_name = ?";
        $params[] = $filtroDivision;
    }
}

$query .= " ORDER BY l.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Listas para selects */
$accionesUnicas = array_keys($accionesMap);
$usuariosUnicos = $conn->query("SELECT DISTINCT username FROM users WHERE username IS NOT NULL AND username <> '' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
$divisionesUnicas = [];
if ($hasUserDivisionId && $hasDivisionesTable) {
    $divisionesUnicas = $conn->query("SELECT nombre FROM divisiones ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
} elseif ($hasUserDivisionName) {
    $divisionesUnicas = $conn->query("SELECT DISTINCT division_name FROM users WHERE division_name IS NOT NULL AND division_name <> '' ORDER BY division_name")->fetchAll(PDO::FETCH_COLUMN);
}
$divisionesUnicas = array_unique(array_filter($divisionesUnicas ?? []));

/* Datos navbar (notificaciones) */
$userIdSess = (int)($_SESSION['user_id'] ?? 0);
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$userIdSess]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registro de Actividades - Bodega</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
  <link rel="stylesheet" href="assets/datatables/css/dataTables.bootstrap5.min.css">
  <style>
    .noti-dropdown { max-height: 300px; overflow-y: auto; }
  </style>
</head>
<body class="bg-light">

<!-- Navbar -->
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
  <div class="container-fluid d-flex justify-content-between align-items-center">
    <img src="assets/logo.png" alt="Sonda Logo" height="120">
    <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega</span>
    <div class="d-flex align-items-center">
      <span class="me-3 text-white">
        <?= htmlspecialchars($_SESSION['user']) ?>
        / (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
        <?php if ($divisionName): ?>
          <span class="badge text-bg-secondary ms-2"><?= htmlspecialchars($divisionName) ?></span>
        <?php endif; ?>
      </span>

      <div class="dropdown me-2">
        <button class="btn btn-outline-light btn-sm position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown">
          🔔
          <?php if ($notiCount > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $notiCount ?></span>
          <?php endif; ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end noti-dropdown">
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
          <li><a class="dropdown-item text-center" href="ver_notificaciones.php">📜 Ver todas</a></li>
        </ul>
      </div>

      <a href="dashboard.php" class="btn btn-outline-light btn-sm me-1">🏠</a>
      <a href="logout.php" class="btn btn-outline-light btn-sm">⏻</a>
    </div>
  </div>
</nav>

<div class="container mt-5">
  <h3 class="mb-4">Historial de Actividades</h3>

  <!-- Filtros -->
  <form method="get" class="row g-3 mb-4">
    <div class="col-md-4">
      <label class="form-label">Acción</label>
      <select name="filtro" class="form-select" onchange="this.form.submit()">
        <option value="Todas">Todas</option>
        <?php foreach ($accionesUnicas as $codigo): ?>
          <option value="<?= htmlspecialchars($codigo) ?>" <?= $filtroAccion === $codigo ? 'selected' : '' ?>>
            <?= htmlspecialchars($accionesMap[$codigo] ?? $codigo) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label">Usuario</label>
      <select name="usuario" class="form-select" onchange="this.form.submit()">
        <option value="Todos">Todos</option>
        <?php foreach ($usuariosUnicos as $u): ?>
          <option value="<?= htmlspecialchars($u) ?>" <?= $filtroUsuario === $u ? 'selected' : '' ?>>
            <?= htmlspecialchars($u) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label">División</label>
      <select name="division" class="form-select" onchange="this.form.submit()" <?= empty($divisionesUnicas) ? 'disabled' : '' ?>>
        <option value="Todas">Todas</option>
        <?php foreach ($divisionesUnicas as $d): ?>
          <option value="<?= htmlspecialchars($d) ?>" <?= $filtroDivision === $d ? 'selected' : '' ?>>
            <?= htmlspecialchars($d) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (empty($divisionesUnicas)): ?>
        <small class="text-muted d-block mt-1">No hay divisiones registradas</small>
      <?php endif; ?>
    </div>
  </form>

  <?php if (empty($logs)): ?>
    <div class="alert alert-info">No hay registros de actividades.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table id="logsTable" class="table table-bordered table-striped table-hover">
        <thead class="table-dark">
          <tr>
            <th>ID</th>
            <th>Usuario</th>
            <th>División</th>
            <th>Acción</th>
            <th>Detalles</th>
            <th>Valor anterior</th>
            <th>Valor nuevo</th>
            <th>Fecha</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $row): 
            $accionLabel = $accionesMap[$row['action']] ?? $row['action'];
            $detalles = $row['details'] ? htmlspecialchars($row['details']) : '—';
            $old = $row['old_value'] ? htmlspecialchars($row['old_value']) : '—';
            $new = $row['new_value'] ? htmlspecialchars($row['new_value']) : '—';
          ?>
            <tr>
              <td><?= (int)$row['id'] ?></td>
              <td><?= htmlspecialchars($row['username'] ?? '(eliminado)') ?></td>
              <td><?= htmlspecialchars($row['division_label'] ?? '—') ?></td>
              <td><span class="badge bg-secondary"><?= htmlspecialchars($accionLabel) ?></span></td>
              <td><?= $detalles ?></td>
              <td><?= $old ?></td>
              <td><?= $new ?></td>
              <td><?= htmlspecialchars($row['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <a href="dashboard.php" class="btn btn-secondary mt-4">Volver al panel</a>
</div>

<script src="assets/jquery/jquery-3.7.1.min.js"></script>
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/datatables/js/jquery.dataTables.min.js"></script>
<script src="assets/datatables/js/dataTables.bootstrap5.min.js"></script>
<script>
  $(document).ready(function() {
    $('#logsTable').DataTable({
      pageLength: 15,
      order: [[7, 'desc']], // orden por fecha descendente
      language: { url: 'assets/datatables/i18n/es-ES.json' }
    });
  });
</script>
</body>
</html>