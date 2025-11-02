<?php
session_start();
if (!isset($_SESSION['user'])) { header("Location: index.php"); exit; }
require 'config/db.php';

$rol = $_SESSION['role'] ?? 'viewer';
$puedeEditar = in_array($rol, ['admin','editor']);
$areaUsuario = $_SESSION['area'] ?? null;
$divisionName = $_SESSION['division_name'] ?? null; // área guardada en sesión

// ===== Notificaciones no leídas =====
$userId = $_SESSION['user_id'];
$notiStmt = $conn->prepare("SELECT * FROM notificaciones WHERE user_id = ? AND leido = 0 ORDER BY created_at DESC LIMIT 5");
$notiStmt->execute([$userId]);
$notificaciones = $notiStmt->fetchAll(PDO::FETCH_ASSOC);
$notiCount = count($notificaciones);

$mensaje = '';
$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barcode = trim($_POST['barcode'] ?? '');
    $accion  = $_POST['action'] ?? 'search';

    if ($barcode === '') {
        $mensaje = 'Escanee o escriba un código válido.';
    } else {
        // 🔎 Buscar producto (filtrando por área si no es admin)
        if ($rol === 'admin') {
            $stmt = $conn->prepare("SELECT * FROM products WHERE barcode = ?");
            $stmt->execute([$barcode]);
        } else {
            $stmt = $conn->prepare("SELECT * FROM products WHERE barcode = ? AND area = ?");
            $stmt->execute([$barcode, $areaUsuario]);
        }
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        // ➕ Incrementar stock (solo admin/editor) con log old/new
        if ($accion === 'increment' && $puedeEditar) {
            if ($prod) {
                $oldQty = (int)$prod['quantity'];
                $newQty = $oldQty + 1;

                $upd = $conn->prepare("UPDATE products SET quantity = quantity + 1, updated_at = NOW() WHERE id = ?");
                $upd->execute([$prod['id']]);

                // Log con valor anterior y nuevo
                if (!empty($_SESSION['user_id'])) {
                    $descLog = "Escaneo (+1) al producto ID {$prod['id']}: {$prod['description']} [{$barcode}]";
                    $log = $conn->prepare("INSERT INTO logs (user_id, action, details, old_value, new_value, created_at) VALUES (?, 'scan_increment', ?, ?, ?, NOW())");
                    $log->execute([$_SESSION['user_id'], $descLog, $oldQty, $newQty]);
                }

                $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
                $stmt->execute([$prod['id']]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);

                $mensaje = "✅ Se sumó 1 unidad a «{$prod['description']}».";
            } else {
                $mensaje = '⚠️ Ese código no existe, primero crea el producto.';
            }
        }

        // 📝 Solicitud de retiro (usuarios normales) — (nota: esta tabla "requests" es legacy si no la usas)
        if ($accion === 'request' && $prod) {
            $req = $conn->prepare("INSERT INTO requests (user_id, product_id, quantity, status, created_at) VALUES (?, ?, 1, 'pendiente', NOW())");
            $req->execute([$_SESSION['user_id'], $prod['id']]);

            $mensaje = "📩 Se generó solicitud de retiro del producto «{$prod['description']}».";
        }

        $resultado = [
            'barcode' => $barcode,
            'producto'=> $prod
        ];
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Buscar por código de barras - Bodega Sonda</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .big { font-size: 1.05rem; }
    /* Overlay del juego */
    #gameOverlay {
        position: fixed;
        inset: 0;
        background: #111;
        display: none;
        z-index: 9999;
    }
    #gameCanvas {
        display: block;
        margin: 0 auto;
        background: #202225;
        width: 100%;
        max-width: 900px;
        height: 70vh;
        border: 2px solid #333;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0,0,0,.5);
    }
    #gameUI {
        color: #eee;
        text-align: center;
        margin-top: 12px;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, Ubuntu, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji","Segoe UI Emoji","Segoe UI Symbol";
    }
    #gameUI .hint { opacity: .8; font-size: 0.95rem; }
    #closeGameBtn {
        position: absolute;
        top: 16px;
        right: 16px;
        border: 0;
        background: #dc3545;
        color: #fff;
        border-radius: 8px;
        padding: 6px 10px;
        font-size: 0.9rem;
    }
</style>
</head>
<body class="bg-light">
<!-- NAVBAR igual al dashboard -->
<nav class="navbar navbar-dark bg-dark border-bottom shadow-sm">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <img src="assets/logo.png" alt="Sonda Logo" height="120">
        <span class="navbar-brand h4 mb-0 text-white">Sistema de Bodega Sonda</span>
        <div class="d-flex align-items-center">
            <span class="me-3 text-white">
                Bienvenido 👤 <?= htmlspecialchars($_SESSION['user']) ?>
                / (<?= htmlspecialchars($_SESSION['role']) ?><?= $areaUsuario ? " - ".htmlspecialchars($areaUsuario) : "" ?>)
                <?php if ($divisionName): ?>
                    <span class="badge text-bg-secondary ms-2"><?= htmlspecialchars($divisionName) ?></span>
                <?php endif; ?>
            </span>

            <div class="dropdown me-3">
                <button class="btn btn-outline-light position-relative dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
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
                    <?php else: ?>
                        <?php foreach ($notificaciones as $n): ?>
                            <li>
                                <a class="dropdown-item" href="<?= htmlspecialchars($n['link']) ?>">
                                    <?= htmlspecialchars($n['mensaje']) ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($n['created_at']) ?></small>
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                        <?php endforeach; ?>
                        <li><a class="dropdown-item text-center" href="ver_notificaciones.php">📜 Ver todas</a></li>
                    <?php endif; ?>
                </ul>
            </div>
          <a href="dashboard.php" class="btn btn-outline-light me-2">🏠 Dashboard</a>
            <a href="logout.php" class="btn btn-outline-light">Cerrar sesión</a>
        </div>
    </div>
</nav>

<div class="container py-4" style="max-width: 820px">
    <?php if ($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <form method="post" class="big mb-4">
        <label class="form-label">Código de barras</label>
        <input id="barcode" name="barcode" class="form-control form-control-lg" value="<?= htmlspecialchars($resultado['barcode'] ?? '') ?>" autofocus>
        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary">Buscar</button>
            <?php if ($puedeEditar): ?>
                <button name="action" value="increment" class="btn btn-success">➕ Sumar 1</button>
            <?php endif; ?>
            <?php if ($rol === 'viewer' || $rol === 'editor'): ?>
                <button name="action" value="request" class="btn btn-warning">📩 Solicitar Retiro</button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($resultado): ?>
        <?php if ($resultado['producto']): 
            $p = $resultado['producto']; ?>
            <div class="card shadow-sm">
                <div class="card-header"><strong>Resultado</strong></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div><strong>Descripción:</strong> <?= htmlspecialchars($p['description']) ?></div>
                            <div><strong>Código:</strong> <?= htmlspecialchars($p['barcode']) ?></div>
                            <div><strong>Área:</strong> <?= htmlspecialchars($p['area']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div><strong>Cantidad:</strong> <?= (int)$p['quantity'] ?></div>
                            <div><strong>Ubicación:</strong> <?= htmlspecialchars($p['ubicacion']) ?></div>
                        </div>
                    </div>
                    <div class="mt-3 d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-info" href="ver_ubicacion.php?ubicacion=<?= urlencode($p['ubicacion']) ?>">Ver ubicación</a>
                        <?php if ($puedeEditar): ?>
                            <a class="btn btn-warning" href="editar_producto.php?id=<?= $p['id'] ?>">✏️ Editar</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                No existe producto con el código <strong><?= htmlspecialchars($resultado['barcode']) ?></strong>.
                <?php if ($puedeEditar): ?>
                    <a class="btn btn-sm btn-primary ms-2" href="agregar_producto.php?barcode=<?= urlencode($resultado['barcode']) ?>">Crear producto con este código</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
      <a href="dashboard.php" class="btn btn-secondary mt-3">Volver</a>
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

<script>
    const inp = document.getElementById('barcode');
    function focusScanner() { 
        if (document.getElementById('gameOverlay').style.display !== 'block') { inp && inp.focus(); } 
    }
    window.addEventListener('load', focusScanner);
    document.addEventListener('click', focusScanner);

    // ==== Huevo de pascua: 5 clics en el logo ====
    (function() {
        const logo = document.getElementById('logo-egg');
        let clickCount = 0, timer = null;
        if (!logo) return;
        logo.addEventListener('click', () => {
            clickCount++;
            if (timer) clearTimeout(timer);
            timer = setTimeout(() => { clickCount = 0; }, 1200);
            if (clickCount >= 5) { clickCount = 0; openGame(); }
        });
    })();

    // ==== Juego estilo Asteroids ====
    let canvas, ctx, W, H;
    let player, bullets, enemies, score, running, gameOver;

    function openGame() {
        const overlay = document.getElementById('gameOverlay');
        overlay.style.display = 'block';
        overlay.setAttribute('aria-hidden','false');
        startGame();
    }

    function closeGame() {
        document.getElementById('gameOverlay').style.display = 'none';
        document.getElementById('gameOverlay').setAttribute('aria-hidden','true');
        stopGame();
        setTimeout(() => { document.getElementById('barcode')?.focus(); }, 50);
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

    let keys = {};
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
        // mover nave
        if (keys['ArrowLeft'] && player.x > player.size) player.x -= player.speed;
        if (keys['ArrowRight'] && player.x < W-player.size) player.x += player.speed;
        if (keys['Space'] && bullets.length<6 && (!bullets.lastShot || Date.now()-bullets.lastShot>300)) {
            shoot();
            bullets.lastShot = Date.now();
        }

        // mover balas
        bullets.forEach(b => b.y += b.dy);
        bullets = bullets.filter(b => b.y > 0);

        // mover enemigos
        enemies.forEach(e => e.y += e.dy);
        enemies = enemies.filter(e => e.y - e.r < H);

        // colisiones balas-enemigos
        for (let i=enemies.length-1; i>=0; i--) {
            let e = enemies[i];
            for (let j=bullets.length-1; j>=0; j--) {
                let b = bullets[j];
                let dx = e.x - b.x, dy = e.y - b.y;
                if (Math.sqrt(dx*dx+dy*dy) < e.r) {
                    enemies.splice(i,1);
                    bullets.splice(j,1);
                    score += 10;
                    break;
                }
            }
        }

        // colisión jugador-enemigos
        for (let e of enemies) {
            let dx = e.x - player.x, dy = e.y - player.y;
            if (Math.sqrt(dx*dx+dy*dy) < e.r+player.size/2) {
                gameOver = true;
            }
        }
    }

    function draw() {
        ctx.fillStyle = '#111';
        ctx.fillRect(0,0,W,H);

        // jugador como triángulo
        ctx.fillStyle = 'lime';
        ctx.beginPath();
        ctx.moveTo(player.x, player.y-player.size);
        ctx.lineTo(player.x-player.size, player.y+player.size);
        ctx.lineTo(player.x+player.size, player.y+player.size);
        ctx.closePath();
        ctx.fill();

        // balas
        ctx.fillStyle = 'yellow';
        bullets.forEach(b => ctx.fillRect(b.x-2, b.y-10, 4, 10));

        // enemigos
        ctx.fillStyle = 'purple';
        enemies.forEach(e => {
            ctx.beginPath();
            ctx.arc(e.x, e.y, e.r, 0, Math.PI*2);
            ctx.fill();
        });

        // score
        document.getElementById('gameScore').textContent = "Puntaje: "+score;

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
