# Flowers Anto

Tienda en línea y panel de administración de una floristería en Managua.
Catálogo de arreglos, carrito, favoritos, pedidos con pago por transferencia y
revisión manual del comprobante, panel con roles, auditoría y respaldos.

---

## Tecnologías

| Capa | Herramienta |
|------|-------------|
| Servidor | PHP 8.1 o superior (Apache) |
| Base de datos | MySQL 8 / MariaDB 10.4 o superior |
| Acceso a datos | PDO con consultas preparadas reales |
| Frontend | HTML5, CSS3 y JavaScript sin frameworks |
| Correo | SMTP propio, `mail()` o archivo de registro |
| Iconos y fuentes | Font Awesome 6 y Google Fonts (por CDN) |

**No hay dependencias de Composer ni de npm.** Es una decisión deliberada: el
proyecto se despliega copiando carpetas y funciona en cualquier hosting
compartido. Google OAuth y el envío por SMTP están implementados con cURL y
sockets nativos por ese mismo motivo.

---

## Requisitos

- PHP 8.1+ con las extensiones `pdo_mysql`, `gd`, `mbstring`, `curl`, `fileinfo`
- MySQL 8 o MariaDB 10.4+
- Apache con `mod_rewrite` y `AllowOverride All` (para que `.htaccess` se aplique)
- Permiso de escritura en `uploads/` y `storage/`

---

## Instalación

### 1. Copiar el proyecto

```bash
git clone https://github.com/Alejandro-RG20/WebFlowersAnto.git
cd WebFlowersAnto
```

En XAMPP: copia la carpeta en `C:\xampp\htdocs\webANTO`.

### 2. Crear la base de datos vacía

```sql
CREATE DATABASE flowers_anto CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

No hace falta importar ningún `.sql`: las tablas las crean las migraciones.

### 3. Configurar el entorno

```bash
cp .env.example .env
```

Edita `.env` con los datos de tu servidor. Como mínimo:

```ini
APP_ENTORNO=dev
APP_URL=http://localhost/webANTO
APP_BASE_URL=/webANTO
DB_NAME=flowers_anto
DB_USER=root
DB_PASS=
```

> Si tu hosting no admite archivos que empiecen por punto, copia
> `config.example.php` como `config.local.php` y pon ahí los mismos valores.
> Ambos archivos están en `.gitignore`.

### 4. Aplicar las migraciones

```bash
php db/migrar.php
```

Sin acceso a consola: abre `instalar.php` en el navegador y pulsa
«Aplicar migraciones».

### 5. Crear la cuenta de administración

Abre `instalar.php` en el navegador y completa el formulario.

**No existe ninguna contraseña de fábrica.** La página de instalación se cierra
sola en cuanto hay una cuenta de personal activa: a partir de ahí, las cuentas
nuevas se crean desde *Panel → Empleados*.

### 6. (Opcional) Datos de ejemplo

```bash
php db/seed.php            # catálogo de muestra
php db/seed.php --pedidos  # además, pedidos de prueba en varios estados
php db/seed.php --limpiar  # borra SOLO lo marcado como demostración
```

Todo lo que crea el seed queda marcado (`[DEMO]` en los productos,
`FA-DEMO-*` en los pedidos), así que nunca se confunde con datos reales.

### 7. Permisos de carpetas

```bash
chmod 755 uploads storage storage/comprobantes storage/respaldos storage/logs
```

---

## Variables de entorno

Se leen en este orden: variable de entorno real → `.env` → `config.local.php`.

| Clave | Para qué sirve |
|-------|----------------|
| `APP_ENTORNO` | `dev` muestra errores en pantalla. En producción: `prod` |
| `APP_URL` | URL pública completa. La usan los correos y el callback de Google |
| `APP_BASE_URL` | Ruta del sitio dentro del dominio (`/webANTO` o vacío) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Conexión a MySQL |
| `MAX_UPLOAD_MB` | Tamaño máximo de imagen del catálogo |
| `MAX_COMPROBANTE_MB` | Tamaño máximo de comprobante de pago |
| `MAX_RESPALDO_MB` | Tamaño máximo de respaldo que se puede subir |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Acceso con Google (opcional) |
| `MAIL_TRANSPORTE` | `log`, `mail` o `smtp` |
| `MAIL_REMITENTE`, `MAIL_REMITENTE_NOMBRE` | Remitente de los correos |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_SEGURIDAD`, `SMTP_USUARIO`, `SMTP_PASSWORD` | Servidor SMTP |
| `MYSQLDUMP_BIN` | Ruta a `mysqldump`. Si falta, se usa el volcador en PHP |

`.env` y `config.local.php` **nunca** se suben al repositorio.

---

## Base de datos y migraciones

El esquema se gestiona con migraciones versionadas en `db/migraciones/`, que son
archivos PHP numerados. Se escriben en PHP y no en SQL puro porque
`ADD COLUMN IF NOT EXISTS` existe en MariaDB pero no en MySQL 8: la clase
`Esquema` consulta `information_schema` y hace que el mismo archivo funcione en
los dos motores y se pueda re-ejecutar sin romper nada.

| Migración | Qué hace |
|-----------|----------|
| `001_base.php` | Catálogo, categorías, temporadas, galería y configuración |
| `002_usuarios_roles.php` | Cuentas, roles, permisos, recuperación, límites y auditoría |
| `003_catalogo.php` | URLs amigables, galería por producto y control de stock |
| `004_comercio.php` | Favoritos, carrito, pedidos, comprobantes, estados y bancos |
| `005_configuracion.php` | Configuración ampliada, créditos del desarrollador y respaldos |
| `006_envio_y_avisos.php` | Zonas de envío, libreta de direcciones, enlace de ubicación y textos de los correos |
| `007_repartidores.php` | Repartidores, asignación del pedido y mensaje al motorizado |
| `008_estilos_temporada.php` | Estilo visual asociado a cada temporada |

```bash
php db/migrar.php            # aplica lo pendiente
php db/migrar.php --estado   # solo informa
```

La migración `001` también sirve como actualización desde la versión anterior:
crea lo que falta, añade las columnas nuevas y **no borra ninguna fila**. Haz
un respaldo antes, por si acaso.

Desde el panel: *Base de datos → Aplicar migraciones* (requiere el permiso
`sistema.migrar`, que solo tiene el super administrador).

---

## Estructura

```
/
├── index.php               Portada
├── productos.php           Catálogo con filtros y paginación
├── producto.php            Ficha del producto (URL amigable por slug)
├── carrito.php             Carrito
├── favoritos.php           Favoritos
├── checkout.php            Datos, entrega y método de pago
├── pedido.php              Seguimiento y subida del comprobante
├── seguimiento.php         Buscar un pedido con el código y el correo
├── comprobante.php         Entrega los comprobantes previa autorización
├── instalar.php            Instalación guiada (se cierra sola)
├── sitemap.php             Mapa del sitio generado del catálogo
│
├── cuenta/                 entrar, registrar, salir, recuperar, restablecer,
│                           perfil, pedidos, direcciones, google, google-callback
├── admin/                  Panel: resumen, pedidos, productos, categorías,
│                           temporadas, galería, clientes, repartidores,
│                           empleados, roles, auditoría, configuración,
│                           respaldos, base de datos
├── api/                    carrito.php y favoritos.php (POST, JSON)
│
├── includes/
│   ├── bootstrap.php       Configuración, sesión, PDO y carga de módulos
│   ├── entorno.php         Lector de .env y config.local.php
│   ├── lib/                utiles, validacion, seguridad, ajustes, auditoria,
│   │                       auth, rbac, correo, catalogo, temporadas, envios,
│   │                       carrito, favoritos, pedidos, repartidores,
│   │                       archivos, respaldos, google
│   └── vistas/             cabecera, pie, tarjeta_producto, menu_cuenta
│
├── assets/css/             estilos.css, app.css, hero.css, temporada.css, admin.css
├── assets/js/              app.js, hero.js, temporada.js, admin.js
│
├── db/
│   ├── Migrador.php        Motor de migraciones
│   ├── migrar.php          Ejecutor por consola
│   ├── seed.php            Datos de ejemplo
│   └── migraciones/        001…008
│
├── images/                 Imágenes del proyecto y placeholders
├── uploads/                Fotos subidas desde el panel (públicas)
└── storage/                Fuera del alcance del navegador
    ├── comprobantes/       Comprobantes de pago
    ├── respaldos/          Copias de la base de datos
    └── logs/               Errores y correos en modo desarrollo
```

---

## Sistema de usuarios y roles

Hay **una sola tabla `usuarios`** para clientes y personal. Los separa el rol:
quien tenga un rol con `es_personal = 1` puede entrar al panel. Mantener una
sola tabla evita duplicar el login, el hash de la contraseña, la recuperación y
el enlace con los pedidos.

| Rol | Para qué sirve |
|-----|----------------|
| **Super administrador** | Acceso total, incluida la restauración de respaldos y las migraciones |
| **Administrador** | Gestión general del negocio, sin restaurar respaldos ni tocar roles |
| **Empleado de pedidos** | Pedidos, pagos y comprobantes. Ve clientes y productos, no los edita |
| **Empleado de productos** | Catálogo, categorías, temporadas y galería |
| **Auditor** | Solo lectura: consulta pedidos, clientes, configuración y auditoría |
| **Cliente** | Cuenta de la tienda. Sin acceso al panel |

Los permisos se comprueban **en el servidor** antes de ejecutar cada acción
(`Rbac::exigir`). Ocultar un botón es cortesía visual, no la protección: un POST
enviado a mano sin el permiso se rechaza con 403 y queda anotado en la auditoría.

Reglas que el servidor impone y la interfaz solo refleja:

- nadie puede cambiarse su propio rol ni desactivarse a sí mismo
- siempre debe quedar al menos un super administrador activo
- el rol de super administrador conserva siempre todos los permisos

### Cómo se crean las cuentas de empleado

Desde *Panel → Empleados*. **No se escribe ninguna contraseña**: la persona
recibe un correo con un enlace de un solo uso, válido 48 horas, y elige la suya.
Nadie más llega a conocerla.

---

## Acceso con Google (opcional)

1. Entra en la [consola de Google Cloud](https://console.cloud.google.com/apis/credentials)
2. Crea unas credenciales de tipo **ID de cliente de OAuth → Aplicación web**
3. Añade como URI de redirección autorizada:
   `{APP_URL}/cuenta/google-callback.php`
4. Copia el id y el secreto en `.env`:

```ini
GOOGLE_CLIENT_ID=...apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=...
```

Si se dejan vacíos, el botón «Continuar con Google» simplemente no aparece.

El flujo usa `state` y `nonce` contra CSRF, valida el `id_token` contra el
endpoint oficial de Google y solo acepta cuentas con el correo verificado. Si ya
existe una cuenta con ese correo, se enlaza en lugar de duplicarla.

---

## Correo

`MAIL_TRANSPORTE` decide cómo se envía:

- **`log`** (desarrollo): escribe los mensajes en `storage/logs/correos.log`.
  Sirve para probar el registro, la recuperación de contraseña y los avisos de
  pedido sin configurar nada.
- **`mail`**: función `mail()` de PHP. Lo habitual en hosting compartido.
- **`smtp`**: servidor propio con STARTTLS o SSL y `AUTH LOGIN`. Recomendado en
  producción porque los correos llegan a la bandeja de entrada y no a spam.

Correos que envía el sistema al **cliente**: bienvenida, recuperación de
contraseña, aviso de cambio de contraseña, pedido recibido, comprobante
recibido, pago aprobado, pago rechazado (con el motivo) y un aviso en cada
cambio de estado del pedido.

Correos que envía al **equipo** (a `email_avisos`, o al correo de contacto si
está vacío): pedido nuevo con el cliente, la dirección, el enlace de ubicación
y el detalle; y comprobante subido, con el monto, el banco y la referencia que
declaró el cliente. Los dos se activan y desactivan por separado en
*Configuración → Avisos por correo*.

Todos salen con la plantilla de la marca: logo, nombre, eslogan y los colores
que haya configurados. El color del texto sobre la banda superior se calcula
con la luminancia del color primario, así que se lee igual con un rosa pastel
que con un vino oscuro. Si el logo es un SVG se escribe el nombre en su lugar,
porque Gmail y Outlook no pintan SVG.

Como el transporte `log` no envía nada y se comporta igual que uno bien
configurado —en silencio—, la pestaña *Avisos por correo* dice cuál está activo,
avisa cuando no sale ningún correo y tiene un botón para mandar una prueba real
que informa del error concreto si falla.

El texto de cada aviso de estado se edita en esa misma pestaña
(`estados_pedido.mensaje_correo`), y cada estado puede dejar de enviar correo
sin dejar de existir (`avisar_cliente`). Al texto fijo se le añade la nota que
escriba quien atiende el pedido, y para los estados de entrega en curso también
la dirección y el enlace del mapa.

---

## Pedidos

El cliente puede pedir de dos maneras, y ambas conviven:

**Pedir por WhatsApp** — se genera un mensaje con todo el carrito (productos,
cantidades, subtotal, envío y total) y se abre el chat. El número sale de la
configuración, nunca del código.

**Completar pedido en la web** — checkout con datos del cliente, entrega,
dedicatoria y método de pago. **No obliga a crear cuenta**: se puede comprar
como invitado, iniciar sesión o registrarse durante el proceso. El invitado
recibe un enlace firmado con el que sigue su pedido y sube el comprobante sin
tener cuenta.

### Estados

| Pedido | Pago |
|--------|------|
| Pendiente | Sin pago en línea |
| Pago en revisión | Pendiente de comprobante |
| Pedido confirmado | Comprobante recibido |
| En preparación | En revisión |
| Listo para entrega | Pago aprobado |
| Enviado | Pago rechazado |
| Entregado / Cancelado | |

El nombre, el color y el orden de cada estado se editan desde la tabla
`estados_pedido`; el `codigo` es fijo porque es lo que consulta la lógica.
Las transiciones válidas están definidas en `Pedidos::FLUJO`: no se puede saltar
de «pendiente» a «enviado».

### Envío por zonas

El costo del envío depende del destino: no cuesta lo mismo cruzar Managua que
salir de la ciudad. Cada zona (`zonas_envio`) lleva su nombre, su descripción
—los barrios que abarca, que el cliente lee bajo la lista—, su precio y si está
dentro o fuera de Managua, que es lo que agrupa la lista del checkout.

El precio **siempre se vuelve a leer de la base** al calcular el resumen y al
registrar el pedido: del formulario solo viaja el identificador de la zona, así
que retocar el HTML no abarata un envío. El resumen que se mueve al cambiar de
zona es presentación; el total que vale es el que confirma el servidor.

Cada pedido guarda `zona_envio_id` y también `zona_envio_nombre`. La copia del
nombre es a propósito: si mañana la zona se renombra o se borra, el pedido sigue
diciendo a dónde se llevó.

El umbral de envío gratis por importe es del negocio, no del destino: se aplica
igual a todas las zonas.

Si no hay ninguna zona configurada, el sitio se comporta como antes de que
existieran y cobra el costo de envío general.

### Enlace de ubicación

El cliente puede pegar el punto exacto de entrega desde Google Maps, Waze, Apple
Maps, OpenStreetMap o what3words, o unas coordenadas sueltas (`12.1364, -86.2514`),
que se convierten en un enlace de Google Maps. Es lo que abre el repartidor desde
su teléfono; aparece como botón en la ficha del pedido en el panel.

La lista de servicios aceptados está cerrada a propósito (`Envios::DOMINIOS_MAPA`):
ese enlace lo abre alguien del equipo, y admitir cualquier dirección convertiría
el formulario del pedido en una forma cómoda de colarle un enlace a donde sea.

Quien tenga cuenta puede marcar «guardar esta dirección» y la encuentra como
chip en su próximo pedido, o la administra en *Mi cuenta → Mis direcciones*.
Guardar dos veces la misma dirección en la misma zona actualiza la que ya
existe en vez de acumular copias.

### Despacho al motorizado

Los repartidores viven en su propia tabla, no como usuarios: al motorizado no
le hace falta una cuenta ni entrar al panel, solo su nombre y su WhatsApp.

Desde la ficha del pedido se elige a quién mandársela y se abre WhatsApp con la
dirección, la zona, la referencia, el enlace del mapa, el detalle y cuánto
cobrar —nada si ya está pagado por transferencia—. El mensaje **se arma en el
servidor** a partir de lo que hay en la base, no de lo que haya en pantalla, y
el texto se edita en *Configuración → Envío y zonas* con etiquetas entre llaves
(`{direccion}`, `{mapa}`, `{cobrar}`…).

El pedido guarda a quién se le asignó y cuándo, con el nombre y el teléfono
copiados: si el repartidor deja de trabajar y se borra su ficha, el pedido
sigue diciendo quién lo llevó. Cada despacho queda en el historial del pedido y
en la auditoría.

Un pedido de retiro en tienda no se puede despachar, y solo se ofrecen los
repartidores activos; ambas cosas se comprueban en el servidor.

### Regla del pago

**Un pago nunca pasa a aprobado porque el cliente suba una imagen.** Subir el
comprobante solo mueve el pedido a «pago en revisión». La aprobación es una
acción explícita de alguien con el permiso `pagos.aprobar`, y queda registrada
con su nombre en el historial del pedido y en la auditoría.

Al rechazar hay que escribir un motivo de al menos 10 caracteres: es lo único
que le explica al cliente qué tiene que corregir. El pedido queda reservado y
puede subir otro comprobante.

---

## Temporadas y estilos

Una temporada se publica sola cuando está activa y la fecha de hoy cae en su
rango; si hay varias vigentes gana la de mayor prioridad. Eso ya funcionaba.
Lo que se le añadió es cómo se ve el sitio mientras dura.

### El color

El color de la campaña sale ahora en el bloque `:root` de la cabecera, que se
imprime en **todas** las páginas, como un juego de variables CSS:

| Variable | Qué es |
|----------|--------|
| `--temporada-color` | el color tal cual se eligió |
| `--temporada-rgb` | sus componentes, para componer `rgba()` |
| `--temporada-claro` / `--temporada-suave` / `--temporada-medio` | mezclas con blanco |
| `--temporada-fuerte` | la versión oscura, para texto sobre fondo claro |
| `--temporada-contraste` | blanco o tinta, lo que se lea sobre el color |

Las mezclas se calculan en PHP y no con `color-mix()` para que el tema se vea
igual en cualquier navegador. `--temporada-contraste` sale de la luminancia
relativa: con un amarillo brillante da tinta oscura y con un vino oscuro da
blanco, sin que haya que acordarse de cambiarlo.

El tema **complementa** el diseño; no lo sustituye. Solo se tiñen la franja
bajo la barra de navegación, la cinta de la campaña y algunos acentos finos.

### Los estilos

Cada temporada puede llevar un estilo de una lista cerrada (`Temporadas::ESTILOS`):
flores amarillas, San Valentín, primavera, Día de las Madres, Navidad,
Halloween, Año Nuevo y verano. «Ninguno» deja solo el color.

Un estilo es un nombre, un icono para el panel, las formas SVG que caen y la
que sale al interactuar. Añadir uno nuevo son dos pasos: una entrada en esa
constante y, si se quiere afinar su ritmo, un bloque en
`assets/css/temporada.css`.

La lista es cerrada a propósito: el identificador se traduce en una clase CSS y
en unos dibujos concretos, así que aceptar cualquier texto solo produciría
temporadas sin animación y sin forma de saber por qué.

### Las animaciones

Van en una capa fija que no recibe clics, por debajo de la barra y de los
modales, con el contenido recortado para que nunca aparezca desplazamiento
horizontal. Las partículas se reparten en dos franjas laterales estrechas y
dejan libre el centro, que es por donde pasan los títulos, los botones y las
fichas.

Todo el movimiento es `transform` y `opacity`, que el navegador resuelve en la
GPU: no hay ni un cálculo por fotograma. El JavaScript solo crea los elementos
y les pone unas variables; la animación entera la lleva el CSS.

- 14 partículas en escritorio, 10 en tablet y 6 en el teléfono — la hoja de
  estilos esconde las que sobran, así que el teléfono no llega a pintarlas.
- Al añadir al carrito, marcar un favorito o llegar a una página con aviso de
  éxito sale un pequeño estallido desde ese punto. Dura menos de un segundo,
  se borra solo y no se repite más de una vez cada 400 ms.
- Con `prefers-reduced-motion: reduce` no se crea ninguna: el color sigue, el
  movimiento no.
- Con la pestaña en segundo plano se pausan.

Los dibujos son SVG en línea, no emoji ni imágenes: se ven nítidos en cualquier
pantalla, pesan unos cientos de bytes, toman el color del tema con
`fill: currentColor` y no añaden ni una petición. Solo se mandan las tres o
cuatro formas que usa el estilo vigente, no las doce.

El CSS y el JS del tema **solo se cargan cuando hay una temporada vigente con
estilo**: fuera de campaña la página no descarga nada de esto.

---

## Auditoría

Registra quién hizo qué, cuándo, desde qué IP y con qué resultado: registros,
inicios de sesión (correctos y fallidos), cambios de perfil y contraseña,
pedidos, aprobaciones y rechazos de pago, altas y bajas de productos, cambios de
configuración, permisos, respaldos y restauraciones.

Es de **solo escritura desde la aplicación**: no hay ninguna ruta que borre ni
edite sus filas. Consultarla exige el permiso `auditoria.ver` y se puede
exportar a CSV.

Nunca guarda contraseñas, hashes ni tokens: `Auditoria::limpiarDetalles()`
filtra esas claves antes de serializar, aunque vinieran en el formulario de la
acción registrada.

---

## Respaldos

*Panel → Respaldos*. Requiere `respaldos.ver`; crear y descargar,
`respaldos.crear`; restaurar, `respaldos.restaurar` (solo super administrador).

### Crear un respaldo

Botón «Crear respaldo ahora». Usa `mysqldump` si `MYSQLDUMP_BIN` apunta a un
binario existente; si no, un volcador escrito en PHP que escribe en streaming
(no carga tablas enteras en memoria) y funciona en cualquier hosting.

Por consola, el equivalente clásico:

```bash
mysqldump -u root -p flowers_anto > respaldo.sql
```

### Subir y restaurar son dos acciones distintas

Subir un archivo **no lo aplica**. Al subirlo se valida que sea texto plano, que
defina tablas y que no contenga sentencias que no aparecen en un volcado normal
(`GRANT`, `CREATE USER`, `DROP DATABASE`, `INTO OUTFILE`…). Si las trae, se
rechaza.

Restaurar exige escribir la palabra **RESTAURAR** y, antes de tocar nada:

1. crea una copia automática del estado actual (queda como
   «Previo a restaurar» en el listado, por si hay que volver atrás)
2. comprueba que el archivo no cambió desde que se registró (hash SHA-256)
3. ejecuta la restauración
4. verifica que las tablas del sistema existan al terminar
5. anota el resultado en la auditoría

Si algo falla a mitad, el mensaje dice en qué sentencia se detuvo y cuál es el
archivo de la copia previa.

---

## Cómo acceder al panel

`{APP_URL}/admin/` — o el enlace «Panel» del menú de usuario.

No hay un formulario de acceso aparte: el panel usa el mismo inicio de sesión
que la tienda (`cuenta/entrar.php`). Quien tenga un rol de personal ve el enlace
al panel; el resto, no.

---

## Qué se administra sin tocar código

- **Marca:** nombre, eslogan, logo, favicon, colores, moneda
- **SEO:** descripción para buscadores e imagen al compartir
- **Portada:** palabra de fondo, títulos, botón, imagen de respaldo, autoplay
- **Nosotros:** título, texto e imagen
- **Contacto:** WhatsApp con su mensaje, teléfono, correo, dirección, horario y redes
- **Pedidos:** activar o desactivar la web y WhatsApp, permitir invitados y
  retiro, aceptar efectivo y franjas horarias
- **Envío y zonas:** zonas de entrega con su propio precio, agrupadas en dentro
  y fuera de Managua; costo por defecto, umbral de envío gratis y si se pide el
  enlace de ubicación en el checkout
- **Avisos por correo:** correo del equipo, qué avisos recibe, el texto que lee
  el cliente en cada estado del pedido y una prueba de envío real
- **Repartidores:** nombre, WhatsApp, vehículo y disponibilidad, más el texto
  del mensaje que se les manda
- **Transferencias:** varias cuentas bancarias con banco, titular, número, tipo,
  moneda e identificación, más las instrucciones para el cliente
- **Créditos del desarrollador:** nombre, logo, descripción, enlace y visibilidad
- **Catálogo:** productos con varias fotos, categorías, temporadas y galería

---

## Ejecución local

```bash
php -S localhost:8000
```

Con `APP_BASE_URL` vacío y `APP_URL=http://localhost:8000`.

No hay proceso de build: los archivos se sirven tal cual.

---

## Despliegue

1. Sube todo **menos** `.env`, `config.local.php`, `storage/` y `.git/`
2. Crea la base de datos vacía en el panel del hosting
3. Crea `.env` (o `config.local.php`) en el servidor con los datos reales
4. Pon `APP_ENTORNO=prod`
5. Ejecuta `php db/migrar.php`, o abre `instalar.php` y pulsa «Aplicar migraciones»
6. Crea la cuenta de administración desde `instalar.php`
7. Comprueba que `uploads/` y `storage/` tengan permiso de escritura
8. Sirve el sitio por HTTPS: la cookie de sesión se marca como segura sola
9. Configura `MAIL_TRANSPORTE=smtp` para que los correos lleguen de verdad
10. Cambia la cuenta bancaria de ejemplo por la real

---

## Notas de seguridad

- Contraseñas con `password_hash()` y re-hasheo automático al cambiar el algoritmo
- Todas las escrituras exigen sesión y token CSRF
- Los permisos se comprueban en el servidor antes de cada acción, no en la interfaz
- Sesión con cookies `HttpOnly`, `SameSite=Lax`, `Secure` bajo HTTPS y rotación
  del identificador cada 30 minutos y en cada inicio de sesión
- Consultas preparadas reales (`ATTR_EMULATE_PREPARES = false`) en todo el proyecto
- Las subidas se validan por el **contenido** del archivo, no por su extensión;
  el nombre final lo genera el servidor
- Los comprobantes viven fuera del alcance del navegador y se sirven por una
  ruta que comprueba permisos
- Límites de intentos en acceso, registro, recuperación, seguimiento y pedidos
- Mensajes de error genéricos donde revelar de más permitiría enumerar cuentas
  o pedidos
- `uploads/` tiene el motor de PHP desactivado por `.htaccess`
- Cabeceras `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` y
  `Content-Security-Policy` en las páginas públicas
- Los números de cuenta bancaria no están en el código: viven en la base de
  datos y solo se muestran al cliente en la página de su pedido

---

## Nota para quien siga el código

Los formularios que se envían con `fetch` deben leer el identificador con
`form.getAttribute('id')` y los valores con `form.querySelector('[name="…"]')`.
El navegador expone los campos de un formulario como propiedades del propio
formulario, y esas propiedades tapan a las nativas: en un formulario con un
campo `name="id"`, escribir `form.id` devuelve el `<input>`, no el identificador.

Para leer identificadores del `$_POST` o del `$_GET` se usa `identificador()`,
no `entero()`. En `entero()` el mínimo se aplica al valor recibido, así que
`entero('id', 1)` convertiría un `id=0` (que significa «crear») en un `1` (que
significa «editar el registro 1»).
