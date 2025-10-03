<?php
require 'config/db.php';

$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $area = $_POST['area'];  // Nueva selección de área

    if ($password !== $confirm_password) {
        $mensaje = 'Las contraseñas no coinciden.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            $mensaje = 'El correo ya está registrado.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Insertar usuario con área y rol por defecto = "viewer"
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, area) VALUES (?, ?, ?, 'viewer', ?)");
            $stmt->execute([$username, $email, $hashedPassword, $area]);

            // Obtener ID del usuario recién creado
            $newUserId = $conn->lastInsertId();

            // Registrar en logs
            $log = $conn->prepare("INSERT INTO logs (user_id, action, details, created_at) VALUES (?, 'user_registered', ?, NOW())");
            $log->execute([
                $newUserId,
                "Nuevo usuario registrado: {$username} ({$email}) - área: {$area}, rol: viewer"
            ]);

            header('Location: index.php?success=1');
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <meta charset="UTF-8">
    <title>Registro - Bodega Sonda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
    <div class="card shadow p-4" style="min-width: 400px;">

        <!-- LOGO -->
        <img src="assets/logo.png" alt="Logo Sonda" style="height: 150px;" class="mb-3 mx-auto d-block">

        <h3 class="mb-3 text-center">Registro de Usuario</h3>

        <?php if ($mensaje): ?>
            <div class="alert alert-danger"><?= $mensaje ?></div>
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
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('password', this)"> <i class="bi bi-eye"></i>
                </button>

                </div>
            </div>

            <div class="mb-2">
                <label class="form-label">Confirmar contraseña</label>
                <div class="input-group">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('confirm_password', this)"><i class="bi bi-eye"></i></button>
                </div>
            </div>

            <!-- Nueva selección de área -->
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
