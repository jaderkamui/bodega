<?php
session_start();
if (!isset($_SESSION['user'])) { header("Location: index.php"); exit; }
require 'config/db.php';

$userId = (int)$_SESSION['user_id'];
$token = trim($_GET['token'] ?? '');

if ($token === '') die("Token inválido.");

// Buscar entrega por token y validar que sea del usuario
$stmt = $conn->prepare("SELECT e.*, s.ticket, s.detalle
                        FROM solicitud_entregas e
                        JOIN solicitudes s ON s.id = e.solicitud_id
                        WHERE e.token_usuario = ? AND e.user_id = ?");
$stmt->execute([$token, $userId]);
$entrega = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$entrega) die("No tienes acceso a este comprobante.");

$entregaId = (int)$entrega['id'];

$stmt = $conn->prepare("SELECT * FROM solicitud_entrega_items WHERE entrega_id=?");
$stmt->execute([$entregaId]);
$detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['firma_base64'])) {
  if ($entrega['estado'] !== 'PENDIENTE_USUARIO') {
    die("Este comprobante no está listo para firma de usuario.");
  }

  $firma = $_POST['firma_base64'];
  if (preg_match('/^data:image\/png;base64,/', $firma)) {
    $firma = substr($firma, strpos($firma, ',') + 1);
  }
  $bin = base64_decode($firma);
  if ($bin === false) die("Firma inválida.");

  $dir = __DIR__ . "/uploads/firmas";
  if (!is_dir($dir)) mkdir($dir, 0777, true);

  $filename = "user_entrega_{$entregaId}_" . time() . ".png";
  $path = $dir . "/" . $filename;
  file_put_contents($path, $bin);

  $relPath = "uploads/firmas/" . $filename;
  $obs = trim($_POST['observacion_usuario'] ?? '');

  $up = $conn->prepare("UPDATE solicitud_entregas
                        SET usuario_firma_path=?, user_signed_at=NOW(),
                            observacion_usuario=?, estado='COMPLETADA',
                            updated_at=NOW()
                        WHERE id=? AND user_id=?");
  $up->execute([$relPath, $obs, $entregaId, $userId]);

          // LOG DE ENTREGA COMPLETADA
        $log = $conn->prepare("
            INSERT INTO logs (user_id, action, details, created_at)
            VALUES (?, 'entrega_registrada', ?, NOW())
        ");
        $log->execute([
            $userId,
            "Entrega completada y firmada por receptor (Solicitud #{$solicitudId}) — Receptor: {$receptorNombre} (RUT: {$receptorRut})"
        ]);

  // Notificar admin
  $notif = $conn->prepare("INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at)
                           VALUES (?, ?, ?, 0, NOW())");
  $notif->execute([
    (int)$entrega['admin_id'],
    "✅ El usuario firmó el comprobante de entrega (Solicitud #{$entrega['solicitud_id']}).",
    "voucher_entrega.php?solicitud_id=" . (int)$entrega['solicitud_id']
  ]);

  $mensaje = "✅ Firma registrada. Comprobante completado.";
  $stmt = $conn->prepare("SELECT * FROM solicitud_entregas WHERE id=?");
  $stmt->execute([$entregaId]);
  $entrega = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Firmar Entrega</title>
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
  <style>
    canvas{border:1px solid #ccc; border-radius:8px; width:100%; max-width:520px; height:180px;}
  </style>
</head>
<body class="bg-light">
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h3 class="mb-0">✍️ Firmar recepción — Solicitud #<?= (int)$entrega['solicitud_id'] ?></h3>
    <div class="d-flex gap-2">
      <a href="mis_solicitudes.php" class="btn btn-secondary btn-sm">Volver</a>
      <button class="btn btn-outline-dark btn-sm" onclick="window.print()">🖨️ Imprimir</button>
    </div>
  </div>

  <?php if ($mensaje): ?>
    <div class="alert alert-success mt-3"><?= htmlspecialchars($mensaje) ?></div>
  <?php endif; ?>

  <div class="card mt-3">
    <div class="card-body">
      <div><strong>Ticket:</strong> <?= htmlspecialchars($entrega['ticket'] ?? '0') ?></div>
      <div><strong>Detalle:</strong> <?= htmlspecialchars($entrega['detalle'] ?? '') ?></div>
      <div><strong>Estado voucher:</strong> <?= htmlspecialchars($entrega['estado']) ?></div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-body">
      <h5>Detalle entregado</h5>
      <div class="table-responsive">
        <table class="table table-sm table-bordered">
          <thead class="table-light">
            <tr><th>Producto</th><th>Ubicación</th><th class="text-end">Cantidad entregada</th></tr>
          </thead>
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
      </div>

      <hr>

      <h5>Firma Usuario</h5>

      <?php if (!empty($entrega['usuario_firma_path'])): ?>
        <div class="alert alert-info">✅ Ya firmaste este comprobante.</div>
        <img src="<?= htmlspecialchars($entrega['usuario_firma_path']) ?>" style="max-width:520px; width:100%; border:1px solid #ddd; border-radius:8px;">
      <?php elseif ($entrega['estado'] !== 'PENDIENTE_USUARIO'): ?>
        <div class="alert alert-warning">Aún no está listo para tu firma (falta firma admin).</div>
      <?php else: ?>
        <form method="post" onsubmit="return prepararFirma();">
          <div class="mb-2">
            <label class="form-label">Observación (opcional)</label>
            <input type="text" name="observacion_usuario" class="form-control" maxlength="255">
          </div>

          <p class="text-muted small mb-2">Firma dentro del recuadro:</p>
          <canvas id="canvas" width="520" height="180"></canvas>
          <input type="hidden" name="firma_base64" id="firma_base64">

          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="limpiar()">Limpiar</button>
            <button type="submit" class="btn btn-primary btn-sm">Guardar firma</button>
          </div>
        </form>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas?.getContext('2d');
let dibujando = false;

function pos(e){
  const r = canvas.getBoundingClientRect();
  const x = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
  const y = (e.touches ? e.touches[0].clientY : e.clientY) - r.top;
  return {x, y};
}

function start(e){ dibujando=true; const p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); e.preventDefault(); }
function move(e){
  if(!dibujando) return;
  const p=pos(e);
  ctx.lineWidth=2.2;
  ctx.lineCap='round';
  ctx.lineTo(p.x,p.y);
  ctx.stroke();
  e.preventDefault();
}
function end(){ dibujando=false; }

function limpiar(){ ctx.clearRect(0,0,canvas.width,canvas.height); }
function prepararFirma(){
  document.getElementById('firma_base64').value = canvas.toDataURL('image/png');
  return true;
}

if(canvas){
  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  window.addEventListener('mouseup', end);
  canvas.addEventListener('touchstart', start, {passive:false});
  canvas.addEventListener('touchmove', move, {passive:false});
  canvas.addEventListener('touchend', end);
}
</script>
</body>
</html>
