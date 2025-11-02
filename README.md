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
/* ===== 0) BASE ===== */
CREATE DATABASE IF NOT EXISTS bodega_sonda
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
USE bodega_sonda;

/* ===== 1) DIVISIONES (catálogo) ===== */
DROP TABLE IF EXISTS divisiones;
CREATE TABLE divisiones (
  id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo ENUM('CHQ','RT','DMH','GM') NOT NULL UNIQUE,
  nombre VARCHAR(100) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO divisiones (codigo, nombre) VALUES
('CHQ','Chuquicamata'),
('RT','Radomiro Tomic'),
('DMH','Ministro Hales'),
('GM','Gabriela Mistral');

/* ===== 2) USERS ===== */
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','editor','viewer') DEFAULT 'viewer',
  area ENUM('Radios','Redes','SCA','Libreria') DEFAULT NULL,
  /* Estos 2 campos permiten compatibilidad con el código actual */
  division ENUM('CHQ','RT','DMH','GM') DEFAULT 'CHQ',
  division_id TINYINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_division_id (division_id),
  CONSTRAINT fk_users_division
    FOREIGN KEY (division_id) REFERENCES divisiones(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* (Opcional) crear admin por defecto: REEMPLAZA_EL_HASH por un bcrypt real
INSERT INTO users (username,email,password,role,area,division,division_id)
VALUES ('admin','admin@local',
'REEMPLAZA_EL_HASH','admin','Redes','CHQ',1);
*/

/* ===== 3) BODEGAS ===== */
DROP TABLE IF EXISTS bodegas;
CREATE TABLE bodegas (
  id INT(11) NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(120) NOT NULL,
  abreviatura VARCHAR(20) DEFAULT NULL,
  /* Compatibilidad con código actual */
  division ENUM('CHQ','RT','DMH','GM') NOT NULL,
  division_id TINYINT UNSIGNED NOT NULL,
  is_principal TINYINT(1) NOT NULL DEFAULT 0,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bodega_div_nombre (division_id, nombre),
  KEY idx_bodegas_division_id (division_id),
  CONSTRAINT fk_bodegas_division
    FOREIGN KEY (division_id) REFERENCES divisiones(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* Seeds de bodegas principales (una por división) */
INSERT INTO bodegas (nombre, abreviatura, division, division_id, is_principal, activa)
VALUES
('Bodega Principal CHQ','CHQ','CHQ',1,1,1),
('Bodega Principal RT','RT','RT',2,1,1),
('Bodega Principal DMH','DMH','DMH',3,1,1),
('Bodega Principal GM','GM','GM',4,1,1);

/* ===== 4) PRODUCTS (con bodega_id) ===== */
DROP TABLE IF EXISTS products;
CREATE TABLE products (
  id INT(11) NOT NULL AUTO_INCREMENT,
  description TEXT NOT NULL,
  quantity INT(11) NOT NULL DEFAULT 0,
  ubicacion VARCHAR(100) DEFAULT NULL,
  barcode VARCHAR(100) DEFAULT NULL,
  area ENUM('Radios','Redes','SCA','Libreria') NOT NULL,
  bodega_id INT(11) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_barcode (barcode),
  KEY idx_products_bodega (bodega_id),
  CONSTRAINT fk_products_bodega
    FOREIGN KEY (bodega_id) REFERENCES bodegas(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ===== 5) SOLICITUDES ===== */
DROP TABLE IF EXISTS solicitudes;
CREATE TABLE solicitudes (
  id INT(11) NOT NULL AUTO_INCREMENT,
  product_id INT(11) NOT NULL,
  user_id INT(11) NOT NULL,
  cantidad INT(11) NOT NULL DEFAULT 1,
  ticket VARCHAR(50) NOT NULL DEFAULT '0',
  detalle TEXT DEFAULT NULL,
  estado ENUM('Pendiente','Aprobada','Rechazada','En Bodega','En Curso','Entregado')
         NOT NULL DEFAULT 'Pendiente',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_solicitudes_product (product_id),
  KEY idx_solicitudes_user (user_id),
  CONSTRAINT fk_solicitudes_product
    FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_solicitudes_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ===== 6) LOGS ===== */
DROP TABLE IF EXISTS logs;
CREATE TABLE logs (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  action TEXT NOT NULL,
  details TEXT DEFAULT NULL,
  old_value TEXT DEFAULT NULL,
  new_value TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_logs_user (user_id),
  CONSTRAINT fk_logs_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ===== 7) NOTIFICACIONES ===== */
DROP TABLE IF EXISTS notificaciones;
CREATE TABLE notificaciones (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  mensaje TEXT NOT NULL,
  link VARCHAR(255) DEFAULT NULL,
  leido TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id),
  CONSTRAINT fk_notif_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ===== 8) USER_BODEGAS (asignación múltiple) ===== */
DROP TABLE IF EXISTS user_bodegas;
CREATE TABLE user_bodegas (
  user_id INT(11) NOT NULL,
  bodega_id INT(11) NOT NULL,
  PRIMARY KEY (user_id, bodega_id),
  KEY idx_user_bodegas_bodega (bodega_id),
  CONSTRAINT fk_user_bodegas_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_user_bodegas_bodega
    FOREIGN KEY (bodega_id) REFERENCES bodegas(id)
    ON DELETE CASCADE ON UPDATE CASCADE
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
