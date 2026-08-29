# Flowers Anto — de sitio informativo a plataforma de comercio

Documento de entrega. Agosto de 2026.

Punto de partida: `webANTO.zip` (46 archivos, PHP + MySQL). El repositorio
contenía además un `webANTO.rar` con una versión anterior del mismo proyecto;
se descartó por estar superada en todo (sin `includes/`, con CSRF en 2 de 14
acciones de escritura, credenciales versionadas y tres tablas que el código
usaba pero que el SQL no creaba). Sigue disponible en el historial de `main`.

---

## 1. Qué había y qué faltaba

**Funcionaba:** catálogo con filtros y buscador, carrito y favoritos en
`localStorage`, modal de producto, galería de fotos y videos, temporadas con
fechas, y un panel para productos, categorías, temporadas, galería, apariencia
y contacto.

**No existía nada de lo que pedía el encargo:** cuentas de cliente, registro,
Google, recuperación por correo, pedidos, checkout, compra como invitado,
transferencia bancaria, comprobantes, estados de pago y de pedido, dashboard,
roles y permisos, auditoría, respaldos, créditos administrables y URLs amigables.

**Problemas de arquitectura que había que resolver antes de añadir nada:**

| # | Problema | Consecuencia |
|---|----------|--------------|
| 1 | Todo el sitio era un `index.html` de una sola página | Sin URLs propias, sin SEO, imposible enlazar un producto |
| 2 | Carrito y favoritos solo en `localStorage` | Se perdían al cambiar de dispositivo o limpiar el navegador |
| 3 | `usuarios` sin columna `email` | El login era por nombre de usuario; un cliente no puede registrarse así |
| 4 | Sin migraciones versionadas | Actualizar una instalación con datos era manual y arriesgado |
| 5 | `admin/guardar.php`: un `switch` de 400 líneas con 20 acciones | No escalaba a los seis módulos nuevos |
| 6 | Contraseña de administrador publicada en el README | Cualquiera que viera el repositorio podía entrar |

---

## 2. Decisión de arquitectura

**Se evolucionó el proyecto, no se reescribió.** Se mantiene PHP 8 + MySQL +
JavaScript sin frameworks, y se conserva el punto fuerte de la versión anterior:
cero dependencias de Composer y npm, para que siga desplegándose copiando
carpetas en cualquier hosting compartido.

Lo que eso obligó a resolver a mano, y por qué compensó:

- **Google OAuth** con cURL contra los endpoints oficiales (~120 líneas) en
  lugar de la librería de Google, que habría arrastrado Composer al proyecto.
- **SMTP** sobre `stream_socket_client` con STARTTLS y `AUTH LOGIN` en lugar de
  PHPMailer, por el mismo motivo.
- **Migraciones en PHP** en vez de SQL puro: `ADD COLUMN IF NOT EXISTS` existe en
  MariaDB pero no en MySQL 8, así que la clase `Esquema` consulta
  `information_schema` y el mismo archivo funciona en los dos motores.
- **Volcador de respaldos propio**, porque en la mayoría de hostings compartidos
  no hay acceso a `mysqldump` y un respaldo que solo funciona en el portátil del
  desarrollador no sirve de nada.

Lo que **no** se hizo: no se introdujo un framework MVC ni un contenedor de
dependencias. El proyecto es de tamaño medio y esa complejidad no habría
aportado nada al cliente.

---

## 3. Estructura nueva

El sitio de una sola página se dividió en páginas reales, cada una con su URL:

```
index.php  productos.php  producto.php  carrito.php  favoritos.php
checkout.php  pedido.php  seguimiento.php  comprobante.php  instalar.php
cuenta/…   admin/…   api/…
```

El núcleo se repartió en trece módulos bajo `includes/lib/`: `utiles`,
`validacion`, `seguridad`, `ajustes`, `auditoria`, `auth`, `rbac`, `correo`,
`catalogo`, `carrito`, `favoritos`, `pedidos`, `archivos`, más `respaldos` y
`google`, que se cargan solo donde hacen falta.

`admin/guardar.php` desapareció: cada sección del panel maneja sus propias
escrituras, con su propia comprobación de permisos al principio.

---

## 4. Base de datos

Cinco migraciones versionadas, con 17 tablas nuevas y ninguna fila perdida.
La `001` sirve además como actualización desde la versión anterior: crea lo que
falta y completa columnas sin borrar datos.

**Tablas nuevas:** `roles`, `permisos`, `rol_permisos`, `password_resets`,
`rate_limits`, `auditoria`, `producto_imagenes`, `favoritos`, `carrito_items`,
`estados_pedido`, `pedidos`, `pedido_items`, `pedido_comprobantes`,
`pedido_historial`, `cuentas_bancarias`, `respaldos`, `migraciones`.

**Cambios sobre las existentes:** `usuarios` pasa a albergar clientes y personal
(email único, nombre y apellido, teléfono, rol, activo, google_id);
`productos` gana slug único, resumen, stock, orden y contador de ventas;
`categorias` gana slug e imagen; `configuracion` suma 26 columnas nuevas para
pedidos, envío, SEO y créditos del desarrollador.

Las cuatro columnas `imagen`…`imagen4` se convirtieron en filas de
`producto_imagenes`, que permite cualquier número de fotos y reordenarlas.
`productos.imagen` se conserva como portada para que los listados no necesiten
un JOIN extra.

---

## 5. Funcionalidades implementadas

**Cliente:** registro con validación real, inicio de sesión por correo o usuario,
Google OAuth, recuperación por correo con token de un solo uso, catálogo con
búsqueda y filtros combinables, ficha con galería deslizable y visor a pantalla
completa, carrito persistente, favoritos que sobreviven al registro, checkout
como invitado o con cuenta, pedido por WhatsApp con el carrito completo,
transferencia con datos bancarios y botón de copiar, subida de comprobante con
vista previa, seguimiento del pedido con línea de tiempo, historial de pedidos y
edición de sus datos.

**Panel:** resumen con métricas y gráfico, gestión completa de pedidos con
aprobación y rechazo de pagos, productos con galería múltiple y control de stock,
categorías, temporadas, galería, clientes con su historial, empleados, roles y
permisos, auditoría con filtros y exportación a CSV, configuración en seis
pestañas, respaldos y estado de la base de datos.

---

## 6. Seguridad

- Contraseñas con `password_hash()` y re-hasheo automático
- CSRF en todas las escrituras, con comparación en tiempo constante
- Permisos comprobados en el servidor antes de cada acción, no en la interfaz
- Cookies `HttpOnly`, `SameSite=Lax`, `Secure` bajo HTTPS, rotación cada 30 minutos
- Consultas preparadas reales en todo el proyecto
- Subidas validadas por contenido: un `.php` renombrado a `.jpg` se rechaza
- Comprobantes fuera del alcance del navegador, servidos por una ruta con permisos
- Límites de intentos en acceso, registro, recuperación, seguimiento y pedidos
- Mensajes genéricos donde revelar de más permitiría enumerar cuentas o pedidos
- La auditoría filtra contraseñas y tokens antes de guardar nada
- Los respaldos subidos se rechazan si traen `GRANT`, `CREATE USER` o similares
- Motor de PHP desactivado en `uploads/` por `.htaccess`
- Los números de cuenta viven en la base de datos, nunca en el código

**La contraseña de fábrica desapareció.** `instalar.php` crea la primera cuenta
y se cierra sola en cuanto existe. La migración `001` invalida además el hash de
`password` que arrastraba la versión anterior.

---

## 7. Fallos encontrados durante las pruebas

Tres se detectaron probando de verdad, no leyendo el código:

**`entero()` aplicaba el mínimo al valor por defecto.** `entero('id', 1, …, 0)`
devolvía `1` cuando no venía ningún `id`, así que «producto nuevo» abría el
producto 1. Peor: un `id=0` explícito también se convertía en `1`, y «crear
empleado» acabó editando al usuario 1. Se separó el helper `identificador()`,
que devuelve 0 cuando no hay valor válido, y se cambiaron las 22 llamadas.

**La auditoría no guardaba nada.** `in_array($op['resultado'] ?? 'exito', …) ? $op['resultado'] : 'exito'`
evaluaba la condición con el valor por defecto pero devolvía la clave sin
definir, es decir `null`, y la columna no admite nulos. Ninguna acción quedaba
registrada.

**La copia previa a una restauración desaparecía del panel.** El respaldo
restaurado incluye la propia tabla `respaldos`, así que sustituía el listado por
el que existía cuando se hizo la copia: la red de seguridad se volvía invisible
justo cuando hacía falta. Ahora se vuelven a registrar los dos archivos después
de restaurar.

---

## 8. Pruebas realizadas

Con PHP 8.4 y MariaDB 10.11, sobre una base creada desde cero con las
migraciones y el seed.

**Cliente:** registro (con contraseña débil rechazada y correo duplicado que no
revela si existe), inicio de sesión, recuperación completa por correo con
reutilización del token bloqueada, catálogo, ficha, favoritos, carrito, checkout
como invitado, acceso al pedido con enlace firmado y denegado sin él (403),
subida de comprobante en JPG y PDF, y rechazo de un `.php` renombrado a `.jpg`.

**Panel:** las 17 páginas sin errores ni avisos de PHP, rechazo de un pago con
motivo, nuevo comprobante, aprobación, y el pedido recorriendo los seis estados
hasta «Entregado» con su correo en cada paso.

**Roles:** un empleado de productos accede a resumen, productos, categorías y
galería (200) y recibe 403 en pedidos, respaldos, auditoría, empleados,
configuración y base de datos. Un POST enviado a mano para aprobar un pago
también devuelve 403, no cambia el estado y queda anotado en la auditoría.

**Respaldos:** creación (25 tablas, 49 KB), descarga, rechazo de un `.sql` con
`GRANT`, rechazo de la restauración sin escribir RESTAURAR, y restauración real
verificando que los datos vuelven atrás y que la copia previa queda registrada.

---

## 9. Lo que queda por configurar

Nada de esto se puede dejar hecho desde el código: depende de credenciales del
cliente.

1. **Cuenta bancaria real** — el seed crea una de ejemplo con ceros. Cambiarla en
   *Configuración → Transferencias* antes de publicar.
2. **Número de WhatsApp** — *Configuración → Contacto*.
3. **Correo saliente** — poner `MAIL_TRANSPORTE=smtp` y los datos del servidor.
   Con `log` los correos se escriben en `storage/logs/correos.log` y el cliente
   no recibe nada.
4. **Google OAuth** — id y secreto en `.env` si se quiere el acceso con Google.
   Sin ellos el botón no aparece y el resto funciona igual.
5. **Créditos del desarrollador** — *Configuración → Créditos*: nombre, logo,
   descripción y enlace.
6. **`APP_ENTORNO=prod` y `APP_CLAVE` nueva** antes de publicar.
7. **HTTPS**, para que la cookie de sesión se marque como segura.
