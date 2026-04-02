<?php
session_start();
if (!isset($_SESSION['user'])) { header("Location: index.php"); exit; }
require 'config/db.php';

$rol         = $_SESSION['role'] ?? 'lector';
$puedeEditar = in_array($rol, ['admin','editor']);
$areaUsuario = $_SESSION['area'] ?? null;
$divisionName = $_SESSION['division_name'] ?? null;

// Notificaciones no leídas
$userId = $_SESSION['user_id'];
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$userId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);

$mensaje   = '';
$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barcode = trim($_POST['barcode'] ?? '');
    $accion  = $_POST['action'] ?? 'search';
    $cantidad = isset($_POST['cantidad']) && is_numeric($_POST['cantidad']) ? (int)$_POST['cantidad'] : 1;

    if ($barcode === '') {
        $mensaje = 'Escanee o escriba un código válido.';
    } else {
        // Buscar producto
        if ($rol === 'admin') {
            $stmt = $conn->prepare("SELECT * FROM products WHERE barcode = ?");
            $stmt->execute([$barcode]);
        } else {
            $stmt = $conn->prepare("SELECT * FROM products WHERE barcode = ? AND area = ?");
            $stmt->execute([$barcode, $areaUsuario]);
        }
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        // Incrementar stock (solo admin/editor)
        if ($puedeEditar && in_array($accion, ['increment', 'increment_custom'])) {
            if ($prod) {
                $oldQty = (int)$prod['quantity'];
                $delta  = ($accion === 'increment') ? 1 : max(1, $cantidad);
                $newQty = $oldQty + $delta;

                $upd = $conn->prepare("UPDATE products SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$delta, $prod['id']]);

                // Log unificado
                if (!empty($_SESSION['user_id'])) {
                    $descLog = "Incremento por escaneo ({$delta}) al producto ID {$prod['id']}: {$prod['description']} [{$barcode}]";
                    $log = $conn->prepare("
                        INSERT INTO logs (user_id, action, details, old_value, new_value, created_at)
                        VALUES (?, 'product_incremented', ?, ?, ?, NOW())
                    ");
                    $log->execute([$_SESSION['user_id'], $descLog, $oldQty, $newQty]);
                }

                $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
                $stmt->execute([$prod['id']]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);

                $mensaje = "✅ Se sumaron {$delta} unidad(es) a «{$prod['description']}». Stock actual: {$newQty}.";
            } else {
                $mensaje = '⚠️ Ese código no existe, primero crea el producto.';
            }
        }

        // Solicitud de retiro
        if ($accion === 'request' && $prod) {
            $req = $conn->prepare("
                INSERT INTO requests (user_id, product_id, quantity, status, created_at)
                VALUES (?, ?, 1, 'pendiente', NOW())
            ");
            $req->execute([$_SESSION['user_id'], $prod['id']]);

            $mensaje = "📩 Se generó solicitud de retiro del producto «{$prod['description']}».";
        }

        $resultado = [
            'barcode'  => $barcode,
            'producto' => $prod
        ];
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Buscar por código de barras - Bodega</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css">
    <style>
        body { background-color: #f5f5f5; }
        .navbar-brand { font-size: 1.1rem; }
        #logo-egg { height: 72px; cursor: pointer; }
        @media (max-width: 576px) {
            #logo-egg { height: 52px; }
            .navbar-brand { font-size: 0.95rem; }
        }
        .noti-dropdown { max-height: 300px; overflow-y: auto; }
        .scanner-wrapper { max-width: 520px; margin: 0 auto; }
        .scanner-card { border-radius: 0.85rem; box-shadow: 0 3px 12px rgba(0,0,0,0.08); }
        .scanner-label { font-weight: 600; font-size: 0.95rem; }
        #barcode { font-size: 1.2rem; padding-block: 0.6rem; text-align: center; letter-spacing: 0.05em; }
        .action-buttons { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        @media (max-width: 576px) {
            .action-buttons { flex-direction: column; }
            .action-buttons .btn { width: 100%; }
        }
        .result-card { border-radius: 0.85rem; }
        .big { font-size: 1.05rem; }
        #gameOverlay {
            position: fixed; inset: 0; background: #111; display: none; z-index: 9999; padding: 10px;
        }
        #gameCanvas {
            display: block; margin: 0 auto; background: #202225; width: 100%; max-width: 900px;
            height: 70vh; border: 2px solid #333; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,.5);
        }
        #gameUI { color: #eee; text-align: center; margin-top: 12px; font-family: system-ui, sans-serif; }
        #gameUI .hint { opacity: .8; font-size: 0.95rem; }
        #closeGameBtn {
            position: absolute; top: 16px; right: 16px; border: 0; background: #dc3545; color: #fff;
            border-radius: 8px; padding: 6px 10px; font-size: 0.9rem;
        }
        .modal-content { border-radius: 0.85rem; }
    </style>
</head>
<body class="bg-light">

<!-- NAVBAR -->
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img id="logo-egg" src="assets/logo.png" alt="Sonda Logo">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega</span>
        <div class="d-flex align-items-center">
            <span class="me-3 text-white text-end">
                <?= htmlspecialchars($_SESSION['user']) ?>
                <small>
                    (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                    <?php if ($divisionName): ?>
                        — <span class="badge text-bg-secondary"><?= htmlspecialchars($divisionName) ?></span>
                    <?php endif; ?>
                </small>
            </span>

            <div class="dropdown me-2">
                <button class="btn btn-outline-light btn-sm position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    🔔
                    <?php if ($notiCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $notiCount ?>
                        </span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end noti-dropdown">
                    <?php if ($notiCount === 0): ?>
                        <li><span class="dropdown-item-text text-muted">No tienes notificaciones nuevas</span></li>
                    <?php else: foreach ($notificaciones as $n): ?>
                        <li>
                            <a class="dropdown-item" href="<?= htmlspecialchars($n['link']) ?>">
                                <?= htmlspecialchars($n['mensaje']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($n['created_at']) ?></small>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                    <?php endforeach; endif; ?>
                    <li><a class="dropdown-item text-center" href="ver_notificaciones.php">📜 Ver todas</a></li>
                </ul>
            </div>

            <a href="dashboard.php" class="btn btn-outline-light btn-sm me-1">🏠</a>
            <a href="logout.php" class="btn btn-outline-light btn-sm">⏻</a>
        </div>
    </div>
</nav>

<div class="container py-3">
    <div class="scanner-wrapper">

        <?php if ($mensaje): ?>
            <div class="alert alert-info py-2"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <!-- Tarjeta de escaneo -->
        <div class="card scanner-card mb-3">
            <div class="card-body">
                <form method="post" class="big">
                    <label class="form-label scanner-label">Código de barras</label>
                    <input id="barcode" name="barcode" class="form-control form-control-lg"
                           value="<?= htmlspecialchars($resultado['barcode'] ?? '') ?>" autofocus>

                    <small class="text-muted d-block mt-1">
                        Apunte con la PDA Zebra y escanee directamente en este campo.
                    </small>

                    <div class="action-buttons mt-3">
                        <button class="btn btn-primary" name="action" value="search">
                            🔍 Buscar
                        </button>

                        <?php if ($puedeEditar): ?>
                            <button name="action" value="increment" class="btn btn-success">
                                ➕ Sumar 1
                            </button>

                            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#sumarCustomModal">
                                ➕ Sumar cantidad...
                            </button>
                        <?php endif; ?>

                        <?php if ($rol === 'lector' || $rol === 'editor'): ?>
                            <button name="action" value="request" class="btn btn-warning">
                                📩 Solicitar Retiro
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal para sumar cantidad personalizada -->
        <?php if ($puedeEditar): ?>
        <div class="modal fade" id="sumarCustomModal" tabindex="-1" aria-labelledby="sumarCustomModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="sumarCustomModalLabel">Sumar cantidad al producto</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post">
                        <div class="modal-body">
                            <input type="hidden" name="barcode" id="modalBarcode" value="<?= htmlspecialchars($resultado['barcode'] ?? '') ?>">
                            <input type="hidden" name="action" value="increment_custom">
                            <label class="form-label">Cantidad a sumar</label>
                            <input type="number" name="cantidad" class="form-control" min="1" value="1" required autofocus>
                            <small class="text-muted mt-2 d-block">
                                Ingresa cuántas unidades deseas sumar al stock actual.
                            </small>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-success">Sumar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Resultado -->
        <?php if ($resultado): ?>
            <?php if ($resultado['producto']):
                $p = $resultado['producto']; ?>
                <div class="card shadow-sm result-card">
                    <div class="card-header"><strong>Resultado</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <div><strong>Descripción:</strong> <?= htmlspecialchars($p['description']) ?></div>
                                <div><strong>Código:</strong> <?= htmlspecialchars($p['barcode']) ?></div>
                                <div><strong>Área:</strong> <?= htmlspecialchars($p['area']) ?></div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div><strong>Cantidad actual:</strong> <?= (int)$p['quantity'] ?></div>
                                <div><strong>Ubicación:</strong> <?= htmlspecialchars($p['ubicacion']) ?></div>
                            </div>
                        </div>
                        <div class="mt-3 d-flex flex-wrap gap-2">
                            <a class="btn btn-outline-info btn-sm" href="ver_ubicacion.php?ubicacion=<?= urlencode($p['ubicacion']) ?>">
                                Ver ubicación
                            </a>
                            <?php if ($puedeEditar): ?>
                                <a class="btn btn-warning btn-sm" href="editar_producto.php?id=<?= (int)$p['id'] ?>">
                                    ✏️ Editar
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mt-2">
                    No existe producto con el código <strong><?= htmlspecialchars($resultado['barcode']) ?></strong>.
                    <?php if ($puedeEditar): ?>
                        <a class="btn btn-sm btn-primary ms-2"
                           href="agregar_producto.php?barcode=<?= urlencode($resultado['barcode']) ?>">
                            Crear producto con este código
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <a href="dashboard.php" class="btn btn-secondary btn-sm mt-3 w-100">⬅ Volver al Dashboard</a>
    </div>
</div>

<!-- Overlay del juego -->
<div id="gameOverlay" aria-hidden="true">
    <button id="closeGameBtn" type="button" title="Salir (Esc)">Salir ✖</button>
    <canvas id="gameCanvas" width="900" height="600"></canvas>
    <div id="gameUI">
        <div class="hint">Mover: ← → | Disparar: Espacio | Salir: ESC</div>
        <div id="gameScore" class="mt-1">Puntaje: 0</div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
// Mantener foco en #barcode SOLO si NO hay modal abierto ni juego
const inp = document.getElementById('barcode');
function focusScanner() {
    const overlayVisible = document.getElementById('gameOverlay').style.display === 'block';
    const modalVisible = document.querySelector('.modal.show') !== null;

    if (!overlayVisible && !modalVisible && inp) {
        inp.focus();
        inp.select();
    }
}
window.addEventListener('load', focusScanner);
document.addEventListener('click', focusScanner);
document.addEventListener('touchend', focusScanner);

// Actualizar barcode en el modal cuando se abre
const sumarModal = document.getElementById('sumarCustomModal');
if (sumarModal) {
    sumarModal.addEventListener('show.bs.modal', function () {
        const barcodeVal = inp ? inp.value.trim() : '';
        document.getElementById('modalBarcode').value = barcodeVal;
        // Foco al input del modal
        setTimeout(() => {
            const cantidadInput = sumarModal.querySelector('input[name="cantidad"]');
            if (cantidadInput) cantidadInput.focus();
        }, 300);
    });
}

// Huevo de pascua: 5 clics en logo
(function() {
    const logo = document.getElementById('logo-egg');
    let clickCount = 0, timer = null;
    if (!logo) return;
    logo.addEventListener('click', () => {
        clickCount++;
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => { clickCount = 0; }, 1200);
        if (clickCount >= 5) {
            clickCount = 0;
            openGame();
        }
    });
})();

// Resto del juego (sin cambios) ...
// (copia aquí el código del juego que ya tenías, desde let canvas... hasta el final de loop())

// Juego Asteroids (sin cambios)
let canvas, ctx, W, H;
let player, bullets, enemies, score, running, gameOver;
let keys = {};

function openGame() {
    document.getElementById('gameOverlay').style.display = 'block';
    startGame();
}

function closeGame() {
    document.getElementById('gameOverlay').style.display = 'none';
    stopGame();
    setTimeout(focusScanner, 80);
}
document.getElementById('closeGameBtn').addEventListener('click', closeGame);

function startGame() {
    canvas = document.getElementById('gameCanvas');
    ctx = canvas.getContext('2d');
    W = canvas.width; H = canvas.height;

    player = { x: W/2, y: H-50, angle: 0, size: 20, speed: 6 };
    bullets = [];
    enemies = [];
    score = 0;
    gameOver = false;
    running = true;

    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('keyup', onKeyUp);

    spawnEnemy();
    loop();
}

function stopGame() {
    running = false;
    document.removeEventListener('keydown', onKeyDown);
    document.removeEventListener('keyup', onKeyUp);
}

function onKeyDown(e) {
    if (['ArrowLeft','ArrowRight','Space','Escape'].includes(e.code)) e.preventDefault();
    keys[e.code] = true;
    if (e.code === 'Escape') closeGame();
    if (gameOver && (e.code === 'Space' || e.code === 'Enter')) startGame();
}
function onKeyUp(e) { keys[e.code] = false; }

function shoot() {
    bullets.push({ x: player.x, y: player.y, dy: -8 });
}

function spawnEnemy() {
    if (!running) return;
    let radius = 15 + Math.random()*20;
    let x = Math.random() * W;
    enemies.push({ x, y: -radius, r: radius, dy: 2 + Math.random()*2 });
    setTimeout(spawnEnemy, 1200);
}

function update() {
    if (gameOver) return;

    if (keys['ArrowLeft'] && player.x > player.size) player.x -= player.speed;
    if (keys['ArrowRight'] && player.x < W-player.size) player.x += player.speed;
    if (keys['Space'] && bullets.length < 6 && (!bullets.lastShot || Date.now()-bullets.lastShot > 300)) {
        shoot();
        bullets.lastShot = Date.now();
    }

    bullets.forEach(b => b.y += b.dy);
    bullets = bullets.filter(b => b.y > 0);

    enemies.forEach(e => e.y += e.dy);
    enemies = enemies.filter(e => e.y - e.r < H);

    for (let i = enemies.length-1; i >= 0; i--) {
        let e = enemies[i];
        for (let j = bullets.length-1; j >= 0; j--) {
            let b = bullets[j];
            let dx = e.x - b.x, dy = e.y - b.y;
            if (Math.sqrt(dx*dx + dy*dy) < e.r) {
                enemies.splice(i,1);
                bullets.splice(j,1);
                score += 10;
                break;
            }
        }
    }

    for (let e of enemies) {
        let dx = e.x - player.x, dy = e.y - player.y;
        if (Math.sqrt(dx*dx + dy*dy) < e.r + player.size/2) {
            gameOver = true;
        }
    }
}

function draw() {
    ctx.fillStyle = '#111';
    ctx.fillRect(0,0,W,H);

    ctx.fillStyle = 'lime';
    ctx.beginPath();
    ctx.moveTo(player.x, player.y-player.size);
    ctx.lineTo(player.x-player.size, player.y+player.size);
    ctx.lineTo(player.x+player.size, player.y+player.size);
    ctx.closePath();
    ctx.fill();

    ctx.fillStyle = 'yellow';
    bullets.forEach(b => ctx.fillRect(b.x-2, b.y-10, 4, 10));

    ctx.fillStyle = 'purple';
    enemies.forEach(e => {
        ctx.beginPath();
        ctx.arc(e.x, e.y, e.r, 0, Math.PI*2);
        ctx.fill();
    });

    document.getElementById('gameScore').textContent = "Puntaje: " + score;

    if (gameOver) {
        ctx.fillStyle = 'rgba(0,0,0,0.6)';
        ctx.fillRect(0,0,W,H);
        ctx.fillStyle = '#fff';
        ctx.font = 'bold 42px system-ui';
        ctx.textAlign = 'center';
        ctx.fillText('💥 PERDISTE', W/2, H/2-20);
        ctx.font = '18px system-ui';
        ctx.fillText('Presiona ESPACIO o ENTER para reiniciar — ESC para salir', W/2, H/2+20);
    }
}

function loop() {
    if (!running) return;
    update();
    draw();
    requestAnimationFrame(loop);
}
</script>

</body>
</html>