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
