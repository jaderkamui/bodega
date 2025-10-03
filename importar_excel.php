<?php
require 'vendor/autoload.php';
require 'config/db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Ruta del archivo Excel (asegúrate de que esté en la raíz del proyecto)
$archivoExcel = __DIR__ . '/productos.xlsx';

if (!file_exists($archivoExcel)) {
    die("❌ El archivo 'productos.xlsx' no fue encontrado en la raíz del proyecto.");
}

try {
    $spreadsheet = IOFactory::load($archivoExcel);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray();

    $insertados = 0;

    foreach ($data as $index => $row) {
        if ($index == 0) continue; // Saltar encabezado

        $cantidad    = trim($row[0]); // Columna A
        $descripcion = trim($row[1]); // Columna B
        $ubicacion   = trim($row[2]); // Columna C

        // Validación básica
        if ($cantidad !== '' && $descripcion !== '') {
            $stmt = $conn->prepare("INSERT INTO products (quantity, description, ubicacion, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
            $stmt->execute([$cantidad, $descripcion, $ubicacion]);
            $insertados++;
        }
    }

    echo "✅ Importación completada: $insertados productos agregados a la base de datos.";
} catch (Exception $e) {
    echo "❌ Error al importar: " . $e->getMessage();
}
?>
