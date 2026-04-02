-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 18-02-2026 a las 21:04:43
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `bodega_sonda`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `bodegas`
--

CREATE TABLE `bodegas` (
  `id` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `abreviatura` varchar(16) DEFAULT NULL,
  `division` varchar(120) NOT NULL DEFAULT '',
  `is_principal` tinyint(1) NOT NULL DEFAULT 0,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `division_id` tinyint(3) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `bodegas`
--

INSERT INTO `bodegas` (`id`, `nombre`, `abreviatura`, `division`, `is_principal`, `activa`, `division_id`, `created_at`, `updated_at`) VALUES
(14, 'Bodega Principal DMH', 'DMH1', 'Ministro Hales', 1, 1, 1, '2025-11-03 16:12:46', '2025-11-03 16:12:46'),
(15, 'Bodega Principal RT', 'RT1', 'Radomiro Tomic', 1, 1, 2, '2025-11-03 16:12:46', '2025-11-03 16:12:46'),
(16, 'Bodega Principal CHQ', 'CHQ1', 'Chuquicamata', 1, 1, 3, '2025-11-03 16:12:46', '2025-11-03 16:12:46'),
(17, 'Bodega Principal GM', 'GM1', 'Gabriela Mistral', 1, 1, 4, '2025-11-03 16:12:46', '2025-11-03 16:12:46'),
(18, 'Bodega Principal DCHS', 'DCHS1', 'División Chuqui Subterránea', 1, 1, 5, '2025-11-03 16:12:46', '2025-11-03 16:12:46'),
(22, 'Bodega Principal Corporativo', 'CORPO1', 'Corporativo', 1, 1, 6, '2026-02-10 15:45:07', '2026-02-10 15:49:23');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `divisiones`
--

CREATE TABLE `divisiones` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `nombre` varchar(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `divisiones`
--

INSERT INTO `divisiones` (`id`, `nombre`) VALUES
(3, 'Chuquicamata'),
(5, 'Chuquicamata Subterránea'),
(6, 'Corporativo'),
(4, 'Gabriela Mistral'),
(1, 'Ministro Hales'),
(2, 'Radomiro Tomic');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `logs`
--

CREATE TABLE `logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `details` text DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `notificaciones`
--

CREATE TABLE `notificaciones` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `mensaje` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `leido` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `products`
--

CREATE TABLE `products` (
  `id` int(10) UNSIGNED NOT NULL,
  `description` varchar(255) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `ubicacion` varchar(120) DEFAULT NULL,
  `area` enum('Radios','Redes','SCA','Libreria') DEFAULT NULL,
  `bodega_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `solicitudes`
--

CREATE TABLE `solicitudes` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `ticket` varchar(60) DEFAULT '0',
  `detalle` text DEFAULT NULL,
  `bodega_id` int(10) UNSIGNED DEFAULT NULL,
  `estado_general` enum('Pendiente','Parcial','Cerrada') NOT NULL DEFAULT 'Pendiente',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `solicitud_entregas`
--

CREATE TABLE `solicitud_entregas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `solicitud_id` int(10) UNSIGNED NOT NULL,
  `admin_user_id` int(10) UNSIGNED NOT NULL,
  `receptor_nombre` varchar(120) NOT NULL,
  `receptor_rut` varchar(20) DEFAULT NULL,
  `firma_base64` longtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estado` enum('PENDIENTE_ADMIN','PENDIENTE_USUARIO','COMPLETADA') NOT NULL DEFAULT 'PENDIENTE_ADMIN',
  `token_usuario` varchar(80) DEFAULT NULL,
  `admin_firma_path` varchar(255) DEFAULT NULL,
  `receptor_firma_path` varchar(255) DEFAULT NULL,
  `admin_signed_at` datetime DEFAULT NULL,
  `receptor_signed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `solicitud_items`
--

CREATE TABLE `solicitud_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `solicitud_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `cant_solicitada` int(11) NOT NULL DEFAULT 1,
  `cant_aprobada` int(11) NOT NULL DEFAULT 0,
  `cant_entregada` int(11) NOT NULL DEFAULT 0,
  `estado_item` enum('Pendiente','Aprobada','Rechazada','En Bodega','En Curso','Entregado') NOT NULL DEFAULT 'Pendiente',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','editor','lector') NOT NULL DEFAULT 'lector',
  `area` enum('Radios','Redes','SCA','Libreria') DEFAULT NULL,
  `division_id` tinyint(3) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `area`, `division_id`, `created_at`) VALUES
(9, 'Redes', 'redes@redes.com', '$2y$10$nyXkNWurNcdED2KEmnGqW.hfIW/gmNCxQWhEhmQDmqh9QXsJIi6Te', 'editor', 'SCA', 2, '2025-11-03 16:33:12'),
(10, 'Control de Acceso', 'sca@sca.com', '$2y$10$VlOHtFxJSCBFPN0XlsFKAeck9PoomEOdxReZl36crrKPwZljH/2fK', 'editor', 'SCA', 4, '2025-11-03 16:34:18'),
(11, 'Admin', 'admin@bodega.com', '$2y$10$lIv70orOWHYQHxohRlG7HOz8j3QHEnieQwQqZerf4zrckYAqgWgY6', 'admin', 'Radios', 1, '2026-01-23 16:21:10'),
(12, 'Radio', 'radio@radio.com', '$2y$10$K3ZY.mfqKPTt9QXKQ7kSHOqkF7xtBUspXPENFvLGPSzffPiojRy4i', 'editor', 'Radios', 6, '2026-02-04 18:13:17'),
(13, 'Usuario', 'user@user.cl', '$2y$10$PklGuoTzofXGtJTgAUkN5umGRaOF/EWssLUF1j10admiz2.bKAhiq', 'editor', 'Redes', 3, '2026-02-05 12:11:32');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_bodegas`
--

CREATE TABLE `user_bodegas` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `bodega_id` int(10) UNSIGNED NOT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `bodegas`
--
ALTER TABLE `bodegas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bodega_nombre_div` (`nombre`,`division_id`),
  ADD UNIQUE KEY `uk_bodegas_div_nombre` (`division`,`nombre`),
  ADD KEY `idx_bodegas_division` (`division_id`);

--
-- Indices de la tabla `divisiones`
--
ALTER TABLE `divisiones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `logs`
--
ALTER TABLE `logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_logs_user` (`user_id`);

--
-- Indices de la tabla `notificaciones`
--
ALTER TABLE `notificaciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_user` (`user_id`),
  ADD KEY `idx_notif_leido` (`leido`);

--
-- Indices de la tabla `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_products_bodega` (`bodega_id`);

--
-- Indices de la tabla `solicitudes`
--
ALTER TABLE `solicitudes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_solicitudes_user` (`user_id`),
  ADD KEY `idx_solicitudes_bodega` (`bodega_id`);

--
-- Indices de la tabla `solicitud_entregas`
--
ALTER TABLE `solicitud_entregas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_entregas_solicitud` (`solicitud_id`),
  ADD KEY `fk_entregas_admin` (`admin_user_id`);

--
-- Indices de la tabla `solicitud_items`
--
ALTER TABLE `solicitud_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_items_solicitud` (`solicitud_id`),
  ADD KEY `idx_items_product` (`product_id`);

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_division` (`division_id`);

--
-- Indices de la tabla `user_bodegas`
--
ALTER TABLE `user_bodegas`
  ADD PRIMARY KEY (`user_id`,`bodega_id`),
  ADD KEY `idx_ub_bodega` (`bodega_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `bodegas`
--
ALTER TABLE `bodegas`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT de la tabla `logs`
--
ALTER TABLE `logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `notificaciones`
--
ALTER TABLE `notificaciones`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `products`
--
ALTER TABLE `products`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `solicitudes`
--
ALTER TABLE `solicitudes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `solicitud_entregas`
--
ALTER TABLE `solicitud_entregas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `solicitud_items`
--
ALTER TABLE `solicitud_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `bodegas`
--
ALTER TABLE `bodegas`
  ADD CONSTRAINT `fk_bodegas_division` FOREIGN KEY (`division_id`) REFERENCES `divisiones` (`id`);

--
-- Filtros para la tabla `logs`
--
ALTER TABLE `logs`
  ADD CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `notificaciones`
--
ALTER TABLE `notificaciones`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_bodega` FOREIGN KEY (`bodega_id`) REFERENCES `bodegas` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `solicitudes`
--
ALTER TABLE `solicitudes`
  ADD CONSTRAINT `fk_solicitudes_bodega` FOREIGN KEY (`bodega_id`) REFERENCES `bodegas` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_solicitudes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `solicitud_entregas`
--
ALTER TABLE `solicitud_entregas`
  ADD CONSTRAINT `fk_entregas_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_entregas_solicitud` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitudes` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `solicitud_items`
--
ALTER TABLE `solicitud_items`
  ADD CONSTRAINT `fk_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `fk_items_solicitud` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitudes` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_division` FOREIGN KEY (`division_id`) REFERENCES `divisiones` (`id`);

--
-- Filtros para la tabla `user_bodegas`
--
ALTER TABLE `user_bodegas`
  ADD CONSTRAINT `fk_ub_bodega` FOREIGN KEY (`bodega_id`) REFERENCES `bodegas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
