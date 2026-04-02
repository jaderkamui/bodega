<?php
session_start();
if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}
require 'config/db.php';

$mensaje = '';
$tipoMsg = 'success';

$userIdSess   = (int)($_SESSION['user_id'] ?? 0);
$areaUsuario  = $_SESSION['area'] ?? null;
$divisionName = $_SESSION['division_name'] ?? null;

/* ========= Helpers ========= */
function flash(&$mensaje, &$tipoMsg, string $msg, string $type='success') {
    $mensaje = $msg;
    $tipoMsg = $type;
}

function nextDivisionId(PDO $conn): int {
    $max = (int)$conn->query("SELECT IFNULL(MAX(id),0) FROM divisiones")->fetchColumn();
    return $max + 1;
}

function divisionInUse(PDO $conn, int $divisionId): array {
    $u = $conn->prepare("SELECT COUNT(*) FROM users WHERE division_id = ?");
    $u->execute([$divisionId]);
    $usersCount = (int)$u->fetchColumn();

    $b = $conn->prepare("SELECT COUNT(*) FROM bodegas WHERE division_id = ?");
    $b->execute([$divisionId]);
    $bodegasCount = (int)$b->fetchColumn();

    return [$usersCount, $bodegasCount];
}

/* ========= Notificaciones ========= */
$mark = $conn->prepare("UPDATE notificaciones SET leido = 1 WHERE user_id = ? AND link = 'admin_divisiones.php'");
$mark->execute([$userIdSess]);

$notiStmt = $conn->prepare("SELECT id, mensaje, link, leido, created_at
                            FROM notificaciones
                            WHERE user_id = ? AND leido = 0
                            ORDER BY created_at DESC
                            LIMIT 5");
$notiStmt->execute([$userIdSess]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);

/* ========= Acciones CRUD ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // Crear
    if ($accion === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');

        if ($nombre === '') {
            flash($mensaje, $tipoMsg, "⚠️ Debes ingresar un nombre de división.", "warning");
        } else {
            $chk = $conn->prepare("SELECT COUNT(*) FROM divisiones WHERE nombre = ?");
            $chk->execute([$nombre]);
            if ((int)$chk->fetchColumn() > 0) {
                flash($mensaje, $tipoMsg, "⚠️ Ya existe una división con ese nombre.", "warning");
            } else {
                $newId = nextDivisionId($conn);

                if ($newId > 255) {
                    flash($mensaje, $tipoMsg, "❌ Límite de IDs alcanzado (255). Contacta al desarrollador.", "danger");
                } else {
                    $ins = $conn->prepare("INSERT INTO divisiones (id, nombre) VALUES (?, ?)");
                    $ins->execute([$newId, $nombre]);

                    // LOG: división creada
                    $log = $conn->prepare("
                        INSERT INTO logs (user_id, action, details, created_at)
                        VALUES (?, 'division_created', ?, NOW())
                    ");
                    $log->execute([
                        $userIdSess,
                        "Creó división ID {$newId}: {$nombre}"
                    ]);

                    flash($mensaje, $tipoMsg, "✅ División creada (ID {$newId}).", "success");
                }
            }
        }
    }

    // Editar
    if ($accion === 'editar') {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');

        if ($id <= 0 || $nombre === '') {
            flash($mensaje, $tipoMsg, "⚠️ Datos inválidos para editar.", "warning");
        } else {
            $chk = $conn->prepare("SELECT nombre FROM divisiones WHERE id = ?");
            $chk->execute([$id]);
            $oldNombre = $chk->fetchColumn();

            if ($oldNombre === false) {
                flash($mensaje, $tipoMsg, "⚠️ División no encontrada.", "warning");
            } elseif ($oldNombre !== $nombre) {
                $chkDup = $conn->prepare("SELECT COUNT(*) FROM divisiones WHERE nombre = ? AND id <> ?");
                $chkDup->execute([$nombre, $id]);
                if ((int)$chkDup->fetchColumn() > 0) {
                    flash($mensaje, $tipoMsg, "⚠️ Ya existe otra división con ese nombre.", "warning");
                } else {
                    $up = $conn->prepare("UPDATE divisiones SET nombre = ? WHERE id = ?");
                    $up->execute([$nombre, $id]);

                    // LOG: división actualizada
                    $log = $conn->prepare("
                        INSERT INTO logs (user_id, action, details, old_value, new_value, created_at)
                        VALUES (?, 'division_updated', ?, ?, ?, NOW())
                    ");
                    $log->execute([
                        $userIdSess,
                        "Actualizó división ID {$id}",
                        $oldNombre,
                        $nombre
                    ]);

                    flash($mensaje, $tipoMsg, "✅ División actualizada.", "success");
                }
            } else {
                flash($mensaje, $tipoMsg, "ℹ️ No hubo cambios en el nombre.", "info");
            }
        }
    }

  // Eliminar
if ($accion === 'eliminar') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        flash($mensaje, $tipoMsg, "⚠️ ID inválido para eliminar.", "warning");
    } else {
        $chk = $conn->prepare("SELECT nombre FROM divisiones WHERE id = ?");
        $chk->execute([$id]);
        $nombre = $chk->fetchColumn();

        if ($nombre === false) {
            flash($mensaje, $tipoMsg, "⚠️ División no encontrada.", "warning");
        } else {
            [$usersCount, $bodegasCount] = divisionInUse($conn, $id);

            if ($usersCount > 0 || $bodegasCount > 0) {
                flash(
                    $mensaje,
                    $tipoMsg,
                    "⛔ No se puede eliminar. Está en uso por {$usersCount} usuario(s) y {$bodegasCount} bodega(s).",
                    "danger"
                );
            } else {
                // Borrar la división
                $del = $conn->prepare("DELETE FROM divisiones WHERE id = ?");
                $del->execute([$id]);

                // LOG: división eliminada
                $log = $conn->prepare("
                    INSERT INTO logs (user_id, action, details, created_at)
                    VALUES (?, 'division_deleted', ?, NOW())
                ");
                $log->execute([
                    $userIdSess,
                    "Eliminó división ID {$id}: {$nombre}"
                ]);

                flash($mensaje, $tipoMsg, "🗑️ División eliminada correctamente.", "success");
            }
        }
    }
}
}

/* ========= Listado ========= */
$stmt = $conn->query("SELECT id, nombre FROM divisiones ORDER BY id ASC");
$divisiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestión de Divisiones - Bodega</title>
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
        th.col-id { width:90px; }
        th.col-nombre { width:300px; }
        th.col-acciones { width:200px; }
        .btn-compact { padding:.28rem .55rem; font-size:.8rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
  <div class="container-fluid d-flex justify-content-between align-items-center">
    <img src="assets/logo.png" alt="Sonda Logo" class="navbar-logo">
    <span class="navbar-brand mb-0 text-white">Sistema de Bodega</span>

    <div class="d-flex align-items-center">
      <span class="me-3 text-white d-none d-md-inline">
        <?= htmlspecialchars($_SESSION['user']) ?>
        <small>
          (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
          <?php if ($divisionName): ?> — <span class="badge text-bg-secondary"><?= htmlspecialchars($divisionName) ?></span><?php endif; ?>
        </small>
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

<div class="container mt-4 page-padding">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h3 class="mb-0">🏢 Administrar Divisiones</h3>
    <a href="dashboard.php" class="btn btn-secondary btn-sm">Volver</a>
  </div>

  <?php if ($mensaje): ?>
    <div class="alert alert-<?= htmlspecialchars($tipoMsg) ?> mt-3">
      <?= htmlspecialchars($mensaje) ?>
    </div>
  <?php endif; ?>

  <!-- Crear -->
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h5 class="mb-3">➕ Crear nueva división</h5>
      <form method="post" class="row g-2 align-items-center">
        <input type="hidden" name="accion" value="crear">
        <div class="col-md-8">
          <input type="text" name="nombre" class="form-control" placeholder="Ej: División Ministro Hales" required>
          <div class="form-text">El ID se asigna automáticamente (MAX(id)+1).</div>
        </div>
        <div class="col-md-4 d-grid">
          <button class="btn btn-success">Crear división</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Listado -->
  <div class="card shadow-sm">
    <div class="card-body">
      <h5 class="mb-3">📋 Divisiones existentes</h5>

      <?php if (empty($divisiones)): ?>
        <div class="alert alert-info">No hay divisiones registradas.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-bordered table-striped align-middle">
            <thead class="table-dark">
              <tr>
                <th class="col-id">ID</th>
                <th class="col-nombre">Nombre</th>
                <th class="col-acciones">Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($divisiones as $d):
              $id = (int)$d['id'];
              $nombre = $d['nombre'];
              [$usersCount, $bodegasCount] = divisionInUse($conn, $id);
              $bloqueado = ($usersCount > 0 || $bodegasCount > 0);
            ?>
              <tr>
                <td><strong><?= $id ?></strong></td>
                <td>
                  <form method="post" class="d-flex gap-2 mb-0">
                    <input type="hidden" name="accion" value="editar">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="text" name="nombre" value="<?= htmlspecialchars($nombre) ?>" class="form-control form-control-sm" required>
                    <button class="btn btn-primary btn-compact">Guardar</button>
                  </form>
                  <div class="form-text small mt-1">
                    En uso: <?= $usersCount ?> usuario(s) · <?= $bodegasCount ?> bodega(s)
                  </div>
                </td>
                <td>
                  <form method="post" class="mb-0">
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn btn-danger btn-sm w-100"
                            <?= $bloqueado ? 'disabled' : '' ?>
                            onclick="return confirm('¿Eliminar división ID <?= $id ?> (<?= htmlspecialchars($nombre) ?>)? Esta acción no se puede deshacer.');">
                      🗑️ Eliminar
                    </button>
                  </form>
                  <?php if ($bloqueado): ?>
                    <div class="small text-muted mt-1">
                      No se puede eliminar (en uso por usuarios/bodegas).
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>