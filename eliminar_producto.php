<?php
session_start();
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'editor') {
    header("Location: dashboard.php");
    exit;
}

require 'config/db.php';

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = $_GET['id'];

    // Obtener datos del producto antes de eliminarlo
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $producto = $stmt->fetch();

    if ($producto) {
        // ✅ Registrar en logs
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId) {
            $accion = "Eliminó el producto ID $id: {$producto['description']} ({$producto['quantity']}) en {$producto['ubicacion']}";
            $log = $conn->prepare("INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())");
            $log->execute([$userId, $accion]);
        }

        // Eliminar el producto
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
    }
}

header("Location: dashboard.php");
exit;
