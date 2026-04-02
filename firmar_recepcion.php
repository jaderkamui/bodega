<?php
session_start();
if (!isset($_SESSION['user'])) { header("Location: index.php"); exit; }
require 'config/db.php';

$userId = (int)$_SESSION['user_id'];
$token = trim($_GET['token'] ?? '');
if ($token === '') die("Token inválido.");

function crearCarpetaFirmas(): string {
    $dir = __DIR__ . "/uploads/firmas";
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir;
}
function guardarFirmaPng(string $base64, string $prefix): string {
    if (preg_match('/^data:image\/png;base64,/', $base64)) {
        $base64 = substr($base64, strpos($base64, ',') + 1);
    }
    $bin = base64_decode($base64);
    if ($bin === false) throw new Exception("Firma inválida.");
    $dir = crearCarpetaFirmas();
    $filename = $prefix . "_" . time() . ".png";
    file_put_contents($dir . "/" . $filename, $bin);
    return "uploads/firmas/" . $filename;
}

// Buscar entrega por token
$stmt = $conn->prepare("SELECT * FROM solicitud_entregas WHERE token_usuario = ?");
$stmt->execute([$token]);
$entrega = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$entrega) die("Voucher no encontrado.");

$solicitudId = (int)$entrega['solicitud_id'];
$adminId = (int)$entrega['admin_user_id'];

// seguridad: solo el usuario dueño puede firmar
$stmt = $conn->prepare("SELECT user_id, ticket, detalle FROM solicitudes WHERE id=?");
$stmt->execute([$solicitudId]);
$sol = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sol || (int)$sol['user_id'] !== $userId) die("Sin permisos para firmar este voucher.");

$mensaje = "";

// Regla: solo firmar si el voucher está listo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['firma_base64'])) {
    try {
        if (($entrega['estado'] ?? '') !== 'PENDIENTE_USUARIO') {
            throw new Exception("Este voucher aún no está listo para tu firma.");
        }

        $receptorNombre = trim($_POST['receptor_nombre'] ?? '');
        $receptorRut    = trim($_POST['receptor_rut'] ?? '');
        if ($receptorNombre === '' || $receptorRut === '') {
            throw new Exception("Debes ingresar nombre y RUT del receptor.");
        }

        $firmaPath = guardarFirmaPng($_POST['firma_base64'], "receptor_solicitud{$solicitudId}");

        $up = $conn->prepare("
            UPDATE solicitud_entregas
            SET receptor_nombre=?, receptor_rut=?,
                receptor_firma_path=?, receptor_signed_at=NOW(),
                estado='COMPLETADA'
            WHERE token_usuario=?
        ");
        $up->execute([$receptorNombre, $receptorRut, $firmaPath, $token]);

        // Cerrar solicitud
        $conn->prepare("UPDATE solicitudes SET estado_general='Cerrada', updated_at=NOW() WHERE id=?")
             ->execute([$solicitudId]);

        // Notificar admin
        $notif = $conn->prepare("INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at)
                                 VALUES (?, ?, ?, 0, NOW())");
        $notif->execute([
            $adminId,
            "✅ El usuario firmó recepción del voucher (Solicitud #{$solicitudId}).",
            "voucher_entrega.php?solicitud_id={$solicitudId}"
        ]);

        // LOG DE ENTREGA COMPLETADA
        $log = $conn->prepare("
            INSERT INTO logs (user_id, action, details, created_at)
            VALUES (?, 'entrega_registrada', ?, NOW())
        ");
        $log->execute([
            $userId,
            "Entrega completada y firmada por receptor (Solicitud #{$solicitudId}) — Receptor: {$receptorNombre} (RUT: {$receptorRut})"
        ]);

        $mensaje = "✅ Firma registrada. Voucher COMPLETADO.";

        // recargar
        $stmt = $conn->prepare("SELECT * FROM solicitud_entregas WHERE token_usuario = ?");
        $stmt->execute([$token]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        $mensaje = "❌ " . $e->getMessage();
    }
}

// items entregados
$stmt = $conn->prepare("
    SELECT si.*, p.description, p.ubicacion
    FROM solicitud_items si
    JOIN products p ON p.id = si.product_id
    WHERE si.solicitud_id = ? AND si.cant_entregada > 0
    ORDER BY si.id ASC
");
$stmt->execute([$solicitudId]);
$itemsEntregados = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Firmar recepción</title>
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
  <style>
    canvas{border:1px solid #ccc;border-radius:10px;width:100%;max-width:520px;height:180px;}
    .firma-img{max-width:520px;width:100%;border:1px solid #ddd;border-radius:10px;}
    .sign-row{display:flex;gap:12px;flex-wrap:wrap;}
    .sign-col{flex:1;min-width:260px;}
    @media print{
      .no-print{display:none!important;}
      body{background:#fff!important;}
      .sign-row{display:flex !important; gap:12px !important; flex-wrap:nowrap !important;}
      .sign-col{flex:1 !important;}
      img{max-height:85px !important; width:auto !important;}
    }
  </style>
</head>
<body class="bg-light">
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h3 class="mb-0">✍️ Firmar recepción — Solicitud #<?= $solicitudId ?></h3>
    <div class="d-flex gap-2 no-print">
      <a href="mis_solicitudes.php" class="btn btn-secondary btn-sm">Volver</a>
      <button class="btn btn-outline-dark btn-sm" onclick="window.print()">🖨️ Imprimir</button>
    </div>
  </div>

  <?php if ($mensaje): ?>
    <div class="alert <?= str_starts_with($mensaje,'✅') ? 'alert-success' : 'alert-danger' ?> mt-3"><?= htmlspecialchars($mensaje) ?></div>
  <?php endif; ?>

  <div class="card mt-3">
    <div class="card-body">
      <div><strong>Ticket:</strong> <?= htmlspecialchars($sol['ticket'] ?? '0') ?></div>
      <div><strong>Detalle:</strong> <?= htmlspecialchars($sol['detalle'] ?? '') ?></div>
      <div><strong>Estado voucher:</strong> <?= htmlspecialchars($entrega['estado'] ?? '') ?></div>
      <div><strong>ID Voucher:</strong> <?= htmlspecialchars($entrega['id'] ?? '—') ?></div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-body">
      <h5>Ítems entregados</h5>
      <table class="table table-sm table-bordered">
        <thead class="table-light"><tr><th>Producto</th><th>Ubicación</th><th class="text-end">Entregado</th></tr></thead>
        <tbody>
        <?php foreach($itemsEntregados as $it): ?>
          <tr>
            <td><?= htmlspecialchars($it['description']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($it['ubicacion'] ?? '—') ?></td>
            <td class="text-end"><?= (int)$it['cant_entregada'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <hr>
      <h5>Firmas</h5>

      <div class="sign-row">
        <div class="sign-col">
          <div class="text-muted small"><strong>Firma Administrador</strong></div>
          <?php if (!empty($entrega['admin_firma_path'])): ?>
            <img class="firma-img" src="<?= htmlspecialchars($entrega['admin_firma_path']) ?>">
            <div class="text-muted small mt-1">Firmado: <?= htmlspecialchars($entrega['admin_signed_at'] ?? '—') ?></div>
          <?php else: ?>
            <div class="text-muted">Aún no firmada por admin.</div>
          <?php endif; ?>
        </div>

        <div class="sign-col">
          <div class="text-muted small"><strong>Firma Receptor</strong></div>

          <?php if (!empty($entrega['receptor_firma_path'])): ?>
            <div class="alert alert-info">✅ Ya firmaste este voucher.</div>
            <img class="firma-img" src="<?= htmlspecialchars($entrega['receptor_firma_path']) ?>">
            <div class="mt-2"><strong>Receptor:</strong> <?= htmlspecialchars($entrega['receptor_nombre']) ?> — <strong>RUT:</strong> <?= htmlspecialchars($entrega['receptor_rut']) ?></div>
          <?php elseif (($entrega['estado'] ?? '') !== 'PENDIENTE_USUARIO'): ?>
            <div class="alert alert-warning">Aún no está listo para firmar (falta firma del administrador).</div>
          <?php else: ?>
            <form method="post" onsubmit="return prepararFirma();">
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label">Nombre receptor</label>
                  <input type="text" name="receptor_nombre" class="form-control" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">RUT receptor</label>
                  <input type="text" name="receptor_rut" class="form-control" required placeholder="12.345.678-9">
                </div>
              </div>

              <p class="text-muted small mt-3 mb-2">Firma dentro del recuadro:</p>
              <canvas id="canvas" width="520" height="180"></canvas>
              <input type="hidden" name="firma_base64" id="firma_base64">

              <div class="d-flex gap-2 mt-2 no-print">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="limpiar()">Limpiar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar firma recepción</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas?.getContext('2d');
let dibujando=false;

function pos(e){
  const r=canvas.getBoundingClientRect();
  const x=(e.touches?e.touches[0].clientX:e.clientX)-r.left;
  const y=(e.touches?e.touches[0].clientY:e.clientY)-r.top;
  return {x,y};
}
function start(e){ dibujando=true; const p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); e.preventDefault(); }
function move(e){
  if(!dibujando) return;
  const p=pos(e);
  ctx.lineWidth=2.2; ctx.lineCap='round';
  ctx.lineTo(p.x,p.y); ctx.stroke(); e.preventDefault();
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