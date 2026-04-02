<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}
require 'config/db.php';

$mensaje = '';

// 1. Cargar divisiones
$divStmt = $conn->query("SELECT id, nombre FROM divisiones ORDER BY id");
$divisiones = $divStmt->fetchAll(PDO::FETCH_ASSOC);
$divById = [];
foreach ($divisiones as $d) {
    $divById[(int)$d['id']] = $d['nombre'];
}

// 2. Config estática
$rolesMap     = ['admin'=>'Administrador','editor'=>'Editor','lector'=>'Lector'];
$rolesValidos = ['lector','editor','admin'];
$areasValidas = ['Radios','Redes','SCA','Libreria'];

// 3. Todas las bodegas
$bStmt = $conn->query("SELECT id, nombre, division_id FROM bodegas ORDER BY nombre");
$todasBodegas = $bStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Asignaciones user -> bodegas
$ab = $conn->query("SELECT user_id, bodega_id FROM user_bodegas");
$userBodegas = [];
foreach ($ab as $row) {
    $userBodegas[(int)$row['user_id']][] = (int)$row['bodega_id'];
}

// 5. POST (guardar / eliminar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Guardar fila
    if (isset($_POST['save_row'])) {
        $uid = (int)($_POST['user_id'] ?? 0);

        if ($uid > 0) {
            // Datos actuales
            $cur = $conn->prepare("SELECT username, email, role, area, division_id FROM users WHERE id=?");
            $cur->execute([$uid]);
            $before = $cur->fetch(PDO::FETCH_ASSOC);

            if ($before) {
                $newUsername  = trim($_POST['new_username'] ?? '');
                $newRole      = $_POST['new_role'] ?? $before['role'];
                $newArea      = $_POST['new_area'] ?? $before['area'];
                $newDivId     = (int)($_POST['new_division_id'] ?? $before['division_id']);

                if ($newUsername === '') $newUsername = $before['username'];
                if (!in_array($newRole, $rolesValidos, true)) $newRole = $before['role'];
                if (!in_array($newArea, $areasValidas, true)) $newArea = $before['area'];
                if (!isset($divById[$newDivId])) $newDivId = (int)$before['division_id'];

                // Detectar cambios
                $changes = [];
                if ($before['username'] !== $newUsername) {
                    $changes[] = ['campo'=>'Nombre','old'=>$before['username'],'new'=>$newUsername];
                }
                if ($before['role'] !== $newRole) {
                    $changes[] = ['campo'=>'Rol','old'=>($rolesMap[$before['role']] ?? $before['role']),'new'=>($rolesMap[$newRole] ?? $newRole)];
                }
                if ($before['area'] !== $newArea) {
                    $changes[] = ['campo'=>'Área','old'=>$before['area'],'new'=>$newArea];
                }
                if ((int)$before['division_id'] !== $newDivId) {
                    $changes[] = [
                        'campo'=>'División',
                        'old'=>$divById[(int)$before['division_id']] ?? '(sin división)',
                        'new'=>$divById[$newDivId] ?? '(sin división)'
                    ];
                }

                // Actualizar usuario
                $upd = $conn->prepare("UPDATE users SET username=?, role=?, area=?, division_id=? WHERE id=?");
                $upd->execute([$newUsername, $newRole, $newArea, $newDivId, $uid]);

                // Bodegas
                $seleccionadas = isset($_POST['bodegas']) && is_array($_POST['bodegas'])
                    ? array_map('intval', $_POST['bodegas'])
                    : [];
                $antesBod = $userBodegas[$uid] ?? [];

                // Reemplazar asignaciones
                $conn->prepare("DELETE FROM user_bodegas WHERE user_id=?")->execute([$uid]);
                if (!empty($seleccionadas)) {
                    $ins = $conn->prepare("INSERT INTO user_bodegas (user_id, bodega_id) VALUES (?, ?)");
                    foreach ($seleccionadas as $bid) {
                        $ins->execute([$uid, $bid]);
                    }
                }

                sort($antesBod);
                $despuesBod = $seleccionadas;
                sort($despuesBod);

                if ($antesBod !== $despuesBod) {
                    $oldNames = [];
                    if (!empty($antesBod)) {
                        $q = $conn->prepare("SELECT nombre FROM bodegas WHERE id IN (" . implode(',', array_fill(0, count($antesBod), '?')) . ")");
                        $q->execute($antesBod);
                        $oldNames = $q->fetchAll(PDO::FETCH_COLUMN);
                    }
                    $newNames = [];
                    if (!empty($despuesBod)) {
                        $q = $conn->prepare("SELECT nombre FROM bodegas WHERE id IN (" . implode(',', array_fill(0, count($despuesBod), '?')) . ")");
                        $q->execute($despuesBod);
                        $newNames = $q->fetchAll(PDO::FETCH_COLUMN);
                    }

                    $changes[] = [
                        'campo' => 'Bodegas',
                        'old'   => implode(', ', $oldNames) ?: '(ninguna)',
                        'new'   => implode(', ', $newNames) ?: '(ninguna)'
                    ];

                    $userBodegas[$uid] = $despuesBod;
                }

                // Logs unificados
                if (!empty($changes)) {
                    foreach ($changes as $c) {
                        $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at)
                                        VALUES (?, 'user_updated', ?, ?, ?, NOW())")
                             ->execute([
                                 $_SESSION['user_id'],
                                 "Actualizó {$c['campo']} del usuario ID {$uid}",
                                 $c['old'] ?? '',
                                 $c['new'] ?? ''
                             ]);
                    }
                }

                // Refrescar sesión si editó su propio usuario
                if ($uid === (int)$_SESSION['user_id']) {
                    $_SESSION['user'] = $newUsername;
                    $_SESSION['role'] = $newRole;
                    $_SESSION['area'] = $newArea;
                    $_SESSION['division_id'] = $newDivId;
                    $_SESSION['division_name'] = $divById[$newDivId] ?? null;

                    // Actualizar bodegas en sesión
                    $me = $conn->prepare("SELECT ub.bodega_id, b.nombre
                                          FROM user_bodegas ub
                                          JOIN bodegas b ON b.id = ub.bodega_id
                                          WHERE ub.user_id = ?");
                    $me->execute([$uid]);
                    $ids = [];
                    $map = [];
                    foreach ($me as $r) {
                        $ids[] = (int)$r['bodega_id'];
                        $map[(int)$r['bodega_id']] = $r['nombre'];
                    }
                    $_SESSION['bodegas_ids'] = $ids;
                    $_SESSION['bodegas_map'] = $map;
                }

                $mensaje = "Cambios guardados en el usuario ID {$uid}.";
            }
        }
    }

    // Eliminar usuario
    if (isset($_POST['delete_user'])) {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            if ($uid === (int)$_SESSION['user_id']) {
                $mensaje = "No puedes eliminar tu propio usuario.";
            } else {
                $info = $conn->prepare("SELECT username, role, area, division_id FROM users WHERE id=?");
                $info->execute([$uid]);
                $u = $info->fetch(PDO::FETCH_ASSOC);

                $conn->prepare("DELETE FROM user_bodegas WHERE user_id=?")->execute([$uid]);
                $conn->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);

                $detDiv = $u && isset($divById[(int)$u['division_id']]) ? $divById[(int)$u['division_id']] : '(sin división)';
                $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at)
                                VALUES (?, 'user_deleted', ?, ?, NULL, NOW())")
                     ->execute([
                         $_SESSION['user_id'],
                         "Eliminó usuario ID {$uid}",
                         $u
                            ? "Nombre: {$u['username']} | Rol: ".($rolesMap[$u['role']] ?? $u['role'])." | Área: {$u['area']} | División: {$detDiv}"
                            : '(desconocido)'
                     ]);

                $mensaje = "Usuario ID {$uid} eliminado correctamente.";
            }
        }
    }
}

// 6. Traer usuarios
$stmt = $conn->query("SELECT id, username, email, role, area, division_id FROM users ORDER BY id ASC");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Notificaciones para navbar
$userIdSess = (int)$_SESSION['user_id'];
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$userIdSess]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);

$areaUsuario  = $_SESSION['area'] ?? '';
$divisionName = $_SESSION['division_name'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestión de Usuarios - Bodega</title>
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

<div class="container page-padding mt-3">

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
                <div>
                    <h5 class="mb-1">Gestión de Usuarios</h5>
                    <small class="text-muted">Editar roles, áreas, divisiones y accesos a bodegas.</small>
                </div>
                <a href="dashboard.php" class="btn btn-secondary btn-sm mt-2 mt-md-0">Volver al panel</a>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-info py-2"><?= htmlspecialchars($mensaje) ?></div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-striped table-bordered table-sm align-middle small-table mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="col-nombre">Nombre</th>
                            <th class="col-email">Email</th>
                            <th class="col-rol">Rol</th>
                            <th class="col-area">Área</th>
                            <th class="col-bodegas">Bodegas</th>
                            <th class="col-division">División</th>
                            <th class="col-acciones">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($usuarios as $u):
                        $uid = (int)$u['id'];
                        $asignadas = $userBodegas[$uid] ?? [];
                    ?>
                        <tr>
                        <form method="POST">
                            <input type="hidden" name="user_id" value="<?= $uid ?>">

                            <!-- Nombre -->
                            <td>
                                <input type="text" name="new_username"
                                       class="form-control form-control-sm cell-input"
                                       value="<?= htmlspecialchars($u['username']) ?>" required>
                            </td>

                            <!-- Email -->
                            <td class="col-email">
                                <div class="email-wrap" title="<?= htmlspecialchars($u['email']) ?>">
                                    <?= htmlspecialchars($u['email']) ?>
                                </div>
                            </td>

                            <!-- Rol -->
                            <td>
                                <select name="new_role" class="form-select form-select-sm cell-input">
                                    <option value="lector" <?= $u['role']==='lector'?'selected':'' ?>>Lector</option>
                                    <option value="editor" <?= $u['role']==='editor'?'selected':'' ?>>Editor</option>
                                    <option value="admin"  <?= $u['role']==='admin' ?'selected':'' ?>>Administrador</option>
                                </select>
                            </td>

                            <!-- Área -->
                            <td>
                                <select name="new_area" class="form-select form-select-sm cell-input">
                                    <?php foreach ($areasValidas as $a): ?>
                                        <option value="<?= $a ?>" <?= $u['area']===$a?'selected':'' ?>>
                                            <?= $a==='SCA' ? 'SCA (Control de Acceso)' : $a ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <!-- Bodegas -->
                            <td class="col-bodegas">
                                <div class="bodega-grid">
                                    <?php foreach ($todasBodegas as $b): ?>
                                        <label class="bodega-item">
                                            <input type="checkbox" name="bodegas[]" value="<?= (int)$b['id'] ?>"
                                                <?= in_array((int)$b['id'],$asignadas,true)?'checked':'' ?>>
                                            <span><?= htmlspecialchars($b['nombre']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>

                            <!-- División -->
                            <td>
                                <select name="new_division_id" class="form-select form-select-sm cell-input">
                                    <?php foreach ($divisiones as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>"
                                            <?= ((int)$u['division_id'] === (int)$d['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <!-- Acciones -->
                            <td class="text-nowrap">
                                <button type="submit" name="save_row" class="btn btn-primary btn-compact me-1">
                                    Guardar
                                </button>
                                <?php if ($uid !== (int)$_SESSION['user_id']): ?>
                                    <button type="submit" name="delete_user"
                                            class="btn btn-danger btn-compact"
                                            onclick="return confirm('¿Seguro que deseas eliminar este usuario? Esta acción no se puede deshacer.')">
                                        Eliminar
                                    </button>
                                <?php endif; ?>
                            </td>
                        </form>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>