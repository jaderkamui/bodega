<?php
session_start();
require 'config/db.php';

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Buscar usuario por email
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verificar contraseña
    if ($user && password_verify($password, $user['password'])) {
        // Guardamos en sesión toda la info necesaria
        $_SESSION['user'] = $user['username'];
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];   // admin, editor, viewer
        $_SESSION['area'] = $user['area'];   // Radios, Redes, SCA, Libreria

        header("Location: dashboard.php");
        exit;
    } else {
        $mensaje = 'Credenciales incorrectas.';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iniciar Sesión - Bodega Sonda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
    <div class="card shadow p-4 text-center" style="min-width: 350px;">
        <!-- LOGO -->
        <img src="assets/logo.png" alt="Logo Sonda" style="height: 250px;" class="mb-3">

        <!-- TÍTULO -->
        <h3 class="mb-4">Sistema de Bodega</h3>

        <!-- MENSAJES -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">¡Usuario registrado con éxito! Inicia sesión.</div>
        <?php endif; ?>

        <?php if ($mensaje): ?>
            <div class="alert alert-danger"><?= $mensaje ?></div>
        <?php endif; ?>

        <!-- FORMULARIO -->
        <form method="POST">
            <div class="mb-3 text-start">
                <label class="form-label">Correo electrónico</label>
                <input type="email" name="email" class="form-control" required>
            </div>

            <div class="mb-3 text-start">
                <label class="form-label">Contraseña</label>
                <input type="password" name="password" class="form-control" required>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-primary">Entrar</button>
            </div>
        </form>

        <!-- ENLACE A REGISTRO -->
        <div class="mt-3">
            <a href="register.php">¿No tienes cuenta? Regístrate aquí</a>
        </div>
    </div>
</body>
</html>
