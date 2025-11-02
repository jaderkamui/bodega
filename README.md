📦 Bodega Sonda

Sistema de gestión de bodega desarrollado en PHP + MySQL, pensado para control interno de productos, solicitudes de materiales, usuarios y registros de auditoría.

Incluye control de roles, notificaciones en tiempo real, logs de cambios.

🚀 Características principales

Gestión de productos (CRUD con códigos de barras, ubicaciones y áreas).

Control de stock con actualizaciones automáticas al entregar solicitudes.

Gestión de solicitudes:

Usuarios pueden generar, editar y consultar sus solicitudes.

Administradores aprueban, rechazan y entregan solicitudes.

Notificaciones con campanita:

Avisos al usuario cuando cambia el estado de su solicitud.

Avisos al administrador cuando los usuarios modifican solicitudes.

Control de roles:

👑 Administrador: gestión completa del sistema.

✏️ Editor: gestiona productos y genera solicitudes.

👁️ Lector: solo puede consultar información.

Logs detallados de acciones con valor anterior y nuevo (auditoría).

Filtros activos/cerrados en panel de solicitudes.

Huevo de pascua arcade oculto en el logo.

🛠️ Tecnologías utilizadas

Backend: PHP 8.2 (Slim Framework opcional)

Base de datos: MySQL / MariaDB

Frontend: Bootstrap 5 + DataTables

Servidor: Apache (XAMPP/Docker)

Otros: PhpSpreadsheet para importación desde Excel

-----------------------------------------------
📂 Estructura del proyecto
bodega_sonda/
├── config/          # Configuración de base de datos
├── assets/          # Logo, estilos, imágenes
├── logs/            # Auditoría de acciones
├── solicitudes/     # Generación y administración de solicitudes
├── usuarios/        # Gestión de usuarios y roles
├── dashboard.php    # Panel principal
├── scan_barcode.php # Escaneo rápido de códigos
└── ...

----------------------------------------------

⚙️ Instalación
Requisitos

PHP >= 8.0

MySQL >= 5.7

Composer (para dependencias)

XAMPP o Docker

Pasos

Clonar el repositorio:

git clone https://github.com/jaderkamui/bodega
cd bodega_sonda


Configurar base de datos en config/db.php.

SQL para recrear la base de datos :

---------------------

CREATE DATABASE IF NOT EXISTS bodega_sonda CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE bodega_sonda;

-- Tabla usuarios
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','editor','viewer') DEFAULT 'viewer',
  `area` enum('Radios','Redes','SCA','Libreria') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabla productos
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` text NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `ubicacion` varchar(100) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `area` enum('Radios','Redes','SCA','Libreria') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabla solicitudes
CREATE TABLE `solicitudes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 1,
  `ticket` varchar(50) NOT NULL DEFAULT '0',
  `detalle` text DEFAULT NULL,
  `estado` enum('Pendiente','Aprobada','Rechazada','En Bodega','En Curso','Entregado') NOT NULL DEFAULT 'Pendiente',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `solicitudes_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `solicitudes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabla logs
CREATE TABLE `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` text NOT NULL,
  `details` text DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabla notificaciones
CREATE TABLE `notificaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `mensaje` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `leido` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notificaciones_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-----------------------------------------------

Importar database.sql (incluido en el repo).

Levantar el servidor:

Con XAMPP: copiar carpeta en htdocs y acceder a http://localhost/bodega_sonda.

Con Docker:

docker-compose up -d

👤 Roles y accesos
Rol	Permisos principales
Admin	CRUD completo, gestión de usuarios, ver logs, administrar solicitudes
Editor	CRUD productos, generar solicitudes
Lector	Solo visualiza productos y solicitudes
🔔 Notificaciones

El usuario recibe notificación cuando su solicitud cambia de estado.

El administrador recibe notificación cuando un usuario modifica su solicitud.

El número rojo en la campanita desaparece automáticamente al abrir las solicitudes.

📝 Logs

Cada acción importante queda registrada en la tabla logs, indicando:

Usuario que ejecutó la acción.

Acción realizada.

Detalle descriptivo.

Valor anterior y nuevo.

Fecha y hora.


📄 Licencia

Proyecto desarrollado con fines internos y educativos.
© 2025 — Desarrollado por Jader Muñoz.
