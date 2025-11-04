<?php
require 'config/db.php';

$mensaje = '';

// Cargar divisiones para el selector
try {
    $stmtDiv = $conn->query("SELECT id, nombre FROM divisiones ORDER BY id");
    $divisiones = $stmtDiv->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $divisiones = [];
    $mensaje = 'Error cargando divisiones.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username          = trim($_POST['username'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $password          = $_POST['password'] ?? '';
    $confirm_password  = $_POST['confirm_password'] ?? '';
    $area              = $_POST['area'] ?? '';
    $division_id       = (int)($_POST['division_id'] ?? 0);

    // Validaciones básicas
    if ($password !== $confirm_password) {
        $mensaje = 'Las contraseñas no coinciden.';
    } elseif ($username === '' || $email === '' || $area === '' || $division_id === 0) {
        $mensaje = 'Completa todos los campos (incluida la división).';
    } else {
        try {
            // Verificar que la división exista
            $chkDiv = $conn->prepare("SELECT id, nombre FROM divisiones WHERE id = ?");
            $chkDiv->execute([$division_id]);
            $division = $chkDiv->fetch(PDO::FETCH_ASSOC);

            if (!$division) {
                $mensaje = 'La división seleccionada no existe.';
            } else {
                // Verificar email único
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->rowCount() > 0) {
                    $mensaje = 'El correo ya está registrado.';
                } else {
                    // Buscar bodega principal (la primera por orden de id) de esa división
                    $stmtB = $conn->prepare("SELECT id, nombre FROM bodegas WHERE division_id = ? ORDER BY id LIMIT 1");
                    $stmtB->execute([$division_id]);
                    $bodegaPrincipal = $stmtB->fetch(PDO::FETCH_ASSOC);

                    if (!$bodegaPrincipal) {
                        $mensaje = 'No existe una bodega registrada para la división seleccionada. Contacta al administrador.';
                    } else {
                        // Crear usuario y asignar bodega principal en una transacción
                        $conn->beginTransaction();

                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                        // role = viewer, division_id obligatorio
                        $insUser = $conn->prepare("
                            INSERT INTO users (username, email, password, role, area, division_id)
                            VALUES (?, ?, ?, 'lector', ?, ?)
                        ");
                        $insUser->execute([$username, $email, $hashedPassword, $area, $division_id]);

                        $newUserId = $conn->lastInsertId();

                        // Acceso a bodega principal de su división
                        $insUB = $conn->prepare("INSERT INTO user_bodegas (user_id, bodega_id) VALUES (?, ?)");
                        $insUB->execute([$newUserId, $bodegaPrincipal['id']]);

                        // Log de registro
                        $log = $conn->prepare("
                            INSERT INTO logs (user_id, action, details, created_at)
                            VALUES (?, 'user_registered', ?, NOW())
                        ");
                        $det = "Nuevo usuario registrado: {$username} ({$email}) - área: {$area}, rol: lector, división: {$division['nombre']}, bodega asignada: {$bodegaPrincipal['nombre']}";
                        $log->execute([$newUserId, $det]);

                        $conn->commit();

                        header('Location: index.php?success=1');
                        exit;
                    }
                }
            }
        } catch (Exception $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $mensaje = 'Error al registrar el usuario: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registro - Bodega Sonda</title>
  <!-- Bootstrap local -->
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">

  <!-- Bootstrap Icons local -->
  <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
    <div class="card shadow p-4" style="min-width: 400px;">

        <!-- LOGO -->
        <img src="assets/logo.png" alt="Logo Sonda" style="height: 150px;" class="mb-3 mx-auto d-block">

        <h3 class="mb-3 text-center">Registro de Usuario</h3>

        <?php if ($mensaje): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-2">
                <label class="form-label">Nombre de usuario</label>
                <input type="text" name="username" class="form-control" required>
            </div>

            <div class="mb-2">
                <label class="form-label">Correo electrónico</label>
                <input type="email" name="email" class="form-control" required>
            </div>

            <div class="mb-2">
                <label class="form-label">Contraseña</label>
                <div class="input-group">
                    <input type="password" name="password" id="password" class="form-control" required>
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('password', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label">Confirmar contraseña</label>
                <div class="input-group">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('confirm_password', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Selección de División (obligatoria) -->
            <div class="mb-2">
                <label class="form-label">División</label>
                <select name="division_id" class="form-select" required>
                    <option value="">Seleccione una división</option>
                    <?php foreach ($divisiones as $d): ?>
                        <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Selección de Área -->
            <div class="mb-2">
                <label class="form-label">Área de trabajo</label>
                <select name="area" class="form-select" required>
                    <option value="">Seleccione un área</option>
                    <option value="Radios">Radios</option>
                    <option value="Redes">Redes</option>
                    <option value="SCA">SCA (Control de Acceso)</option>
                    <option value="Libreria">Librería</option>
                </select>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-success">Registrar</button>
            </div>
        </form>

        <div class="mt-3 text-center">
            <a href="index.php">← Volver al Login</a>
        </div>
    </div>

<script>
function togglePassword(fieldId, btn) {
    const field = document.getElementById(fieldId);
    const icon = btn.querySelector("i");

    if (field.type === "password") {
        field.type = "text";
        icon.classList.remove("bi-eye");
        icon.classList.add("bi-eye-slash");
    } else {
        field.type = "password";
        icon.classList.remove("bi-eye-slash");
        icon.classList.add("bi-eye");
    }
}
</script>
</body>
</html>
