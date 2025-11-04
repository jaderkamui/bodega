<?php
session_start();
require 'config/db.php';

$mensaje = '';

/*
  Flujo de login con multi-división/bodegas:
  1) Autenticar por email + password.
  2) Cargar nombre de división (si tiene division_id).
  3) Cargar bodegas permitidas desde user_bodegas.
  4) Si no tiene bodegas pero sí división, asignar la bodega principal (primera por id) de esa división.
  5) Guardar todo en $_SESSION para el dashboard y el resto del sistema.
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        // 1) Buscar usuario
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {

            // 2) Traer nombre de división (si existe)
            $divisionName = null;
            if (!empty($user['division_id'])) {
                $qDiv = $conn->prepare("SELECT nombre FROM divisiones WHERE id = ? LIMIT 1");
                $qDiv->execute([$user['division_id']]);
                $div = $qDiv->fetch(PDO::FETCH_ASSOC);
                $divisionName = $div ? $div['nombre'] : null;
            }

            // 3) Bodegas a las que tiene acceso
            $qUB = $conn->prepare("
                SELECT b.id, b.nombre
                FROM user_bodegas ub
                INNER JOIN bodegas b ON b.id = ub.bodega_id
                WHERE ub.user_id = ?
                ORDER BY b.id
            ");
            $qUB->execute([$user['id']]);
            $bodegas = $qUB->fetchAll(PDO::FETCH_ASSOC);

            // 4) Si no tiene bodegas pero sí división, asignar bodega principal (primera por id)
            if (empty($bodegas) && !empty($user['division_id'])) {
                $qBP = $conn->prepare("SELECT id, nombre FROM bodegas WHERE division_id = ? ORDER BY id LIMIT 1");
                $qBP->execute([$user['division_id']]);
                $bodegaPrincipal = $qBP->fetch(PDO::FETCH_ASSOC);

                if ($bodegaPrincipal) {
                    $ins = $conn->prepare("INSERT INTO user_bodegas (user_id, bodega_id) VALUES (?, ?)");
                    $ins->execute([$user['id'], $bodegaPrincipal['id']]);

                    // Volver a cargar bodegas para sesión
                    $qUB->execute([$user['id']]);
                    $bodegas = $qUB->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            // Normalizar estructuras para sesión
            $bodegasIds   = array_map(fn($r) => (int)$r['id'], $bodegas);
            $bodegasNames = [];
            foreach ($bodegas as $b) { $bodegasNames[(int)$b['id']] = $b['nombre']; }

            // 5) Guardar sesión (mantengo 'user' por compatibilidad con tu UI)
            $_SESSION['user']          = $user['username'];
            $_SESSION['user_id']       = (int)$user['id'];
            $_SESSION['role']          = $user['role'];      // admin/editor/viewer
            $_SESSION['area']          = $user['area'];      // Radios/Redes/SCA/Libreria
            $_SESSION['division_id']   = !empty($user['division_id']) ? (int)$user['division_id'] : null;
            $_SESSION['division_name'] = $divisionName;      // Ministro Hales, Radomiro Tomic, etc.
            $_SESSION['bodegas_ids']   = $bodegasIds;        // [1,5,...]
            $_SESSION['bodegas_map']   = $bodegasNames;      // [1=>'Bodega X', ...]

            header("Location: dashboard.php");
            exit;
        } else {
            $mensaje = 'Credenciales incorrectas.';
        }
    } catch (Exception $e) {
        $mensaje = 'Error al iniciar sesión. ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iniciar Sesión - Bodega Sonda</title>
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">

  <!-- Bootstrap Icons local -->
  <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
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

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <!-- FORMULARIO -->
        <form method="POST" novalidate>
            <div class="mb-3 text-start">
                <label class="form-label">Correo electrónico</label>
                <input type="email" name="email" class="form-control" required autofocus>
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
