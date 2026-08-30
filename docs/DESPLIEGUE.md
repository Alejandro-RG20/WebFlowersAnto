# Guía de despliegue

Dos partes: primero probarlo en tu computadora, después subirlo a un hosting.
El proceso es el mismo en los dos sitios; lo único que cambia son los datos de
conexión y de dónde sale la configuración.

---

# Parte 1 — Probarlo en tu computadora (XAMPP)

## 1. Instalar XAMPP

Descarga XAMPP con **PHP 8.1 o superior** desde
<https://www.apachefriends.org> e instálalo.

Abre el **panel de control de XAMPP** y arranca:

- **Apache**
- **MySQL**

Los dos deben quedar en verde. Si Apache no arranca, casi siempre es que otro
programa ocupa el puerto 80 (Skype, IIS): cámbialo en *Config → httpd.conf*.

## 2. Copiar el proyecto

Descarga el proyecto de la rama `claude/flowers-anto-ecommerce-9lzc0z` (botón
**Code → Download ZIP** en GitHub) y descomprímelo en:

```
C:\xampp\htdocs\webANTO
```

Debe quedar así — `index.php` directamente dentro de `webANTO`:

```
C:\xampp\htdocs\webANTO\index.php
C:\xampp\htdocs\webANTO\includes\
C:\xampp\htdocs\webANTO\admin\
...
```

> Si al descomprimir te queda `webANTO\WebFlowersAnto-claude-...\index.php`,
> mueve el contenido una carpeta hacia arriba.

## 3. Crear la base de datos vacía

Abre <http://localhost/phpmyadmin>, pestaña **SQL**, y ejecuta:

```sql
CREATE DATABASE flowers_anto CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**Solo eso.** No hay que importar ningún archivo `.sql`: las tablas las crea el
instalador en el paso 5.

## 4. Configurar la conexión

En la carpeta del proyecto, copia `.env.example` y renombra la copia como `.env`.

> En Windows el Explorador no deja crear archivos que empiecen por punto desde
> el menú «Nuevo». Copia y pega `.env.example`, y renómbralo escribiendo
> `.env.` — Windows quita el punto final solo. O usa el Bloc de notas y guarda
> con el tipo «Todos los archivos».
>
> **Alternativa más cómoda:** copia `config.example.php` como
> `config.local.php` y edita ese en vez del `.env`. Funciona igual.

Abre `.env` y deja estas líneas así (lo demás no hace falta tocarlo todavía):

```ini
APP_ENTORNO=dev
APP_URL=http://localhost/webANTO
APP_BASE_URL=/webANTO

DB_HOST=localhost
DB_PORT=3306
DB_NAME=flowers_anto
DB_USER=root
DB_PASS=

MAIL_TRANSPORTE=log
```

Y ya está: no hay ninguna clave más que generar.

## 5. Instalar

Abre en el navegador:

```
http://localhost/webANTO/instalar.php
```

Verás dos pasos:

1. **Base de datos** — pulsa **«Aplicar migraciones»**. Crea las 25 tablas.
2. **Cuenta de administración** — pon tu nombre, correo, teléfono y una
   contraseña (mínimo 8 caracteres, con letras y números).

Pulsa **«Crear cuenta y terminar»**.

> **No hay usuario ni contraseña de fábrica.** La cuenta que crees aquí es la
> primera y es super administrador. En cuanto exista, esta página se cierra
> sola: ya no vuelve a pedir datos.

## 6. (Opcional) Datos de ejemplo

Para ver la web con productos y pedidos en vez de vacía, abre la consola de
XAMPP en la carpeta del proyecto y ejecuta:

```
C:\xampp\php\php.exe db\seed.php --pedidos
```

Crea 10 arreglos, una galería, una cuenta bancaria de ejemplo y 5 pedidos en
distintos estados. Todo queda marcado (`[DEMO]` en los productos, `FA-DEMO-*`
en los pedidos), así que nunca se confunde con datos reales.

Para borrarlo después:

```
C:\xampp\php\php.exe db\seed.php --limpiar
```

## 7. Probar

| | Dirección |
|---|---|
| **Tienda** | <http://localhost/webANTO/> |
| **Panel** | <http://localhost/webANTO/admin/> |

Entra al panel con el correo y la contraseña del paso 5.

### Qué probar

1. **Catálogo** — filtra por categoría, busca, ordena por precio
2. **Ficha de producto** — pasa las fotos con las flechas, toca una para ampliarla
3. **Favoritos** — toca el corazón; se guarda aunque cierres el navegador
4. **Carrito** — añade, cambia cantidades, quita
5. **Comprar sin cuenta** — «Completar pedido», rellena y confirma
6. **Comprobante** — en la página del pedido, sube cualquier foto o PDF
7. **Panel → Pedidos** — abre el pedido, mira el comprobante, **recházalo** con
   un motivo, vuelve a la página del cliente, sube otro, y ahora **apruébalo**
8. **Panel → Configuración** — cambia el nombre, los colores, el WhatsApp
9. **Panel → Respaldos** — crea uno, descárgalo

### Los correos

Con `MAIL_TRANSPORTE=log` **no se envía nada de verdad**: los correos se
escriben en un archivo. Ábrelo para verlos:

```
C:\xampp\htdocs\webANTO\storage\logs\correos.log
```

Ahí aparecen la bienvenida, la recuperación de contraseña, la confirmación del
pedido y cada cambio de estado. Es lo que quieres en local: puedes probar la
recuperación de contraseña copiando el enlace de ese archivo y pegándolo en el
navegador.

### Si algo falla

Con `APP_ENTORNO=dev` los errores salen en pantalla con el detalle completo.
También se guardan en `storage/logs/php.log`.

| Síntoma | Causa habitual |
|---|---|
| «El sitio no está disponible» | MySQL apagado, o `DB_NAME`/`DB_USER` mal en `.env` |
| Las imágenes no cargan | `APP_BASE_URL` no coincide con la carpeta real |
| «Falta config…» | `.env` no se creó, o se guardó como `.env.txt` |
| El panel da 403 | Entraste con una cuenta de cliente, no con la de administración |

---

# Parte 2 — Subirlo a InfinityFree

Tu intuición es correcta: **es el mismo proceso, cambiando los datos de
conexión y creando la base de datos de cero**. Pero InfinityFree tiene cuatro
particularidades que conviene saber antes de empezar.

## Lo que cambia respecto a local

| | En tu PC (XAMPP) | En InfinityFree |
|---|---|---|
| `DB_HOST` | `localhost` | **NO es localhost.** Es el que muestre el panel, del estilo `sqlXXX.infinityfree.com` |
| `DB_NAME` | `flowers_anto` | El que asigne el panel, con prefijo: `if0_XXXXXXXX_flowersanto` |
| `DB_USER` | `root` | También con prefijo: `if0_XXXXXXXX` |
| `DB_PASS` | vacía | La que pusiste al crear la base |
| Consola / SSH | disponible | **no hay** → se usa `instalar.php` |
| `mail()` de PHP | — | **desactivado** → hay que usar SMTP |

## 1. Crear la cuenta y el sitio

En <https://infinityfree.com> crea la cuenta y un sitio. Te dará un dominio
tipo `tusitio.infinityfreeapp.com`.

Entra al **panel de control** del sitio.

## 2. Crear la base de datos

*Panel → MySQL Databases*. Crea una base nueva y **apunta los cuatro datos**
que te muestra:

- Hostname (algo como `sqlXXX.infinityfree.com`)
- Database name (`if0_XXXXXXXX_algo`)
- Username (`if0_XXXXXXXX`)
- Password (la que elijas)

No importes nada aquí. La base se queda vacía.

## 3. Subir los archivos

*Panel → Online File Manager* (o por FTP con FileZilla; los datos están en
*Panel → FTP Accounts*).

Sube **todo el contenido** del proyecto dentro de la carpeta **`htdocs`**.

```
htdocs/index.php
htdocs/includes/
htdocs/admin/
htdocs/assets/
...
```

Si InfinityFree ya creó un `index2.html` o similar de bienvenida, bórralo.

> Si subes un ZIP y lo descomprimes desde el File Manager, revisa que no te
> quede una carpeta de más: `index.php` tiene que estar directamente en `htdocs`.

## 4. Configurar la conexión

El File Manager de InfinityFree puede dar problemas con archivos que empiezan
por punto, así que aquí es mejor usar la otra opción:

Copia `config.example.php` como **`config.local.php`** (dentro de `htdocs`) y
edítalo con los datos del paso 2:

```php
return [
    'APP_ENTORNO'  => 'prod',
    'APP_BASE_URL' => '',
    'APP_URL'      => 'https://tusitio.infinityfreeapp.com',

    'DB_HOST'    => 'sqlXXX.infinityfree.com',
    'DB_PORT'    => '3306',
    'DB_NAME'    => 'if0_XXXXXXXX_flowersanto',
    'DB_USER'    => 'if0_XXXXXXXX',
    'DB_PASS'    => 'la_que_pusiste',
    'DB_CHARSET' => 'utf8mb4',

    'MAX_UPLOAD_MB'      => '3',
    'MAX_COMPROBANTE_MB' => '3',
    'MAX_RESPALDO_MB'    => '16',

    'MAIL_TRANSPORTE'       => 'smtp',
    'MAIL_REMITENTE'        => 'tucorreo@gmail.com',
    'MAIL_REMITENTE_NOMBRE' => 'Flowers Anto',
];
```

Tres cosas importantes de este archivo:

1. **`APP_ENTORNO` en `prod`** — oculta los errores técnicos al visitante.
   Déjalo en `dev` solo mientras depuras un problema, y vuélvelo a `prod`.
2. **`APP_BASE_URL` vacío** — porque el sitio está en la raíz del dominio, no
   en una subcarpeta como en local.
3. **Los MB bajos** — el plan gratuito limita el tamaño de subida. Con 3 MB
   caben de sobra las capturas de comprobante.

## 5. Instalar

Abre:

```
https://tusitio.infinityfreeapp.com/instalar.php
```

Mismos dos pasos que en local: **aplicar migraciones** y **crear tu cuenta**.

> Aquí es donde se nota que no hay consola: en local podías ejecutar
> `php db/migrar.php`, y aquí no. Por eso el instalador hace lo mismo desde el
> navegador. Es la única forma de crear las tablas en este hosting.

Si el primer paso da error de conexión, revisa los cuatro datos del paso 2 —
casi siempre es que se puso `localhost` en `DB_HOST`.

## 6. Configurar el correo (importante)

**InfinityFree desactiva la función `mail()` de PHP.** Si dejas
`MAIL_TRANSPORTE=mail`, los clientes no recibirán ni la confirmación del pedido
ni el enlace de recuperación de contraseña.

Configura un SMTP. Con Gmail:

1. Activa la verificación en dos pasos en tu cuenta de Google
2. Crea una **contraseña de aplicación** en
   <https://myaccount.google.com/apppasswords>
3. En `config.local.php`:

```php
    'MAIL_TRANSPORTE' => 'smtp',
    'SMTP_HOST'       => 'smtp.gmail.com',
    'SMTP_PORT'       => '587',
    'SMTP_SEGURIDAD'  => 'tls',
    'SMTP_USUARIO'    => 'tucorreo@gmail.com',
    'SMTP_PASSWORD'   => 'la_contraseña_de_aplicación',
```

**Compruébalo en cuanto instales:** usa «Olvidé mi contraseña» con un correo
tuyo y mira si llega. Algunos planes gratuitos también bloquean las conexiones
SMTP salientes; si es tu caso, tienes dos salidas: pasar a un hosting de pago,
o dejarlo sin correo.

> Sin correo la tienda **sigue funcionando**: el cliente ve la confirmación y el
> estado de su pedido en pantalla, y el enlace de seguimiento se le puede pasar
> por WhatsApp. Lo que se pierde son los avisos automáticos y la recuperación de
> contraseña por correo.

## 7. Activar HTTPS

*Panel → SSL/TLS Certificates*. Emite el certificado gratuito y espera a que
se active (puede tardar unos minutos).

Cuando funcione, cambia `APP_URL` a `https://…`. La cookie de sesión se marca
como segura sola en cuanto detecta HTTPS.

## 8. Dejarlo listo para el cliente

Desde *Panel → Configuración*:

1. **Marca** — nombre, logo, favicon, colores
2. **Contacto** — WhatsApp (solo dígitos con código de país: `50588887777`),
   teléfono, correo, dirección, horario, redes
3. **Transferencias** — **borra la cuenta bancaria de ejemplo** y pon las reales
4. **Pedidos** — formas de pedir, invitados, retiro, franjas horarias
5. **Envío y zonas** — **el precio de cada zona**. La migración deja creadas las
   ciudades que ya tenías más unas zonas de ejemplo de Managua, todas con el
   costo de envío que hubiera: ponles el precio real, borra las que no uses y
   añade las que falten. Aquí también se activa el enlace de ubicación del
   checkout y el umbral de envío gratis.
6. **Avisos por correo** — el correo donde quieres recibir los pedidos nuevos y
   los comprobantes, y el texto que lee el cliente en cada estado.
   **Usa el botón «Enviar correo de prueba»**: si no llega, no llegará ninguno.
7. **Repartidores** — nombre y WhatsApp de cada motorizado, para poder mandarles
   la entrega desde el pedido
8. **Créditos** — nombre, logo, descripción y enlace de ANDRODEV

Y desde *Panel → Productos*, sube los arreglos reales.

Si dejaste los datos de ejemplo del paso 6 de la parte 1, bórralos: los
productos `[DEMO]` uno a uno desde el panel, y los pedidos `FA-DEMO-*` no
molestan pero conviene quitarlos.

## 9. Comprobación final

- [ ] La tienda abre por HTTPS
- [ ] `instalar.php` ya no deja crear cuentas (dice que está instalado)
- [ ] Entras al panel con tu cuenta
- [ ] Un pedido de prueba llega hasta «Pago en revisión»
- [ ] El envío cambia de precio al elegir otra zona en el checkout
- [ ] Te llega el correo de «Pedido nuevo» al buzón del equipo
- [ ] El correo de prueba llega con el logo y los colores de la floristería
- [ ] Desde un pedido puedes mandarle la entrega a un repartidor por WhatsApp
- [ ] El comprobante se sube y se ve desde el panel
- [ ] Aprobar y rechazar funcionan
- [ ] Los correos llegan (o sabes que no van y es a propósito)
- [ ] La cuenta bancaria es la real, no la de ejemplo
- [ ] `APP_ENTORNO` está en `prod`
- [ ] Abriendo `tusitio.infinityfreeapp.com/storage/` **no** se lista nada

---

## Cosas que ya están resueltas y no tienes que tocar

- **Respaldos sin `mysqldump`** — InfinityFree no da acceso a programas del
  sistema. El proyecto trae su propio volcador en PHP y lo usa solo, sin
  configurar nada. *Panel → Respaldos* funciona igual que en local.
- **Protección de `storage/`** — los comprobantes y los respaldos están fuera
  del alcance del navegador por `.htaccess`, que InfinityFree respeta.
- **`uploads/` sin ejecución de PHP** — aunque alguien lograra colar un archivo
  raro, ahí no se ejecuta código.
- **Migraciones futuras** — si más adelante se añaden tablas, se aplican desde
  *Panel → Base de datos* sin tocar phpMyAdmin.

---

## Volver a empezar de cero

Si quieres reinstalar limpio (en local o en el hosting):

1. En phpMyAdmin: `DROP DATABASE flowers_anto;` y vuelve a crearla vacía
2. Borra `storage/comprobantes/*`, `storage/respaldos/*` y `storage/logs/*`
   (deja los archivos `.gitkeep` y `.htaccess`)
3. Borra `uploads/*` si quieres quitar también las fotos subidas
4. Abre `instalar.php` otra vez

Al quedar la base sin la tabla `usuarios`, el instalador vuelve a abrirse y
pide crear la cuenta de administración de nuevo.
