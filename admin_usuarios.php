<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

require 'config/db.php';

$mensaje = '';

// Traducción de roles
$rolesMap = [
    'admin'  => 'Administrador',
    'editor' => 'Editor',
    'viewer' => 'Lector'
];

// Actualizar usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_user']) && isset($_POST['user_id'], $_POST['new_username'], $_POST['new_role'], $_POST['new_area'])) {
        $userId = (int) $_POST['user_id'];
        $newUsername = trim($_POST['new_username']);
        $newRole = $_POST['new_role']; // admin/editor/viewer
        $newArea = $_POST['new_area'];

        $roles = ['admin', 'editor', 'viewer'];
        $areas = ['Radios', 'Redes', 'SCA', 'Libreria'];

        if (in_array($newRole, $roles) && in_array($newArea, $areas) && $newUsername !== '') {

            // Obtener datos actuales antes de actualizar
            $stmt = $conn->prepare("SELECT username, role, area FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $usuarioActual = $stmt->fetch(PDO::FETCH_ASSOC);

            // Actualizar usuario
            $stmt = $conn->prepare("UPDATE users SET username = ?, role = ?, area = ? WHERE id = ?");
            $stmt->execute([$newUsername, $newRole, $newArea, $userId]);

            // Log: usuario actualizado con valores antes y después
            $log = $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at) 
                                   VALUES (?, 'user_updated', ?, ?, ?, NOW())");
            $log->execute([
                $_SESSION['user_id'],
                "Admin actualizó al usuario ID {$userId}",
                "Nombre: {$usuarioActual['username']}, Rol: {$rolesMap[$usuarioActual['role']]}, Área: {$usuarioActual['area']}",
                "Nombre: {$newUsername}, Rol: {$rolesMap[$newRole]}, Área: {$newArea}"
            ]);

            $mensaje = "Usuario actualizado correctamente.";
        }
    }

    // Eliminar usuario
    if (isset($_POST['delete_user']) && isset($_POST['user_id'])) {
        $userId = (int) $_POST['user_id'];
        if ($userId !== $_SESSION['user_id']) { // evitar que el admin se borre a sí mismo

            // Obtener info antes de eliminar
            $stmt = $conn->prepare("SELECT username, role, area FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);

            // Log: usuario eliminado
            $log = $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at) 
                                   VALUES (?, 'user_deleted', ?, ?, NULL, NOW())");
            $log->execute([
                $_SESSION['user_id'],
                "Admin eliminó al usuario ID {$userId}",
                "Nombre: {$userData['username']}, Rol: {$rolesMap[$userData['role']]}, Área: {$userData['area']}"
            ]);

            $mensaje = "Usuario eliminado correctamente.";
        }
    }
}

// Obtener todos los usuarios
$stmt = $conn->query("SELECT id, username, email, role, area FROM users");
$usuarios = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios - Bodega Sonda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>
            <div>
                <span class="me-3 text-white">Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?> / (<?= $rolesMap[$_SESSION['role']] ?? $_SESSION['role'] ?>)</span>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver</a>
            </div>
    </div>
</nav>

<div class="container mt-5">
    <h3 class="mb-4">Gestión de Usuarios</h3>

    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-success"><?= $mensaje ?></div>
    <?php endif; ?>

    <table class="table table-striped table-bordered">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Email</th>
                <th>Rol</th>
                <th>Área</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= $rolesMap[$u['role']] ?? $u['role'] ?></td>
                    <td><?= $u['area'] ?></td>
                    <td>
                        <form method="POST" class="row g-1">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <div class="col-md-3">
                                <input type="text" name="new_username" class="form-control form-control-sm" value="<?= htmlspecialchars($u['username']) ?>" required>
                            </div>
                            <div class="col-md-3">
                                <select name="new_role" class="form-select form-select-sm">
                                    <option value="viewer" <?= $u['role'] === 'viewer' ? 'selected' : '' ?>>Lector</option>
                                    <option value="editor" <?= $u['role'] === 'editor' ? 'selected' : '' ?>>Editor</option>
                                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="new_area" class="form-select form-select-sm">
                                    <option value="Radios" <?= $u['area'] === 'Radios' ? 'selected' : '' ?>>Radios</option>
                                    <option value="Redes" <?= $u['area'] === 'Redes' ? 'selected' : '' ?>>Redes</option>
                                    <option value="SCA" <?= $u['area'] === 'SCA' ? 'selected' : '' ?>>SCA</option>
                                    <option value="Libreria" <?= $u['area'] === 'Libreria' ? 'selected' : '' ?>>Librería</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex gap-1">
                                <button type="submit" name="update_user" class="btn btn-sm btn-primary">Actualizar</button>
                                <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                    <button type="submit" name="delete_user" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este usuario?')">Eliminar</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    

    <a href="dashboard.php" class="btn btn-secondary">Volver al Dashboard</a>
</div>
</body>
</html>
