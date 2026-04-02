<?php
session_start();
if (!isset($_SESSION['user'])) { header("Location: index.php"); exit; }
require 'config/db.php';

$solicitudId = (int)($_GET['solicitud_id'] ?? 0);
if ($solicitudId<=0) die("Solicitud inválida.");

$userId = (int)$_SESSION['user_id'];
$rol = $_SESSION['role'] ?? 'lector';

$stmt = $conn->prepare("SELECT e.*, u.username
                        FROM solicitud_entregas e
                        JOIN users u ON u.id = e.user_id
                        WHERE e.solicitud_id = ?");
$stmt->execute([$solicitudId]);
$e = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$e) die("No existe voucher para esta solicitud.");

if ($rol !== 'admin' && (int)$e['user_id'] !== $userId) die("Sin permisos.");

$stmt = $conn->prepare("SELECT * FROM solicitud_entrega_items WHERE entrega_id=?");
$stmt->execute([(int)$e['id']]);
$detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Voucher</title>
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center">
    <h3>🧾 Voucher — Solicitud #<?= (int)$solicitudId ?></h3>
    <button class="btn btn-outline-dark btn-sm" onclick="window.print()">🖨️ Imprimir</button>
  </div>

  <div class="card mt-3"><div class="card-body">
    <div><strong>Solicitante:</strong> <?= htmlspecialchars($e['username']) ?></div>
    <div><strong>Estado:</strong> <?= htmlspecialchars($e['estado']) ?></div>
    <div><strong>Firmado Admin:</strong> <?= htmlspecialchars($e['admin_signed_at'] ?? '—') ?></div>
    <div><strong>Firmado Usuario:</strong> <?= htmlspecialchars($e['user_signed_at'] ?? '—') ?></div>
  </div></div>

  <div class="card mt-3"><div class="card-body">
    <h5>Detalle entregado</h5>
    <table class="table table-sm table-bordered">
      <thead class="table-light"><tr><th>Producto</th><th>Ubicación</th><th class="text-end">Cantidad</th></tr></thead>
      <tbody>
        <?php foreach($detalle as $d): ?>
          <tr>
            <td><?= htmlspecialchars($d['producto_snapshot']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($d['ubicacion_snapshot'] ?? '—') ?></td>
            <td class="text-end"><?= (int)$d['cantidad_entregada'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="row g-3 mt-2">
      <div class="col-md-6">
        <h6>Firma Admin</h6>
        <?php if ($e['admin_firma_path']): ?>
          <img src="<?= htmlspecialchars($e['admin_firma_path']) ?>" style="width:100%; border:1px solid #ddd; border-radius:8px;">
        <?php else: ?><div class="text-muted">—</div><?php endif; ?>
      </div>
      <div class="col-md-6">
        <h6>Firma Usuario</h6>
        <?php if ($e['usuario_firma_path']): ?>
          <img src="<?= htmlspecialchars($e['usuario_firma_path']) ?>" style="width:100%; border:1px solid #ddd; border-radius:8px;">
        <?php else: ?><div class="text-muted">—</div><?php endif; ?>
      </div>
    </div>
  </div></div>
</div>
</body>
</html>
