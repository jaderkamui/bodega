<?php
session_start();
require 'config/db.php';
$error = '';

/*
  Objetivo:
  - Autenticar usuario
  - Cargar división y bodegas a las que tiene acceso
  - Si el usuario no tiene bodegas asignadas en user_bodegas,
    asignar automáticamente la bodega principal (la primera) de su división
  - Guardar todo en $_SESSION para usar en el dashboard y demás páginas
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        // 1) Buscar usuario por correo
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {

            // 2) Asegurarnos de que exista la división y obtener su nombre (si está seteada)
            $divisionName = null;
            if (!empty($user['division_id'])) {
                $qDiv = $conn->prepare("SELECT nombre FROM divisiones WHERE id = ? LIMIT 1");
                $qDiv->execute([$user['division_id']]);
                $divisionRow = $qDiv->fetch(PDO::FETCH_ASSOC);
                $divisionName = $divisionRow ? $divisionRow['nombre'] : null;
            }

            // 3) Obtener bodegas a las que el usuario tiene acceso
            $qUB = $conn->prepare("
                SELECT b.id, b.nombre
                FROM user_bodegas ub
                INNER JOIN bodegas b ON b.id = ub.bodega_id
                WHERE ub.user_id = ?
                ORDER BY b.id
            ");
            $qUB->execute([$user['id']]);
            $bodegas = $qUB->fetchAll(PDO::FETCH_ASSOC);

            // 4) Si no tiene bodegas asignadas pero sí división, asignar bodega principal de su división
            if (empty($bodegas) && !empty($user['division_id'])) {
                $qBP = $conn->prepare("SELECT id, nombre FROM bodegas WHERE division_id = ? ORDER BY id LIMIT 1");
                $qBP->execute([$user['division_id']]);
                $bodegaPrincipal = $qBP->fetch(PDO::FETCH_ASSOC);

                if ($bodegaPrincipal) {
                    $ins = $conn->prepare("INSERT INTO user_bodegas (user_id, bodega_id) VALUES (?, ?)");
                    $ins->execute([$user['id'], $bodegaPrincipal['id']]);

                    // Volver a leer las bodegas para sesión
                    $qUB->execute([$user['id']]);
                    $bodegas = $qUB->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            // 5) Preparar estructuras para la sesión
            $bodegasIds   = array_map(fn($r) => (int)$r['id'], $bodegas);
            $bodegasNames = [];
            foreach ($bodegas as $b) { $bodegasNames[(int)$b['id']] = $b['nombre']; }

            // 6) Guardar datos en sesión (ojo: mantengo 'user' por compatibilidad con tu UI)
            $_SESSION['user']          = $user['username'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['user_id']       = (int)$user['id'];
            $_SESSION['role']          = $user['role'];      // admin/editor/viewer
            $_SESSION['area']          = $user['area'];      // Radios/Redes/SCA/Libreria
            $_SESSION['division_id']   = !empty($user['division_id']) ? (int)$user['division_id'] : null;
            $_SESSION['division_name'] = $divisionName;
            $_SESSION['bodegas_ids']   = $bodegasIds;        // [1,5,9]
            $_SESSION['bodegas_map']   = $bodegasNames;      // [1=>'Bodega X', 5=>'Bodega Y', ...]

            // 7) ¡Listo!
            header("Location: dashboard.php");
            exit;

        } else {
            $error = "Correo o contraseña incorrectos.";
        }
    } catch (Exception $e) {
        $error = "Error de autenticación. " . $e->getMessage();
    }
}
?>

<?php include 'includes/header.php'; ?>

<div class="container mt-5" style="max-width: 500px;">
    <div class="card shadow p-4">
        <h2 class="text-center mb-4">Iniciar Sesión</h2>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label for="email" class="form-label">Correo electrónico</label>
                <input type="email" class="form-control" id="email" name="email" required autofocus>
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
