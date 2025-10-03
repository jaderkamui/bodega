<?php
session_start();
require 'config/db.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Buscar usuario por correo
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        // Guardamos en sesión todos los datos necesarios
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];   // 👈 Rol: admin/editor/viewer
        $_SESSION['area'] = $user['area'];   // 👈 Área: Radios/Redes/SCA/Libreria

        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Correo o contraseña incorrectos.";
    }
}
?>

<?php include 'includes/header.php'; ?>

<div class="container mt-5" style="max-width: 500px;">
    <div class="card shadow p-4">
        <h2 class="text-center mb-4">Iniciar Sesión</h2>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label for="email" class="form-label">Correo electrónico</label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Contraseña</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-primary">Entrar</button>
            </div>
        </form>

        <div class="mt-3 text-center">
            <a href="register.php" class="btn btn-link">Registrarse</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
