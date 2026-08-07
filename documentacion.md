- PHP puro.
- PDO.
- MySQL/MariaDB.
- XAMPP.
- Autenticación y permisos.
- Sistemas multi-sucursal.
- Catálogos, productos, pedidos, checkout y stock.

El proyecto es una plataforma para panaderías desarrollada en PHP puro con MySQL/MariaDB.
Actualmente incluye o debe incluir:
- Compradores.
- Vendedores.
- Administrador Global.
- Encargado Padre.
- Encargado Hijo.
- Panadería principal.
- Sucursales Hijas.
- Invitaciones.
- Productos propios del Padre.
- Productos heredados por las Hijas.
- Stock independiente por sucursal.
- Trabajadores.
- Pedidos.
- Carrito.
- Checkout.
- Calificaciones.
- Panel del administrador.
- Panel del vendedor/encargado.
- Catálogo público.
## Reglas de negocio
### Vendedor pendiente

Un vendedor puede:
- Iniciar sesión.
- Entrar a su panel.
- Completar su perfil.
- Subir documentación.
- Crear y editar productos.

Mientras no esté aprobado:
- Sus productos no deben aparecer públicamente.
- Su panadería no debe aparecer en el catálogo.
- No debe recibir pedidos públicos.
- Sus productos no deben poder comprarse mediante APIs.

### Encargado Padre
Debe ser un vendedor aprobado y autorizado por el Administrador.
Debe tener:
```text
tipo = vendedor
is_admin_pan = 1
tipo_sucursal = padre
También debe tener una sucursal Padre activa.
El Administrador es quien convierte al vendedor en Padre. El vendedor no puede autoconvertirse.
Cuando el Administrador convierte un vendedor aprobado en Padre, el sistema debe:
Activar los permisos de Encargado.
Asignar tipo_sucursal = padre.
Crear automáticamente una sucursal Padre activa si no existe.
Evitar crear sucursales duplicadas.
Permitir luego crear sucursales Hijas e invitar encargados.
El Padre puede:
Crear productos.
Editar sus productos.
Activar o desactivar productos.
Crear sucursales Hijas.
Invitar Encargados Hijos.
Asignar productos a Hijas.
Asignar todos los productos activos a una Hija.
Establecer precios mínimos.
Ver pedidos y métricas de sus Hijas.
Administrar trabajadores de sus sucursales.
Encargado Hijo
Debe aceptar una invitación antes de quedar activo.
Al aceptar una invitación:
Se crea la cuenta si no existía.
Se vincula con la sucursal correspondiente.
Se vincula con el Padre correcto.
Se activa la sucursal.
La invitación pasa a estado aceptada.
El token no puede reutilizarse.
La sesión debe regenerarse.

El Hijo puede:
Administrar su sucursal.
Ver productos heredados.
Aceptar productos heredados.
Rechazar o dejar de vender productos heredados.
Definir precios respetando el precio mínimo.
Ver sus pedidos.
Gestionar el stock de su sucursal.
Administrar trabajadores de su sucursal.

El Hijo no puede:
Crear productos propios independientes.
Modificar el producto original del Padre.
Administrar otras sucursales.
Asignar productos a otras sucursales.
Ver el stock de otras sucursales.
Funcionalidades ya trabajadas
Según la conversación anterior, ya se trabajó en:
Conversión de vendedor a Encargado Padre desde admin.php.
El Administrador selecciona el tipo “Sucursal Padre” desde el panel. El sistema crea automáticamente una sucursal Padre activa si no existe.
Creación de sucursales Hijas desde vendedor.php.
El Padre puede indicar:
Nombre de sucursal.
Dirección.
Teléfono.
Nombre del futuro Hijo.
Email del futuro Hijo.
Invitaciones de sucursal.
Ya existe la tabla invitaciones_sucursal.
La invitación utiliza:
Token seguro.
Hash SHA-256 almacenado en la base.
Vencimiento.
Estado pendiente.
Sucursal pendiente.
Aceptación y rechazo de invitaciones.
Existe aceptar-invitacion.php.
El usuario invitado puede:
Crear una cuenta nueva.
Definir una contraseña.
Aceptar la invitación.
Rechazar la invitación.
Al aceptar, se crea o vincula el usuario como Hija y se activa la sucursal.
Herencia individual de productos.
El Padre puede asignar productos individuales a una Hija mediante herencia_productos.
Asignación masiva de productos.
Se agregó una acción para asignar todos los productos activos del Padre a una Hija seleccionada.
El precio mínimo queda igual al precio actual del Padre.
Los productos quedan pendientes de aceptación de la Hija.
Se probó exitosamente el flujo:
Padre crea invitación.
Invitado abre el enlace.
Invitado completa nombre y contraseña.
Invitado entra al panel de sucursal Hija.
Verificá que estas funcionalidades realmente estén presentes en el repositorio actual y que no tengan errores.
Problemas y funcionalidades pendientes
Priorizá el trabajo de esta forma:
Prioridad 1: permisos y seguridad
Revisar y corregir:
Validación real de permisos en servidor.
Acceso de vendedores pendientes.
Bloqueo de compradores en paneles privados.
Bloqueo de trabajadores en vendedor.php.
Bloqueo de vendedores en admin.php.
Separación real de permisos Padre/Hija.
Regeneración de sesión al iniciar sesión.
Protección CSRF en formularios POST.
Validación de IDs.
Validación de pertenencia de productos.
Validación de pertenencia de sucursales.
Protección contra acceso directo mediante URL.
Protección de subida de archivos.
Existe una inconsistencia que debe revisarse:
El login usa $_SESSION['user_id'].
Revisar si alguna API todavía usa $_SESSION['usuario'].
Prioridad 2: solicitud formal para ser Padre
Actualmente el Administrador puede convertir manualmente un vendedor en Padre, pero falta verificar si existe un flujo formal de solicitud.
Debe existir:
Botón “Solicitar ser Encargado Padre”.
Solicitud pendiente.
Vista de solicitudes en admin.php.
Aprobación por Administrador.
Rechazo con motivo.
Conversión a Padre sólo después de aprobar.
Creación o vinculación de sucursal Padre.
Indicador de estado para el Administrador.
No marques automáticamente a un vendedor como Padre sin aprobación administrativa.
Revisá si solicitudes_admin sirve para este flujo o si conviene crear una tabla separada. No reutilices la tabla sin analizar su propósito actual.
Prioridad 3: productos heredados
Completar y revisar:
Asignación individual.
Asignación masiva.
Aceptación por Hija.
Rechazo por Hija.
Precio mínimo.
Precio de venta de la Hija.
Revocación por Padre.
Revocación por Hija.
Prevención de duplicados.
Validación de propiedad.
La relación correcta debe utilizar:
herencia_productos.sucursal_id → sucursales.id
No asumir que sucursal_id corresponde a usuarios.id.
Prioridad 4: stock independiente por sucursal
Actualmente no existe una tabla stock_sucursal.
El stock actual está en:
productos.cantidad_disponible
Eso no es suficiente para múltiples sucursales.
Diseñar primero, antes de modificar checkout:
stock_sucursal
Debe permitir diferenciar:
Producto.
Sucursal Padre.
Sucursal Hija.
Cantidad actual.
Stock mínimo.
Estado.
Fecha de actualización.
Debe contemplar:
Stock del Padre.
Stock de cada Hija.
Productos heredados.
Stock independiente.
Historial de movimientos.
Compatibilidad con datos actuales.
No hagas una migración destructiva ni elimines productos.cantidad_disponible sin confirmación.
Prioridad 5: transferencias de stock
El Padre debe poder transferir cantidades físicas de stock a una Hija.
Debe permitir:
Seleccionar Hija.
Seleccionar producto.
Indicar cantidad.
Validar stock disponible del Padre.
Descontar stock del Padre.
Aumentar stock de la Hija.
Usar transacción.
Evitar cantidades negativas.
Registrar movimiento.
Impedir transferencias a Hijas ajenas.
La asignación de productos y la transferencia física de stock son procesos diferentes.
Prioridad 6: catálogo público
Verificar que sólo aparezcan públicamente:
Vendedores aprobados.
Padres y Hijas activas.
Sucursales activas.
Productos activos.
Productos heredados aceptados.
Productos con stock disponible.
Precios reales de la sucursal correspondiente.
Revisar:
catalogo.php
tienda.php
producto.php
sucursal.php
api/buscar.php
api/carrito.php
Acceso directo por URL.
Prioridad 7: carrito y checkout
El checkout debe:
Validar comprador.
Validar vendedor.
Validar sucursal.
Validar producto.
Leer el precio real desde la base.
No confiar en precios enviados por JavaScript.
Validar que el producto esté activo.
Validar que esté aceptado por la Hija.
Validar stock real de la sucursal.
Descontar stock de la sucursal correcta.
Usar transacciones.
Evitar condiciones de carrera.
Guardar vendedor y sucursal en el pedido.
Impedir comprar productos ocultos o inactivos.
Actualmente la tabla pedidos no tiene claramente un sucursal_id. Revisar si es necesario agregarlo.
Prioridad 8: trabajadores
Revisar la relación actual entre:
trabajador → sucursal
Actualmente existe panaderia_id, pero hay que confirmar si representa una sucursal o sólo al usuario Padre.
Implementar correctamente:
Alta de trabajador.
Asignación a una única sucursal.
Login.
Panel propio.
Restricción a una sola sucursal.
Consulta de productos de su sucursal.
Entradas y salidas de stock.
Permisos.
Prevención de acceso a otras sucursales.
Verificar si existe trabajador.php. Si el login redirige allí y el archivo no existe, corregirlo.
Prioridad 9: movimientos de stock
Revisar la tabla movimientos.
Debe permitir registrar:
Entrada.
Salida.
Cantidad.
Motivo.
Producto.
Sucursal.
Trabajador.
Fecha.
Usuario que realizó el movimiento.
Debe usar:
Validación de permisos.
Transacciones.
Validación de stock.
Historial.
Prevención de movimientos sobre sucursales ajenas.
Prioridad 10: pruebas
Preparar pruebas para:
Vendedor pendiente.
Vendedor aprobado.
Padre.
Hija antes de aceptar invitación.
Hija después de aceptar invitación.
Comprador.
Trabajador.
Administrador.
Invitación válida.
Invitación vencida.
Invitación rechazada.
Invitación reutilizada.
Token inválido.
Producto oculto.
Producto aprobado.
Producto inactivo.
Producto heredado pendiente.
Producto heredado aceptado.
Stock insuficiente.
Compra desde sucursal incorrecta.
Acceso no autorizado.
Relación Padre-Hija incorrecta.
Forma de trabajo obligatoria
Antes de modificar archivos:
Revisá el repositorio completo.
Identificá la estructura real.
Revisá los archivos principales.
Compará el código con las reglas anteriores.
Indicá qué ya existe.
Indicá qué está incompleto.
Indicá qué está roto.
Indicá qué falta.
Revisá las tablas necesarias.
Entregá una tabla con:
Funcionalidad | Estado actual | Qué falta | Archivos involucrados | Prioridad
Después proponé un único grupo pequeño de trabajo y esperá mi confirmación.
No hagas cambios en la primera respuesta de la nueva conversación.
Forma de entregar el código
Todo el código debe pasarse por el chat para copiar y pegar.
Para cada modificación indicá:
Archivo exacto.
Si debo crear, reemplazar o agregar.
Bloque exacto que debo buscar.
Código completo para copiar y pegar.
Qué debo borrar o conservar.
Cómo probarlo.
Consultas SQL de verificación.
No me pidas que tome archivos directamente del entorno.
No mezcles varios grupos de trabajo en una sola instrucción.
No borres usuarios reales. No elimines tablas. No hagas migraciones destructivas sin confirmación.
Mantené:
PHP puro.
PDO.
MySQL/MariaDB.
Compatibilidad con XAMPP.
Consultas preparadas.
Validaciones del lado del servidor.
Escapado HTML.
Transacciones.
Código claro y fácil de copiar.
Información de la base de datos
No existe la tabla:
stock_sucursal
La base usa:
Host: localhost
Puerto MySQL: 1107
Base de datos: panaderia_db
No necesito mostrar contraseñas, hashes completos, tokens ni secretos.
Pedime o revisá estos resultados actualizados:
SHOW TABLES;
SHOW CREATE TABLE usuarios;
SHOW CREATE TABLE sucursales;
SHOW CREATE TABLE invitaciones_sucursal;
SHOW CREATE TABLE herencia_productos;
SHOW CREATE TABLE productos;
SHOW CREATE TABLE pedidos;
SHOW CREATE TABLE pedido_items;
SHOW CREATE TABLE movimientos;
SHOW CREATE TABLE catalogo_base;
SHOW CREATE TABLE productos_encargado;
SHOW CREATE TABLE solicitudes_admin;
También verificar si existe:
SHOW TABLES LIKE 'stock_sucursal';
Datos actuales no sensibles
Solicitar estas consultas sin incluir contraseñas ni tokens:
SELECT
id,
nombre,
email,
tipo,
estado_verificacion,
is_admin_pan,
puede_ser_admin,
tipo_sucursal,
sucursal_padre_id,
panaderia_id,
panaderia_padre_id,
es_sucursal,
created_at
FROM usuarios
ORDER BY id;
SELECT
id,
vendedor_id,
padre_id,
nombre,
direccion,
telefono,
activo,
estado,
created_at
FROM sucursales
ORDER BY id;
SELECT
id,
padre_id,
sucursal_id,
usuario_invitado_id,
email_invitado,
nombre_invitado,
estado,
expires_at,
accepted_at,
rejected_at,
revoked_at,
created_at
FROM invitaciones_sucursal
ORDER BY id;
SELECT
id,
producto_id,
padre_id,
sucursal_id,
precio_minimo,
precio_sucursal,
aceptado,
created_at
FROM herencia_productos
ORDER BY id;
SELECT
id,
vendedor_id,
nombre,
precio,
cantidad_disponible,
activo,
created_at
FROM productos
ORDER BY id;
SELECT
id,
comprador_id,
vendedor_id,
estado,
total,
created_at
FROM pedidos
ORDER BY id;
SELECT
id,
trabajador_id,
vendedor_id,
producto_id,
tipo,
cantidad,
descripcion,
created_at
FROM movimientos
ORDER BY id;
No mostrar nunca:
password_hash
token_hash
password
SESSION_SECRET
tokens de invitación reales
claves de API
secretos de entorno
Primer objetivo recomendado
Después de auditar el repositorio actualizado, el primer grupo recomendado debería ser:
Corregir permisos y sesiones.
Confirmar que el flujo Padre/Hija esté protegido.
Verificar el carrito.
Luego diseñar la tabla de stock independiente por sucursal.
No empezar todavía modificando checkout o stock hasta revisar las tablas reales.
## Datos importantes que debes enviar junto con el prompt
Además del repositorio actualizado, envía:
1. El resultado de `SHOW TABLES`.
2. Todos los `SHOW CREATE TABLE` indicados.
3. Las consultas de usuarios, sucursales, invitaciones, herencias, productos, pedidos y movimientos.
4. Si puedes, una descripción breve de cualquier error actual.
5. Qué funcionalidades ya probaste manualmente:
- Conversión a Padre.
- Creación de invitación.
- Aceptación como Hija.
- Asignación masiva de productos.
6. No envíes contraseñas, hashes, tokens reales ni secretos.

## Base de Datos (puede estar incompleta)

127.0.0.1:1107/panaderia_db/		http://localhost:8012/phpmyadmin/index.php?route=/database/sql&db=panaderia_db
Su consulta se ejecutó con éxito.

Su consulta se ejecutó con éxito.

Su consulta se ejecutó con éxito.

Su consulta se ejecutó con éxito.

Su consulta se ejecutó con éxito.

Su consulta se ejecutó con éxito.

Su consulta se ejecutó con éxito.

Su consulta se ejecutó con éxito.

Su consulta se ejecutó con éxito.

SHOW CREATE TABLE usuarios;


SHOW CREATE TABLE sucursales;


SHOW CREATE TABLE productos;


SHOW CREATE TABLE movimientos;


SHOW CREATE TABLE herencia_productos;


SHOW CREATE TABLE pedidos;


SHOW CREATE TABLE pedido_items;


SHOW CREATE TABLE invitaciones_sucursal;


SHOW CREATE TABLE solicitudes_padre;



usuarios	CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `email` varchar(180) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'comprador',
  `nombre_panaderia` varchar(120) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `banner_anuncio` varchar(120) DEFAULT NULL,
  `avatar_url` varchar(500) DEFAULT NULL,
  `instagram` varchar(80) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `email_contacto` varchar(180) DEFAULT NULL,
  `estado_verificacion` enum('sin_enviar','pendiente','aprobado','rechazado') DEFAULT 'sin_enviar',
  `medios_pago` varchar(255) DEFAULT 'efectivo',
  `cbu` varchar(30) DEFAULT NULL,
  `alias_cbu` varchar(50) DEFAULT NULL,
  `titular_cuenta` varchar(120) DEFAULT NULL,
  `doc_bromatologia` varchar(500) DEFAULT NULL,
  `doc_carnet_manipulador` varchar(500) DEFAULT NULL,
  `doc_habilitacion_comercial` varchar(500) DEFAULT NULL,
  `doc_notas_rechazo` text DEFAULT NULL,
  `panaderia_padre_id` int(11) DEFAULT NULL,
  `es_sucursal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `puede_ser_admin` tinyint(1) NOT NULL DEFAULT 0,
  `identificador` varchar(50) DEFAULT NULL,
  `documento_id` varchar(50) DEFAULT NULL,
  `panaderia_id` int(11) DEFAULT NULL,
  `is_admin_pan` tinyint(1) DEFAULT 0,
  `tipo_sucursal` enum('padre','hija') DEFAULT NULL,
  `sucursal_padre_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `identificador` (`identificador`),
  KEY `fk_suc_padre` (`sucursal_padre_id`),
  CONSTRAINT `fk_suc_padre` FOREIGN KEY (`sucursal_padre_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci	
sucursales	CREATE TABLE `sucursales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendedor_id` int(11) DEFAULT NULL,
  `padre_id` int(11) DEFAULT NULL,
  `nombre` varchar(255) NOT NULL,
  `direccion` varchar(500) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `estado` enum('pendiente','activa','inactiva') NOT NULL DEFAULT 'activa',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `vendedor_id` (`vendedor_id`),
  KEY `idx_sucursales_padre_id` (`padre_id`),
  KEY `idx_sucursales_estado` (`estado`),
  CONSTRAINT `fk_sucursales_padre_id` FOREIGN KEY (`padre_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `sucursales_ibfk_1` FOREIGN KEY (`vendedor_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci	
productos	CREATE TABLE `productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendedor_id` int(11) NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL,
  `precio_media_docena` decimal(10,2) DEFAULT NULL,
  `precio_docena` decimal(10,2) DEFAULT NULL,
  `cantidad_disponible` int(11) DEFAULT 0,
  `dato_extra` varchar(255) DEFAULT NULL,
  `categoria` enum('pan','facturas','galletas','cakes','otro') DEFAULT 'pan',
  `unidad_venta` enum('unidad','kilo') DEFAULT 'unidad',
  `imagen_url` varchar(500) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `vendedor_id` (`vendedor_id`),
  CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`vendedor_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci	
movimientos	CREATE TABLE `movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('entrada','salida') NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` int(11) NOT NULL,
  `descripcion` varchar(300) DEFAULT NULL,
  `trabajador_id` int(11) NOT NULL,
  `vendedor_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `producto_id` (`producto_id`),
  KEY `trabajador_id` (`trabajador_id`),
  KEY `vendedor_id` (`vendedor_id`),
  CONSTRAINT `movimientos_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `movimientos_ibfk_2` FOREIGN KEY (`trabajador_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `movimientos_ibfk_3` FOREIGN KEY (`vendedor_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci	
herencia_productos	CREATE TABLE `herencia_productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `padre_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `precio_minimo` decimal(10,2) NOT NULL,
  `precio_sucursal` decimal(10,2) DEFAULT NULL,
  `aceptado` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_herencia` (`producto_id`,`sucursal_id`),
  KEY `padre_id` (`padre_id`),
  KEY `sucursal_id` (`sucursal_id`),
  CONSTRAINT `herencia_productos_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `herencia_productos_ibfk_2` FOREIGN KEY (`padre_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `herencia_productos_ibfk_3` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci	
pedidos	CREATE TABLE `pedidos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comprador_id` int(11) NOT NULL,
  `vendedor_id` int(11) NOT NULL,
  `sucursal_id` int(11) DEFAULT NULL,
  `estado` enum('pendiente','confirmado','listo','entregado') DEFAULT 'pendiente',
  `metodo_pago` enum('efectivo','transferencia','debito','credito') DEFAULT 'efectivo',
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notas` text DEFAULT NULL,
  `codigo_postal` varchar(8) DEFAULT NULL,
  `direccion` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `comprador_id` (`comprador_id`),
  KEY `vendedor_id` (`vendedor_id`),
  KEY `fk_pedidos_sucursal` (`sucursal_id`),
  CONSTRAINT `fk_pedidos_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pedidos_ibfk_1` FOREIGN KEY (`comprador_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pedidos_ibfk_2` FOREIGN KEY (`vendedor_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci	
pedido_items	CREATE TABLE `pedido_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pedido_id` int(11) NOT NULL,
  `producto_id` int(11) DEFAULT NULL,
  `nombre_producto` varchar(120) NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `pedido_id` (`pedido_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `pedido_items_ibfk_1` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pedido_items_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci	
invitaciones_sucursal	CREATE TABLE `invitaciones_sucursal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `padre_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `usuario_invitado_id` int(11) DEFAULT NULL,
  `email_invitado` varchar(180) NOT NULL,
  `nombre_invitado` varchar(120) DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `estado` enum('pendiente','aceptada','rechazada','revocada','vencida') NOT NULL DEFAULT 'pendiente',
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invitaciones_token_hash` (`token_hash`),
  KEY `idx_inv_padre_estado` (`padre_id`,`estado`),
  KEY `idx_inv_sucursal_estado` (`sucursal_id`,`estado`),
  KEY `idx_inv_usuario_estado` (`usuario_invitado_id`,`estado`),
  KEY `idx_inv_email_estado` (`email_invitado`,`estado`),
  CONSTRAINT `fk_inv_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_sucursal_padre` FOREIGN KEY (`padre_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_inv_usuario` FOREIGN KEY (`usuario_invitado_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci	
solicitudes_padre	CREATE TABLE `solicitudes_padre` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendedor_id` int(11) NOT NULL,
  `estado` enum('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
  `motivo_rechazo` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_sol_padre` (`vendedor_id`),
  CONSTRAINT `fk_sol_padre` FOREIGN KEY (`vendedor_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci	



127.0.0.1:1107/panaderia_db/		http://localhost:8012/phpmyadmin/index.php?route=/database/sql&db=panaderia_db
Su consulta se ejecutó con éxito.

Su consulta se ejecutó con éxito.

Su consulta se ejecutó con éxito.

SHOW INDEX FROM movimientos;


SHOW INDEX FROM sucursales;


SHOW INDEX FROM herencia_productos;



movimientos	0	PRIMARY	1	id	A	0	NULL	NULL		BTREE			
movimientos	1	producto_id	1	producto_id	A	0	NULL	NULL		BTREE			
movimientos	1	trabajador_id	1	trabajador_id	A	0	NULL	NULL		BTREE			
movimientos	1	vendedor_id	1	vendedor_id	A	0	NULL	NULL		BTREE			
sucursales	0	PRIMARY	1	id	A	5	NULL	NULL		BTREE			
sucursales	1	vendedor_id	1	vendedor_id	A	5	NULL	NULL	YES	BTREE			
sucursales	1	idx_sucursales_padre_id	1	padre_id	A	5	NULL	NULL	YES	BTREE			
sucursales	1	idx_sucursales_estado	1	estado	A	5	NULL	NULL		BTREE			
herencia_productos	0	PRIMARY	1	id	A	0	NULL	NULL		BTREE			
herencia_productos	0	uq_herencia	1	producto_id	A	0	NULL	NULL		BTREE			
herencia_productos	0	uq_herencia	2	sucursal_id	A	0	NULL	NULL		BTREE			
herencia_productos	1	padre_id	1	padre_id	A	0	NULL	NULL		BTREE			
herencia_productos	1	sucursal_id	1	sucursal_id	A	0	NULL	NULL		BTREE			


##Importante:
Encargado Padre: puede crear una sucursal Padre, crear productos, crear una o mas sucursal Hija, puede visualizar sus metricas/movimientos de su sucursal y de sus sucursales Hija. 
Encargado Hija: Hereda productos de la sucursal Padre, el precio de los productos establecidos por la sucursal padre quedan como precio minimo para Hija, pueden visualizar sus propias metricas, NO PUEDEN crear productos.
Importante: El meto de creacion de las sucursales Hija es mediante invitacion (un enlace que ya esta implementado) el cual abrira el Encargado Hija y pondra su Gmail y Contraseña (con el cual podra entrar luego).