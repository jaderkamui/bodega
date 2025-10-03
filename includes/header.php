<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Sistema de Bodega</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">Bodega Sonda</a>
        <div class="d-flex">
            <span class="navbar-text text-white me-3">
                <?php echo $_SESSION['username'] ?? ''; ?>
            </span>
            <a href="logout.php" class="btn btn-outline-light">Cerrar sesión</a>
        </div>
    </div>
</nav>
<div class="container">
