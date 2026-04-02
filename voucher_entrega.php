<?php
session_start();
if (!isset($_SESSION['user'])) { header("Location: index.php"); exit; }
require 'config/db.php';

$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = $_SESSION['role'] ?? 'lector';
$esAdmin  = ($role === 'admin');

$solicitudId = (int)($_GET['solicitud_id'] ?? 0);
if ($solicitudId <= 0) die("Solicitud inválida.");

/* Marcar notificaciones relacionadas como leídas */
$mark = $conn->prepare("UPDATE notificaciones SET leido = 1
                        WHERE user_id = ? AND link LIKE 'voucher_entrega.php%'");
$mark->execute([$userId]);

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

function badgeColor($estado) {
    $map = [
        "Pendiente"         => "secondary",
        "Aprobada"          => "primary",
        "Rechazada"         => "danger",
        "Entregado"         => "success",
        "PENDIENTE_ADMIN"   => "secondary",
        "PENDIENTE_USUARIO" => "warning",
        "COMPLETADA"        => "success"
    ];
    return $map[$estado] ?? "dark";
}

/* 1) Traer solicitud y validar permisos */
$stmt = $conn->prepare("SELECT s.*, u.username
                        FROM solicitudes s
                        JOIN users u ON u.id = s.user_id
                        WHERE s.id = ?");
$stmt->execute([$solicitudId]);
$sol = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sol) die("Solicitud no encontrada.");

$solicitanteId = (int)$sol['user_id'];
if (!$esAdmin && $solicitanteId !== $userId) die("Sin permisos para ver este voucher.");

/* 2) Items (TODOS) */
$stmt = $conn->prepare("
    SELECT si.*, p.description, p.ubicacion
    FROM solicitud_items si
    JOIN products p ON p.id = si.product_id
    WHERE si.solicitud_id = ?
    ORDER BY si.id ASC
");
$stmt->execute([$solicitudId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Contar estados para resumen y validación (solo 4 estados) */
$tot = count($items);
$cntEnt = 0; $cntRech = 0; $cntNoResueltos = 0;
foreach ($items as $it) {
    $st = $it['estado_item'] ?? 'Pendiente';
    if ($st === 'Entregado') $cntEnt++;
    elseif ($st === 'Rechazada') $cntRech++;
    else $cntNoResueltos++;
}

$todoResuelto = ($cntNoResueltos === 0);

/* 3) Buscar o crear voucher SOLO si es admin Y todo está resuelto */
$stmt = $conn->prepare("SELECT * FROM solicitud_entregas WHERE solicitud_id = ?");
$stmt->execute([$solicitudId]);
$entrega = $stmt->fetch(PDO::FETCH_ASSOC);

$mensaje = "";

if (!$entrega && $esAdmin) {
    if (!$todoResuelto) {
        $mensaje = "⚠️ No se puede crear el comprobante aún: hay $cntNoResueltos ítem(s) en estado Pendiente o Aprobada. Resuélvelos primero en admin_solicitudes.php.";
    } else {
        $token = bin2hex(random_bytes(24));
        $ins = $conn->prepare("
            INSERT INTO solicitud_entregas (solicitud_id, admin_user_id, estado, token_usuario, created_at)
            VALUES (?, ?, 'PENDIENTE_ADMIN', ?, NOW())
        ");
        $ins->execute([$solicitudId, (int)$_SESSION['user_id'], $token]);

        $stmt = $conn->prepare("SELECT * FROM solicitud_entregas WHERE solicitud_id = ?");
        $stmt->execute([$solicitudId]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

/* Helper: voucher ya completado */
$voucherCerrado = (($entrega['estado'] ?? '') === 'COMPLETADA');

/* 4) Guardar firma ADMIN (solo admin) */
if ($esAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='firmar_admin') {
    try {
        if (empty($entrega)) throw new Exception("No existe comprobante para firmar.");
        if ($voucherCerrado) throw new Exception("Este comprobante ya está COMPLETADO.");
        if (!$todoResuelto) throw new Exception("No puedes firmar: hay $cntNoResueltos ítem(s) en estado Pendiente o Aprobada.");

        if (empty($_POST['firma_base64'])) throw new Exception("Debes firmar en el recuadro.");

        $firmaPath = guardarFirmaPng($_POST['firma_base64'], "admin_solicitud{$solicitudId}");

        $up = $conn->prepare("
            UPDATE solicitud_entregas
            SET admin_firma_path = ?, admin_signed_at = NOW(),
                admin_user_id = ?, estado = 'PENDIENTE_USUARIO'
            WHERE solicitud_id = ?
        ");
        $up->execute([$firmaPath, (int)$_SESSION['user_id'], $solicitudId]);

        // Notificar al usuario
        $notif = $conn->prepare("
            INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at)
            VALUES (?, ?, ?, 0, NOW())
        ");
        $notif->execute([
            $solicitanteId,
            "✍️ Comprobante de entrega listo para tu firma (Solicitud #{$solicitudId}).",
            "firmar_recepcion.php?token=" . ($entrega['token_usuario'] ?? '')
        ]);

        $mensaje = "✅ Firma del administrador guardada. Ahora el receptor puede firmar.";

        $stmt = $conn->prepare("SELECT * FROM solicitud_entregas WHERE solicitud_id = ?");
        $stmt->execute([$solicitudId]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

/* 5) Firma receptor asistida por ADMIN (bodeguero) */
if ($esAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='firmar_receptor_admin') {
    try {
        if (empty($entrega)) throw new Exception("No existe comprobante.");
        if ($voucherCerrado) throw new Exception("Este comprobante ya está COMPLETADO.");
        if (($entrega['estado'] ?? '') !== 'PENDIENTE_USUARIO') throw new Exception("Primero debe firmar el administrador.");
        if (!$todoResuelto) throw new Exception("No puedes cerrar: hay $cntNoResueltos ítem(s) en estado Pendiente o Aprobada.");

        $receptorNombre = trim($_POST['receptor_nombre'] ?? '');
        $receptorRut    = trim($_POST['receptor_rut'] ?? '');
        if ($receptorNombre === '' || $receptorRut === '') throw new Exception("Debes ingresar nombre y RUT del receptor.");

        if (empty($_POST['receptor_firma_base64'])) throw new Exception("El receptor debe firmar en el recuadro.");

        $firmaPath = guardarFirmaPng($_POST['receptor_firma_base64'], "receptor_solicitud{$solicitudId}");

        $up = $conn->prepare("
            UPDATE solicitud_entregas
            SET receptor_nombre=?, receptor_rut=?,
                receptor_firma_path=?, receptor_signed_at=NOW(),
                estado='COMPLETADA'
            WHERE solicitud_id=?
        ");
        $up->execute([$receptorNombre, $receptorRut, $firmaPath, $solicitudId]);

        // Cerrar solicitud definitivamente
        $conn->prepare("UPDATE solicitudes SET estado_general='Cerrada', updated_at=NOW() WHERE id=?")
             ->execute([$solicitudId]);

        // Notificar al solicitante
        $notif = $conn->prepare("
            INSERT INTO notificaciones (user_id, mensaje, link, leido, created_at)
            VALUES (?, ?, ?, 0, NOW())
        ");
        $notif->execute([
            $solicitanteId,
            "✅ Comprobante de entrega COMPLETADO y firmado (Solicitud #{$solicitudId}).",
            "voucher_entrega.php?solicitud_id={$solicitudId}"
        ]);

        $mensaje = "✅ Firma del receptor guardada. Comprobante COMPLETADO y solicitud cerrada.";

        $stmt = $conn->prepare("SELECT * FROM solicitud_entregas WHERE solicitud_id = ?");
        $stmt->execute([$solicitudId]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);
        $voucherCerrado = true;

    } catch (Throwable $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

/* Items entregados para resumen */
$itemsEntregados = array_values(array_filter($items, fn($it) => (int)($it['cant_entregada'] ?? 0) > 0));
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Comprobante de Entrega — Solicitud #<?= $solicitudId ?></title>
  <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
  <style>
    body { background:#f8f9fa; }
    .voucher-wrap { max-width: 1000px; margin: 0 auto; }
    .firma-img { max-height: 100px; width: auto; border:1px solid #dee2e6; border-radius:8px; }
    .small-muted { font-size:0.9rem; color:#6c757d; }
    .alert-pending { background-color: #fff3cd; border-color: #ffeeba; color: #856404; }
    .solicitud-header { background:#e9ecef; padding:1rem; border-radius:0.5rem; margin-bottom:1rem; }
    @media print {
      @page { size: A4; margin: 12mm; }
      .no-print { display:none !important; }
      body { background:#fff !important; font-size:10pt; }
      .card { border:1px solid #000 !important; box-shadow:none !important; margin:0 !important; }
      .solicitud-header { background:#f0f0f0 !important; border:1px solid #000; }
      .voucher-wrap { margin:0; padding:0; width:100%; }
    }
  </style>
</head>
<body>

<div class="container mt-4 voucher-wrap">

  <!-- Encabezado visible siempre (pantalla e impresión) -->
  <div class="solicitud-header text-center">
    <h3 class="mb-1">Comprobante de Entrega</h3>
    <h4 class="mb-0">Solicitud #<?= $solicitudId ?></h4>
    <small class="text-muted">Fecha: <?= htmlspecialchars($sol['created_at'] ?? date('Y-m-d H:i')) ?></small>
  </div>

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 no-print mb-3">
    <h5 class="mb-0">Solicitud #<?= $solicitudId ?></h5>
    <div class="d-flex gap-2">
      <a href="<?= $esAdmin ? 'admin_solicitudes.php' : 'mis_solicitudes.php' ?>" class="btn btn-secondary btn-sm">Volver</a>
      <button class="btn btn-outline-dark btn-sm" onclick="window.print()">🖨️ Imprimir</button>
    </div>
  </div>

  <?php if ($mensaje): ?>
    <div class="alert <?= strpos($mensaje, '✅') === 0 ? 'alert-success' : 'alert-danger' ?> mt-3">
      <?= htmlspecialchars($mensaje) ?>
    </div>
  <?php endif; ?>

  <?php if (!$todoResuelto): ?>
    <div class="alert alert-warning alert-pending mt-3">
      <strong>⚠️ Atención:</strong> Hay <strong><?= $cntNoResueltos ?></strong> ítem(s) en estado <strong>Pendiente</strong> o <strong>Aprobada</strong>.<br>
      No se puede firmar ni completar este comprobante hasta que todos estén en <strong>Entregado</strong> o <strong>Rechazada</strong>.
    </div>
  <?php endif; ?>

  <div class="card mt-3">
    <div class="card-body">
      <div class="d-flex justify-content-between flex-wrap gap-3">
        <div>
          <strong>Solicitante:</strong> <?= htmlspecialchars($sol['username']) ?><br>
          <strong>Ticket:</strong> <?= htmlspecialchars($sol['ticket'] ?? '0') ?><br>
          <strong>Detalle:</strong> <?= htmlspecialchars($sol['detalle'] ?? '—') ?>
        </div>
        <div class="text-end">
          <strong>ID Solicitud:</strong> #<?= $solicitudId ?><br>
          <strong>Estado del comprobante:</strong><br>
          <span class="badge bg-<?= badgeColor($entrega['estado'] ?? 'SIN_VOUCHER') ?> fs-6 px-3 py-2">
            <?= htmlspecialchars($entrega['estado'] ?? 'SIN COMPROBANTE') ?>
          </span><br>
          <div class="small-muted mt-1">
            Ítems totales: <?= $tot ?>  
            · Entregados: <?= $cntEnt ?>  
            · Rechazados: <?= $cntRech ?>  
            · Pendientes/Aprobados: <?= $cntNoResueltos ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla de ítems -->
  <div class="card mt-3">
    <div class="card-body">
      <h5>Detalle de ítems (Solicitud #<?= $solicitudId ?>)</h5>
      <?php if (empty($items)): ?>
        <div class="alert alert-info">No hay ítems en esta solicitud.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-bordered">
            <thead class="table-light">
              <tr>
                <th>Producto</th>
                <th>Ubicación</th>
                <th class="text-end">Solicitado</th>
                <th class="text-end">Aprobado</th>
                <th class="text-end">Entregado</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($items as $it): 
                $st = $it['estado_item'] ?? 'Pendiente';
              ?>
                <tr>
                  <td><?= htmlspecialchars($it['description'] ?? $it['producto'] ?? '—') ?></td>
                  <td class="text-muted"><?= htmlspecialchars($it['ubicacion'] ?? '—') ?></td>
                  <td class="text-end"><?= (int)($it['cant_solicitada'] ?? 0) ?></td>
                  <td class="text-end"><?= (int)($it['cant_aprobada'] ?? 0) ?></td>
                  <td class="text-end"><?= (int)($it['cant_entregada'] ?? 0) ?></td>
                  <td><span class="badge bg-<?= badgeColor($st) ?>"><?= htmlspecialchars($st) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Sección de firmas -->
  <?php if ($entrega): ?>
  <div class="card mt-3">
    <div class="card-body">
      <h5>Firmas del comprobante — Solicitud #<?= $solicitudId ?></h5>

      <div class="row g-4">
        <!-- Firma Administrador -->
        <div class="col-md-6">
          <div class="border p-3 rounded bg-white">
            <h6 class="mb-2"><strong>Firma Administrador</strong></h6>
            <?php if (!empty($entrega['admin_firma_path'])): ?>
              <img src="<?= htmlspecialchars($entrega['admin_firma_path']) ?>" class="firma-img mb-2" alt="Firma admin">
              <div class="small-muted">Firmado: <?= htmlspecialchars($entrega['admin_signed_at'] ?? '—') ?></div>
            <?php elseif ($esAdmin && !$voucherCerrado): ?>
              <?php if (!$todoResuelto): ?>
                <div class="alert alert-warning small">No disponible: hay ítems en Pendiente o Aprobada.</div>
              <?php else: ?>
                <form method="post" onsubmit="return prepararFirmaAdmin();">
                  <input type="hidden" name="accion" value="firmar_admin">
                  <canvas id="canvasAdmin" width="480" height="160" style="border:1px solid #ccc; border-radius:8px; width:100%;"></canvas>
                  <input type="hidden" name="firma_base64" id="firma_admin_base64">
                  <div class="d-flex gap-2 mt-2 no-print">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="limpiarAdmin()">Limpiar</button>
                    <button type="submit" class="btn btn-primary btn-sm">Firmar como administrador</button>
                  </div>
                </form>
              <?php endif; ?>
            <?php else: ?>
              <div class="text-muted">Aún no firmada por el administrador.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Firma Receptor -->
        <div class="col-md-6">
          <div class="border p-3 rounded bg-white">
            <h6 class="mb-2"><strong>Firma Receptor</strong></h6>
            <?php if (!empty($entrega['receptor_firma_path'])): ?>
              <img src="<?= htmlspecialchars($entrega['receptor_firma_path']) ?>" class="firma-img mb-2" alt="Firma receptor">
              <div class="small-muted">Firmado: <?= htmlspecialchars($entrega['receptor_signed_at'] ?? '—') ?></div>
              <div><strong>Receptor:</strong> <?= htmlspecialchars($entrega['receptor_nombre'] ?? '—') ?>  
                — <strong>RUT:</strong> <?= htmlspecialchars($entrega['receptor_rut'] ?? '—') ?></div>
            <?php elseif ($esAdmin && ($entrega['estado'] ?? '') === 'PENDIENTE_USUARIO'): ?>
              <?php if (!$todoResuelto): ?>
                <div class="alert alert-warning small">No disponible: hay ítems en Pendiente o Aprobada.</div>
              <?php else: ?>
                <div class="alert alert-info small mb-2">
                  Puedes registrar la firma del receptor aquí mismo (asistida en bodega).
                </div>
                <form method="post" onsubmit="return prepararFirmaReceptor();">
                  <input type="hidden" name="accion" value="firmar_receptor_admin">
                  <div class="row g-2 mb-2">
                    <div class="col-6">
                      <input type="text" name="receptor_nombre" class="form-control form-control-sm" placeholder="Nombre receptor" required>
                    </div>
                    <div class="col-6">
                      <input type="text" name="receptor_rut" class="form-control form-control-sm" placeholder="RUT (ej: 12.345.678-9)" required>
                    </div>
                  </div>
                  <canvas id="canvasReceptor" width="480" height="160" style="border:1px solid #ccc; border-radius:8px; width:100%;"></canvas>
                  <input type="hidden" name="receptor_firma_base64" id="firma_receptor_base64">
                  <div class="d-flex gap-2 mt-2 no-print">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="limpiarReceptor()">Limpiar</button>
                    <button type="submit" class="btn btn-success btn-sm">Guardar firma receptor y cerrar</button>
                  </div>
                </form>
              <?php endif; ?>
            <?php else: ?>
              <div class="text-muted">Aún no firmada por el receptor.</div>
              <?php if (!$esAdmin && ($entrega['estado'] ?? '') === 'PENDIENTE_USUARIO' && !empty($entrega['token_usuario'])): ?>
                <a href="firmar_recepcion.php?token=<?= urlencode($entrega['token_usuario']) ?>" class="btn btn-primary btn-sm mt-2 no-print">
                  ✍️ Firmar como receptor
                </a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
// Canvas Admin
const canvasAdmin = document.getElementById('canvasAdmin');
if (canvasAdmin) {
  const ctxA = canvasAdmin.getContext('2d');
  let drawingA = false;
  function posA(e) {
    const r = canvasAdmin.getBoundingClientRect();
    return { x: (e.touches ? e.touches[0].clientX : e.clientX) - r.left, y: (e.touches ? e.touches[0].clientY : e.clientY) - r.top };
  }
  function startA(e){ drawingA=true; const p=posA(e); ctxA.beginPath(); ctxA.moveTo(p.x,p.y); e.preventDefault(); }
  function moveA(e){ if(!drawingA) return; const p=posA(e); ctxA.lineWidth=2.5; ctxA.lineCap='round'; ctxA.lineTo(p.x,p.y); ctxA.stroke(); e.preventDefault(); }
  function endA(){ drawingA=false; }
  function clearA(){ ctxA.clearRect(0,0,canvasAdmin.width,canvasAdmin.height); }
  function saveA(){ document.getElementById('firma_admin_base64').value = canvasAdmin.toDataURL('image/png'); return true; }

  canvasAdmin.addEventListener('mousedown', startA);
  canvasAdmin.addEventListener('mousemove', moveA);
  window.addEventListener('mouseup', endA);
  canvasAdmin.addEventListener('touchstart', startA, {passive:false});
  canvasAdmin.addEventListener('touchmove', moveA, {passive:false});
  canvasAdmin.addEventListener('touchend', endA);
  document.querySelector('[onclick="limpiarAdmin()"]')?.addEventListener('click', clearA);
  document.querySelector('form[onsubmit="return prepararFirmaAdmin();"]')?.addEventListener('submit', saveA);
}

// Canvas Receptor
const canvasReceptor = document.getElementById('canvasReceptor');
if (canvasReceptor) {
  const ctxR = canvasReceptor.getContext('2d');
  let drawingR = false;
  function posR(e) {
    const r = canvasReceptor.getBoundingClientRect();
    return { x: (e.touches ? e.touches[0].clientX : e.clientX) - r.left, y: (e.touches ? e.touches[0].clientY : e.clientY) - r.top };
  }
  function startR(e){ drawingR=true; const p=posR(e); ctxR.beginPath(); ctxR.moveTo(p.x,p.y); e.preventDefault(); }
  function moveR(e){ if(!drawingR) return; const p=posR(e); ctxR.lineWidth=2.5; ctxR.lineCap='round'; ctxR.lineTo(p.x,p.y); ctxR.stroke(); e.preventDefault(); }
  function endR(){ drawingR=false; }
  function clearR(){ ctxR.clearRect(0,0,canvasReceptor.width,canvasReceptor.height); }
  function saveR(){ document.getElementById('firma_receptor_base64').value = canvasReceptor.toDataURL('image/png'); return true; }

  canvasReceptor.addEventListener('mousedown', startR);
  canvasReceptor.addEventListener('mousemove', moveR);
  window.addEventListener('mouseup', endR);
  canvasReceptor.addEventListener('touchstart', startR, {passive:false});
  canvasReceptor.addEventListener('touchmove', moveR, {passive:false});
  canvasReceptor.addEventListener('touchend', endR);
  document.querySelector('[onclick="limpiarReceptor()"]')?.addEventListener('click', clearR);
  document.querySelector('form[onsubmit="return prepararFirmaReceptor();"]')?.addEventListener('submit', saveR);
}
</script>

</body>
</html>