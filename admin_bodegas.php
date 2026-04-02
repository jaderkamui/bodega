<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require 'config/db.php';

if (!function_exists('boolVal')) {
    function boolVal($v){ return isset($v) && ($v==='1' || $v==='on' || $v===1); }
}

$mensaje = '';
$areaUsuario  = $_SESSION['area'] ?? null;
$divisionName = $_SESSION['division_name'] ?? null;

/* ===== Cargar divisiones desde la base ===== */
$divRows = $conn->query("SELECT id, nombre FROM divisiones ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$DIV_BY_ID = [];
$ID_BY_NAME = [];
foreach ($divRows as $r) {
    $DIV_BY_ID[(int)$r['id']] = $r['nombre'];
    $ID_BY_NAME[$r['nombre']] = (int)$r['id'];
}

/* Helper para validar division_id */
function validar_division_id($division_id, $DIV_BY_ID) {
    $division_id = (int)$division_id;
    return isset($DIV_BY_ID[$division_id]) ? $division_id : 0;
}

/* ===== Crear ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear'])) {
    $nombre      = trim($_POST['nombre'] ?? '');
    $abrev       = trim($_POST['abreviatura'] ?? '');
    $division_id = validar_division_id($_POST['division_id'] ?? 0, $DIV_BY_ID);
    $principal   = boolVal($_POST['is_principal'] ?? '0') ? 1 : 0;
    $activa      = boolVal($_POST['activa'] ?? '1') ? 1 : 0;

    if ($nombre !== '' && $division_id > 0) {
        try {
            $division_nombre = $DIV_BY_ID[$division_id];

            // Si se marca principal, desmarca otras principales de esa división
            if ($principal) {
                $stmt = $conn->prepare("UPDATE bodegas SET is_principal = 0 WHERE division_id = ?");
                $stmt->execute([$division_id]);
            }

            $stmt = $conn->prepare("
                INSERT INTO bodegas (nombre, abreviatura, division, division_id, is_principal, activa, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$nombre, $abrev ?: null, $division_nombre, $division_id, $principal, $activa]);

            // Log
            $log = $conn->prepare("INSERT INTO logs (user_id, action, details, created_at)
                                   VALUES (?, 'bodega_created', ?, NOW())");
            $log->execute([$_SESSION['user_id'], "Creó bodega '{$nombre}' ({$division_nombre})".($principal? ' [principal]':'' )]);

            $mensaje = '✅ Bodega creada.';
        } catch (PDOException $e) {
            $mensaje = '⚠️ Error de base de datos: '.$e->getMessage();
        }
    } else {
        $mensaje = '⚠️ Completa nombre y una división válida.';
    }
}

/* ===== Actualizar ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar'], $_POST['id'])) {
    $id          = (int)$_POST['id'];
    $nombre      = trim($_POST['nombre'] ?? '');
    $abrev       = trim($_POST['abreviatura'] ?? '');
    $division_id = validar_division_id($_POST['division_id'] ?? 0, $DIV_BY_ID);
    $principal   = boolVal($_POST['is_principal'] ?? '0') ? 1 : 0;
    $activa      = boolVal($_POST['activa'] ?? '0') ? 1 : 0;

    if ($id && $nombre !== '' && $division_id > 0) {
        try {
            $prev = $conn->prepare("SELECT * FROM bodegas WHERE id = ?");
            $prev->execute([$id]);
            $before = $prev->fetch(PDO::FETCH_ASSOC);

            if (!$before) {
                $mensaje = '⚠️ Bodega no encontrada.';
            } else {
                $division_nombre = $DIV_BY_ID[$division_id];

                if ($principal) {
                    // Solo una principal por división
                    $stmt = $conn->prepare("UPDATE bodegas SET is_principal = 0 WHERE division_id = ? AND id <> ?");
                    $stmt->execute([$division_id, $id]);
                }

                $stmt = $conn->prepare("
                    UPDATE bodegas
                    SET nombre = ?, abreviatura = ?, division = ?, division_id = ?, is_principal = ?, activa = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$nombre, $abrev ?: null, $division_nombre, $division_id, $principal, $activa, $id]);

                $after = [
                    'nombre'       => $nombre,
                    'abreviatura'  => $abrev,
                    'division'     => $division_nombre,
                    'division_id'  => $division_id,
                    'is_principal' => $principal,
                    'activa'       => $activa
                ];
                $log = $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at)
                                       VALUES (?, 'bodega_updated', ?, ?, ?, NOW())");
                $log->execute([
                    $_SESSION['user_id'],
                    "Actualizó bodega ID {$id}",
                    json_encode($before, JSON_UNESCAPED_UNICODE),
                    json_encode($after,  JSON_UNESCAPED_UNICODE)
                ]);

                $mensaje = '✅ Bodega actualizada.';
            }
        } catch (PDOException $e) {
            $mensaje = '⚠️ Error de base de datos: '.$e->getMessage();
        }
    } else {
        $mensaje = '⚠️ Completa nombre y una división válida.';
    }
}

/* ===== Eliminar (seguro) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar'], $_POST['id'])) {
    $id = (int)$_POST['id'];
    if ($id) {
        $enProductos = $conn->prepare("SELECT COUNT(*) FROM products WHERE bodega_id = ?");
        $enProductos->execute([$id]);
        $cntP = (int)$enProductos->fetchColumn();

        $enUsuarios = $conn->prepare("SELECT COUNT(*) FROM user_bodegas WHERE bodega_id = ?");
        $enUsuarios->execute([$id]);
        $cntU = (int)$enUsuarios->fetchColumn();

        if ($cntP > 0 || $cntU > 0) {
            $mensaje = "⚠️ No se puede eliminar: hay referencias (Productos: {$cntP}, Usuarios: {$cntU}). Desactiva en su lugar.";
        } else {
            $prev = $conn->prepare("SELECT * FROM bodegas WHERE id = ?");
            $prev->execute([$id]);
            $before = $prev->fetch(PDO::FETCH_ASSOC);

            $del = $conn->prepare("DELETE FROM bodegas WHERE id = ?");
            $del->execute([$id]);

            $log = $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, created_at)
                                   VALUES (?, 'bodega_deleted', ?, ?, NOW())");
            $log->execute([$_SESSION['user_id'], "Eliminó bodega ID {$id}", json_encode($before, JSON_UNESCAPED_UNICODE)]);
            $mensaje = '🗑️ Bodega eliminada.';
        }
    }
}

/* ===== Listado ===== */
$stmt = $conn->query("
    SELECT b.*, d.nombre AS division_nombre
    FROM bodegas b
    LEFT JOIN divisiones d ON d.id = b.division_id
    ORDER BY d.id, b.is_principal DESC, b.nombre ASC
");
$bodegas = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestión de Bodegas - Bodega</title>
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
    <style>
        body { font-size:14px; background-color:#f5f6fa; }
        .navbar-logo { height:60px; }
        @media (min-width:768px) { .navbar-logo { height:90px; } }
        .navbar-brand-title { font-size:1.1rem; }
        @media (min-width:768px) { .navbar-brand-title { font-size:1.3rem; } }
        .noti-dropdown { max-height:300px; overflow-y:auto; }
        .page-padding { padding-bottom:3rem; }
        .table-sm > :not(caption) > * > * { padding:.35rem .45rem; }
        .small-table { table-layout:fixed; }
        th.col-nombre { width:170px; }
        th.col-email { width:160px; }
        th.col-rol { width:130px; }
        th.col-area { width:160px; }
        th.col-bodegas { width:230px; }
        th.col-division { width:150px; }
        th.col-acciones { width:160px; }
        .cell-input { height:31px; padding:.25rem .5rem; font-size:13px; }
        .email-wrap { max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:13px; }
        .bodega-grid { display:flex; flex-direction:column; gap:.25rem; max-height:220px; overflow-y:auto; padding-right:4px; }
        .bodega-item { background:#fff; border:1px solid #e5e7eb; border-radius:.4rem; padding:.25rem .45rem; font-size:12px; display:flex; align-items:center; }
        .bodega-item input { margin-right:.35rem; transform:scale(.9); }
        .btn-compact { padding:.28rem .55rem; font-size:.8rem; }
        @media (max-width:576px) {
            th.col-email, td.col-email,
            th.col-bodegas, td.col-bodegas { display:none; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" class="navbar-logo">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega</span>
        <div class="d-flex align-items-center">
            <span class="me-3 text-white">
                <?= htmlspecialchars($_SESSION['user']) ?>
                (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                <?php if ($divisionName): ?>
                    <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars($divisionName) ?></span>
                <?php endif; ?>
            </span>

            <div class="dropdown me-2">
                <button class="btn btn-outline-light btn-sm position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown">
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

            <a href="dashboard.php" class="btn btn-outline-light btn-sm me-1">🏠</a>
            <a href="logout.php" class="btn btn-outline-light btn-sm">⏻</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
  <?php if ($mensaje): ?>
    <div class="alert alert-info"><?= htmlspecialchars($mensaje) ?></div>
  <?php endif; ?>

  <!-- Crear nueva bodega -->
  <div class="card shadow-sm mb-3">
    <div class="card-body tight">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="crear" value="1">
        <div class="col-md-3">
          <label class="form-label mb-0">Nombre</label>
          <input type="text" name="nombre" class="form-control" required placeholder="Bodega Secundaria DMH">
        </div>
        <div class="col-md-2">
          <label class="form-label mb-0">Abreviatura</label>
          <input type="text" name="abreviatura" class="form-control" placeholder="DMH2">
        </div>
        <div class="col-md-3">
          <label class="form-label mb-0">División</label>
          <select name="division_id" class="form-select" required>
            <option value="">Seleccione…</option>
            <?php foreach ($DIV_BY_ID as $id => $nombreDiv): ?>
              <option value="<?= (int)$id ?>"><?= htmlspecialchars($nombreDiv) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 form-check mt-4">
          <input class="form-check-input" type="checkbox" name="is_principal" id="np" value="1">
          <label class="form-check-label" for="np">Principal</label>
        </div>
        <div class="col-md-1 form-check mt-4">
          <input class="form-check-input" type="checkbox" name="activa" id="na" value="1" checked>
          <label class="form-check-label" for="na">Activa</label>
        </div>
        <div class="col-md-1">
          <button class="btn btn-success w-100">Crear</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Listado -->
  <div class="table-responsive">
    <table class="table table-bordered table-striped align-middle tight">
      <thead class="table-dark">
        <tr>
          <th style="width:65px">ID</th>
          <th style="min-width:220px">Nombre</th>
          <th style="min-width:120px">Abrev.</th>
          <th style="min-width:220px">División</th>
          <th style="width:110px">Principal</th>
          <th style="width:100px">Activa</th>
          <th style="min-width:220px">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($bodegas as $b):
            $fid = 'f-'.$b['id'];
            $currentDivId = (int)($b['division_id'] ?? 0);
            if ($currentDivId === 0 && !empty($b['division']) && isset($ID_BY_NAME[$b['division']])) {
                $currentDivId = $ID_BY_NAME[$b['division']];
            }
      ?>
        <tr>
          <td><?= (int)$b['id'] ?></td>

          <!-- NOMBRE -->
          <td>
            <input type="text" class="form-control"
                   name="nombre" value="<?= htmlspecialchars($b['nombre']) ?>"
                   required form="<?= $fid ?>">
          </td>

          <!-- ABREVIATURA -->
          <td>
            <input type="text" class="form-control"
                   name="abreviatura" value="<?= htmlspecialchars($b['abreviatura'] ?? '') ?>"
                   form="<?= $fid ?>">
          </td>

          <!-- DIVISIÓN (por id) -->
          <td>
            <select name="division_id" class="form-select" required form="<?= $fid ?>">
              <?php foreach ($DIV_BY_ID as $id => $nombreDiv): ?>
                <option value="<?= (int)$id ?>" <?= $currentDivId===(int)$id? 'selected':'' ?>>
                  <?= htmlspecialchars($nombreDiv) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>

          <!-- PRINCIPAL -->
          <td class="text-center">
            <input type="hidden" name="is_principal" value="0" form="<?= $fid ?>">
            <input type="checkbox" name="is_principal" value="1" <?= $b['is_principal'] ? 'checked':'' ?> form="<?= $fid ?>">
          </td>

          <!-- ACTIVA -->
          <td class="text-center">
            <input type="hidden" name="activa" value="0" form="<?= $fid ?>">
            <input type="checkbox" name="activa" value="1" <?= $b['activa'] ? 'checked':'' ?> form="<?= $fid ?>">
          </td>

          <!-- ACCIONES -->
          <td>
            <div class="d-flex gap-1">
              <form method="post" id="<?= $fid ?>" class="m-0 p-0">
                <input type="hidden" name="actualizar" value="1">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="btn btn-primary">Actualizar</button>
              </form>

              <form method="post" onsubmit="return confirm('¿Eliminar esta bodega? Solo si no tiene productos ni usuarios asociados.');">
                <input type="hidden" name="eliminar" value="1">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="btn btn-danger">Eliminar</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <a href="dashboard.php" class="btn btn-secondary mt-2">Volver</a>
</div>
</body>
</html>
