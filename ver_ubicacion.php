<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$ubicacionOriginal = $_GET['ubicacion'] ?? '';
$ubicacion = strtoupper(trim($ubicacionOriginal));
$ubicacion = preg_replace('/\s+/', '', $ubicacion); // quitar espacios

// --- Helper para encontrar imagen con varias extensiones ---
function findFirstExisting($relativePathWithoutExt, $exts = ['png','jpg','jpeg']) {
    foreach ($exts as $ext) {
        $candidate = $relativePathWithoutExt . '.' . $ext;
        if (file_exists(__DIR__ . '/' . $candidate)) {
            return $candidate;
        }
    }
    return null;
}

// --- Parseo robusto ---
$partes = explode('-', $ubicacion);
$bodegaNum   = null;
$muebleLetra = null;
$horizontal  = null;
$vertical    = null;

// Bodega (acepta B3, b3, B 3, B03, etc.)
if (!empty($partes[0]) && preg_match('/^B\s*0*([0-9]+)/i', $partes[0], $m)) {
    $bodegaNum = (int)$m[1];
}

// Segunda parte: "D1", "HPISO", "F", "F2", "A", "E3", etc.
if (!empty($partes[1])) {
    $p2 = strtoupper(str_replace([' ', '_'], '', $partes[1]));
    // Primera letra SIEMPRE es el mueble/sector
    if (preg_match('/^[A-Z]/', $p2)) {
        $muebleLetra = $p2[0];  // ej: H en HPISO, F en F2, A en A
    }
    // Dígitos consecutivos tras la letra, si existen, son la "horizontal"
    if (preg_match('/^[A-Z](\d+)/', $p2, $mm)) {
        $horizontal = (int)$mm[1];
    }
}

// Tercera parte si es numérica => vertical/gabeta
if (isset($partes[2]) && preg_match('/^\d+$/', $partes[2])) {
    $vertical = (int)$partes[2];
}

// --- Resolución de imágenes ---
$assetsBase      = 'assets/';
$imgVistaGeneral = findFirstExisting($assetsBase . 'vista-general');

// Bodega: intenta bodega-<n>.(png|jpg|jpeg); si no hay, usa vista-general
$imgBodega = $bodegaNum ? findFirstExisting($assetsBase . 'bodega-' . $bodegaNum) : null;
if (!$imgBodega) {
    $imgBodega = $imgVistaGeneral ?: null;
}

// Detalle (mueble o sector)
$imgDetalle = null;
if ($muebleLetra) {
    $letra = strtolower($muebleLetra);
    $candidatosBase = [];

    // Reglas especiales
    if ($muebleLetra === 'A') {
        // Sector A (no mueble), usar el plano de sector llamado mueble-a.png
        $candidatosBase = [$assetsBase . 'mueble-a'];
    } elseif ($muebleLetra === 'F') {
        if ($horizontal !== null) {
            // F con número => mueble F
            $candidatosBase = [$assetsBase . 'mueble-f'];
        } else {
            // F solo => sector F (con fallback a mueble-f si no existiera)
            $candidatosBase = [$assetsBase . 'sector-f', $assetsBase . 'mueble-f'];
        }
    } elseif ($muebleLetra === 'H') {
        // HPISO o H => mostrar mueble H
        $candidatosBase = [$assetsBase . 'mueble-h'];
    } else {
        // Por defecto: mueble <letra>
        $candidatosBase = [$assetsBase . 'mueble-' . $letra];
    }

    // Busca el primer archivo existente entre las bases y extensiones
    foreach ($candidatosBase as $basePath) {
        $found = findFirstExisting($basePath);
        if ($found) { $imgDetalle = $found; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Ubicación: <?= htmlspecialchars($ubicacionOriginal ?: 'Sin dato') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid position-relative d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <img src="assets/logo.png" alt="Sonda Logo" height="120">
        </div>
        <div class="position-absolute start-50 translate-middle-x">
            <span class="navbar-brand h5 mb-0 text-white text-center">
                Ubicación: <?= htmlspecialchars($ubicacionOriginal ?: 'Sin dato') ?>
            </span>
        </div>
        <div class="d-flex align-items-center text-white">
            <span class="me-3">👤 <?= htmlspecialchars($_SESSION['user']) ?> (<?= htmlspecialchars($_SESSION['role'] ?? '') ?>)</span>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">Volver</a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <?php if (!$ubicacionOriginal): ?>
        <div class="alert alert-warning">No se entregó un código de ubicación.</div>
    <?php else: ?>
        <div class="row g-4">
            <!-- Vista general / Bodega -->
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <strong>Vista general<?= $bodegaNum ? " • Bodega {$bodegaNum}" : '' ?></strong>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($imgBodega): ?>
                            <img src="<?= $imgBodega ?>" class="img-fluid" alt="Bodega" style="max-height:70vh;">
                        <?php else: ?>
                            <div class="alert alert-secondary mb-0">No hay imagen de bodega disponible.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Detalle: Mueble o Sector -->
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <strong>
                            <?= $muebleLetra ? "Detalle • {$muebleLetra}" : "Detalle" ?>
                            <?php if ($horizontal !== null): ?> • Horizontal <?= (int)$horizontal ?><?php endif; ?>
                            <?php if ($vertical !== null): ?> • Vertical <?= (int)$vertical ?><?php endif; ?>
                        </strong>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($imgDetalle): ?>
                            <img src="<?= $imgDetalle ?>" class="img-fluid" alt="Detalle" style="max-height:70vh;">
                        <?php else: ?>
                            <div class="alert alert-secondary mb-0">
                                No hay imagen específica para esta ubicación (<?= htmlspecialchars($muebleLetra ?? '-') ?>).
                            </div>
                        <?php endif; ?>

                        <div class="mt-3">
                            <span class="badge bg-primary">Código: <?= htmlspecialchars($ubicacionOriginal) ?></span>
                            <?php if ($muebleLetra): ?>
                                <span class="badge bg-info text-dark">Mueble/Sector: <?= htmlspecialchars($muebleLetra) ?></span>
                            <?php endif; ?>
                            <?php if ($horizontal !== null): ?>
                                <span class="badge bg-success">Horizontal: <?= (int)$horizontal ?></span>
                            <?php endif; ?>
                            <?php if ($vertical !== null): ?>
                                <span class="badge bg-warning text-dark">Vertical: <?= (int)$vertical ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<footer class="bg-light text-center text-muted py-3 mt-5 border-top">
    Desarrollado por Jader Muñoz
</footer>

</body>
</html>
