<?php
session_start();
if (!isset($_SESSION['user']) || !in_array($_SESSION['role'], ['admin','editor'])) {
    header("Location: dashboard.php");
    exit;
}

require 'config/db.php';

$rol    = $_SESSION['role'];
$userId = $_SESSION['user_id'] ?? null;

// === POST: borrar producto (y sus solicitudes asociadas si las hay) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
    $confirm  = isset($_POST['confirm']) && $_POST['confirm'] === '1';

    if ($id > 0 && $confirm) {
        // Obtener datos del producto
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($producto) {
            // Contar solicitudes asociadas (a través de solicitud_items)
            $stmtSol = $conn->prepare("
                SELECT COUNT(DISTINCT s.id) 
                FROM solicitudes s
                JOIN solicitud_items si ON si.solicitud_id = s.id
                WHERE si.product_id = ?
            ");
            $stmtSol->execute([$id]);
            $solCount = (int)$stmtSol->fetchColumn();

            // Borrar solicitudes asociadas + sus ítems (si existen)
            if ($solCount > 0) {
                // Borrar ítems primero (por integridad)
                $delItems = $conn->prepare("DELETE si FROM solicitud_items si WHERE si.product_id = ?");
                $delItems->execute([$id]);

                // Borrar solicitudes que ya no tienen ítems
                $delSol = $conn->prepare("
                    DELETE s FROM solicitudes s
                    LEFT JOIN solicitud_items si ON si.solicitud_id = s.id
                    WHERE si.solicitud_id IS NULL
                ");
                $delSol->execute();
            }

            // Registrar en logs (acción unificada)
            if ($userId) {
                $detalle = "Producto ID {$id}: {$producto['description']} ({$producto['quantity']}) en {$producto['ubicacion']}";
                if ($solCount > 0) {
                    $detalle .= " — también se eliminaron {$solCount} solicitud(es) asociadas y sus ítems.";
                }

                $log = $conn->prepare("
                    INSERT INTO logs (user_id, action, details, created_at)
                    VALUES (?, 'product_deleted', ?, NOW())
                ");
                $log->execute([$userId, $detalle]);
            }

            // Borrar producto
            $delProd = $conn->prepare("DELETE FROM products WHERE id = ?");
            $delProd->execute([$id]);

            header("Location: dashboard.php?msg=producto_eliminado");
            exit;
        }
    }

    header("Location: dashboard.php");
    exit;
}

// === GET: mostrar confirmación o borrar directo si no tiene solicitudes ===
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    header("Location: dashboard.php");
    exit;
}
$id = (int)$_GET['id'];

// Obtener datos del producto
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$producto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$producto) {
    header("Location: dashboard.php?msg=producto_no_encontrado");
    exit;
}

// Contar solicitudes asociadas (misma query corregida)
$stmtSol = $conn->prepare("
    SELECT COUNT(DISTINCT s.id) 
    FROM solicitudes s
    JOIN solicitud_items si ON si.solicitud_id = s.id
    WHERE si.product_id = ?
");
$stmtSol->execute([$id]);
$solCount = (int)$stmtSol->fetchColumn();

// Si NO hay solicitudes → borrar directo
if ($solCount === 0) {
    if ($userId) {
        $detalle = "Producto ID {$id}: {$producto['description']} ({$producto['quantity']}) en {$producto['ubicacion']}";
        $log = $conn->prepare("INSERT INTO logs (user_id, action, details, created_at) VALUES (?, 'product_deleted', ?, NOW())");
        $log->execute([$userId, $detalle]);
    }

    $delProd = $conn->prepare("DELETE FROM products WHERE id = ?");
    $delProd->execute([$id]);

    header("Location: dashboard.php?msg=producto_eliminado");
    exit;
}

// Si HAY solicitudes → mostrar confirmación
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Confirmar eliminación de producto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
</head>
<body class="bg-light">

<div class="container py-5" style="max-width: 600px;">
    <div class="card shadow-sm">
        <div class="card-header bg-danger text-white">
            <strong>Confirmar eliminación</strong>
        </div>
        <div class="card-body">
            <p>
                El producto <strong><?= htmlspecialchars($producto['description']) ?></strong><br>
                (ID: <?= (int)$producto['id'] ?>, Código: <?= htmlspecialchars($producto['barcode'] ?? '—') ?>)
            </p>
            <p class="mb-3">
                Está asociado a <strong><?= $solCount ?></strong> solicitud(es) en el sistema.
            </p>
            <p class="text-danger">
                Si continúas, se eliminarán permanentemente:
            </p>
            <ul class="mb-3">
                <li>Todas las solicitudes que incluyen este producto.</li>
                <li>Todos los ítems relacionados en esas solicitudes.</li>
                <li>El producto de la bodega.</li>
            </ul>
            <p class="fw-semibold text-danger">Esta acción **no se puede deshacer**. ¿Estás completamente seguro?</p>

            <form method="post" class="d-flex flex-wrap gap-2 mt-3">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="confirm" value="1">
                <button type="submit" class="btn btn-danger">
                    Sí, eliminar todo lo relacionado
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    Cancelar
                </a>
            </form>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>