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
6. **`APP_ENTORNO=prod`** antes de publicar, para que los errores no salgan en pantalla.
7. **HTTPS**, para que la cookie de sesión se marque como segura.
8. **Zonas de envío con precios reales** — *Configuración → Envío y zonas*. La
   migración crea las ciudades que ya estaban configuradas más unas zonas de
   ejemplo de Managua, todas con el costo de envío que hubiera. Hay que poner el
   precio de cada una.
9. **Correo del equipo** — *Configuración → Avisos por correo*. Si se deja
   vacío se usa el de contacto.

---

## 10. Segunda entrega: envío por zonas, ubicación y avisos

Cinco cambios pedidos después de la primera entrega, todos sobre el código que
ya estaba funcionando.

**1. El envío cuesta según la zona.** Tabla `zonas_envio` con nombre,
descripción, precio y si está dentro o fuera de Managua. El checkout las agrupa
en esos dos bloques y el resumen se actualiza al elegir. El precio se vuelve a
leer de la base al registrar el pedido: enviar `envio=1` en el POST no cambia
lo que se cobra (probado). Cada pedido guarda además el nombre de la zona, para
que renombrarla o borrarla no reescriba el historial.

**2. Enlace de ubicación para el repartidor.** Campo opcional donde el cliente
pega su punto de Google Maps, Waze, Apple Maps, OpenStreetMap o what3words, o
unas coordenadas, que se convierten en un enlace de Google Maps. La lista de
servicios es cerrada: el enlace lo abre alguien del equipo desde su teléfono, y
aceptar cualquier dirección habría convertido el formulario en una vía cómoda
para colarle un enlace a donde sea. En el panel aparece como botón «Abrir en
Google Maps» junto a la dirección escrita.

**3. Libreta de direcciones.** Quien tiene cuenta puede guardar la dirección al
pedir y reutilizarla con un toque; la que marque como predeterminada llega ya
elegida al checkout, con su zona. Se administran en *Mi cuenta → Mis
direcciones*. Guardar la misma dirección en la misma zona actualiza la que ya
existe en vez de acumular copias.

**4. Correo personalizado en cada cambio de estado.** El texto de cada estado se
edita en *Configuración → Avisos por correo*, y cada estado puede dejar de
enviar correo sin desaparecer del flujo. Al texto fijo se le añade la nota que
escriba quien atiende el pedido, y en los estados de entrega en curso también la
dirección y el enlace del mapa.

**5. Aviso al equipo.** Un correo al entrar un pedido —con el cliente, el
teléfono, la entrega, el enlace de ubicación, el detalle y un botón al panel— y
otro al subirse un comprobante, con el monto, el banco y la referencia que
declaró el cliente. Van al correo configurado, aparte del correo al cliente: si
el buzón del equipo rebota, el cliente igual recibe su confirmación.

**Y la dirección escrita bajo el mapa de la portada**, con el horario y el
teléfono y un botón «Cómo llegar». El mapa incrustado no le sirve a todo el
mundo.

### Cómo se probó

Pedido real por cada zona verificando lo que quedó en la base; intento de
falsear el precio desde el POST (se cobró el de la base); enlace de mapa a un
dominio no permitido (rechazado, sin crear el pedido); retiro en tienda (sin
zona, sin envío y sin mapa); umbral de envío gratis (el navegador y el servidor
coinciden); alta, edición, ocultado y borrado de zonas desde el panel, incluido
el nombre duplicado; recorrido completo de estados comprobando qué correos
salen y cuáles no según `avisar_cliente`; y el checkout en un navegador real
—Chromium— viendo el total moverse al cambiar de zona y el formulario llenarse
al tocar una dirección guardada.

Todo repetido en las dos configuraciones de ruta: en la raíz del dominio y en
una subcarpeta (`APP_BASE_URL=/webANTO`), que es como corre en XAMPP.

---

## 11. Tercera entrega: correcciones y despacho al motorizado

**Tres errores, uno de ellos con pérdida de datos.**

El primero explicaba a los otros dos. Al añadir las pestañas *Envío y zonas* y
*Avisos por correo* quedaron fuera de la lista blanca que decide qué grupo de
ajustes guarda el formulario. Su POST caía en el grupo por defecto —*Marca*— y
guardaba **los campos de Marca con el formulario vacío**: el correo de avisos no
se guardaba nunca, la página volvía al inicio de configuración, y de paso se
borraban el nombre de la tienda y el eslogan. Como el correo de avisos nunca
llegaba a guardarse, tampoco salía el aviso de pedido nuevo.

Arreglado, y arreglado para que no se repita: el valor por defecto del grupo ya
no es *Marca* sino vacío, y un grupo que no se reconoce **no escribe nada** en
la base y lo dice. Añadir una pestaña y olvidarse de la lista ya no puede
borrar los ajustes de otra.

*Si actualizas desde la versión anterior, revisa el nombre de la tienda y el
eslogan en Configuración → Marca: pueden haber quedado vacíos.*

El segundo era la vista previa del comprobante. La cabecera
`Content-Security-Policy` permitía imágenes de `self`, `data:` y `https:`, pero
no `blob:` — que es justo lo que produce `URL.createObjectURL()` para enseñar el
archivo antes de subirlo. El navegador bloqueaba la miniatura y quedaba una
imagen rota, con el texto alternativo desbordado. Se añadió `blob:` a `img-src`
y la miniatura cae a un icono si aun así no se puede pintar.

El tercero estaba en la misma zona: el `<input type=file>` era un punto de 1×1
posicionado en absoluto dentro de un contenedor sin `position`, así que acababa
en cualquier parte. Al enviar sin archivo, el navegador saltaba a ese punto
invisible para enseñar un aviso que no se veía. Ahora el input cubre el recuadro
y el globo señala lo que hay que rellenar. De paso, esos mensajes del navegador
salen en español: antes decían «Please select a file» aunque la web esté en
español, porque los escribe el navegador según *su* idioma, no el de la página.

**Correos con la marca.** Dejaron de ser un aviso de sistema: llevan el logo, el
nombre, el eslogan y los colores configurados, con los datos de contacto reales
en el pie. El texto sobre la banda de color se decide con la luminancia del
color primario, así que se lee igual con un rosa pastel que con un vino oscuro,
sin tener que acordarse de cambiarlo al cambiar de marca. Si el logo es un SVG
se escribe el nombre: Gmail y Outlook no pintan SVG y habría salido roto. El
nombre acompaña siempre al logo, porque las imágenes llegan bloqueadas por
defecto. Están hechos con tablas y estilos en línea, que es lo único que pintan
igual todos los clientes de correo.

**Y el correo que no salía ni avisaba.** El transporte `log` escribe en un
archivo y no envía nada, pero se comportaba igual que uno bien configurado: en
silencio. Ahora la pestaña *Avisos por correo* dice qué transporte está activo,
avisa en rojo cuando no sale ningún correo, y tiene un botón que manda una
prueba de verdad y cuenta el error concreto si falla —«SMTP_HOST está vacío»,
«la función mail() devolvió error»— en vez de no hacer nada.

**Repartidores y despacho.** Tabla propia, no usuarios: al motorizado no le hace
falta cuenta ni entrar al panel. Se registran con nombre, WhatsApp, vehículo y
disponibilidad, y el panel enseña cuántas entregas lleva cada uno y la fecha de
la última.

Desde la ficha del pedido se elige a quién mandársela y se abre WhatsApp con la
dirección, la zona, la referencia, el enlace del mapa, el detalle y cuánto
cobrar —nada si ya se pagó por transferencia, que evita que le vuelva a cobrar
al cliente—. El mensaje se arma en el servidor con lo que hay en la base, y su
texto se edita desde la configuración con etiquetas entre llaves.

El pedido guarda a quién se le asignó, con nombre y teléfono copiados, para que
borrar la ficha del repartidor no borre el historial. Un pedido de retiro en
tienda no se despacha y solo se ofrecen los repartidores activos: las dos cosas
se comprueban en el servidor, no escondiendo el botón.

### Cómo se probó

Reproducido el borrado de datos antes de tocar nada, y verificado después que
las dos pestañas guardan, se quedan donde estaban y que un grupo inventado no
escribe nada. Miniatura del comprobante comprobada en un navegador real
(`naturalWidth > 0`), y el salto de la página medido antes y después. Alta,
edición, desactivación y borrado de repartidores; teléfono inválido rechazado;
despacho completo revisando el mensaje que se abre, la asignación en la base y
el apunte en el historial. Retiro en tienda, repartidor inactivo y repartidor
inexistente: los tres rechazados. Un empleado de catálogo recibe 403 en la
página, en el despacho y en el alta enviada a mano, y las tres denegaciones
quedan en la auditoría. Los correos revisados con marca clara y oscura, con
logo PNG y con SVG.

**Versión en el CSS y el JS.** Al probar la corrección anterior salió otro
problema, más callado: las hojas de estilo y los scripts se enlazaban sin
ninguna versión, así que al reemplazar los archivos en el hosting el navegador
seguía sirviendo los que ya tenía en caché. La web se comportaba como antes
—con los errores incluidos— y no había forma de saber que lo que se estaba
viendo era la versión vieja. Ahora cada enlace lleva detrás la fecha de
modificación del archivo (`app.js?v=1788052669`), que cambia sola al subir una
versión nueva y obliga al navegador a descargarla.

---

## 12. Estilos de temporada

Rama `EstilosdeTemporada`. Dos objetivos: que el color de la campaña se aplique
de verdad, y que cada temporada pueda vestir el sitio.

### Por qué el color no llegaba

No era un problema de guardado ni de recuperación: el color se guardaba bien y
se leía bien. Se perdía después.

`--temporada-color` se escribía en un único sitio —el atributo `style` de la
tira de temporada de la portada— y esa sección está detrás de
`if ($temporada && !empty($temporada['productos']))`. Una temporada recién
creada, sin productos vinculados todavía, no pintaba nada: ni la sección ni el
color. Y aun con productos, el color solo teñía el fondo de esa tira; al resto
del sitio no llegaba nunca, porque los datos que se le pasan al carrusel de la
portada incluyen el título y la palabra de la campaña pero no su color.

Reproducido antes de tocar nada: temporada vigente, `#F4C400` en la base, y el
color apareciendo **cero veces** en el HTML de la portada.

La corrección va donde corresponde: la cabecera —que se imprime en todas las
páginas— resuelve la temporada vigente y emite el color en el bloque `:root`
que ya existía para los colores de la marca, junto con cinco variables
derivadas. La tira de la portada sigue funcionando igual; ahora es una de las
cosas que usan el color, no la única que lo tiene.

Para no pagar una consulta por página se separó `temporadaVigente()` —solo la
fila, memoizada por petición, guardando también el «no hay ninguna»— de
`temporadaActiva()`, que sigue trayendo los productos para la portada.

### Los estilos

Una columna, `temporadas.estilo`, y un catálogo cerrado en el código: cada
estilo es un nombre, un icono, las formas que caen y la que sale al
interactuar. La migración propone estilo a las temporadas que ya existen
mirando su nombre —«Navidad» → navidad, «San Valentín» → san_valentin— y solo
toca las que lo tienen vacío, así que una elección hecha a mano nunca se pisa.

Las animaciones son CSS puro sobre `transform` y `opacity`. El JavaScript crea
catorce elementos, les pone unas variables y no vuelve a intervenir: no hay
trabajo por fotograma. Van en franjas laterales estrechas, con el centro
despejado, en una capa que no recibe clics y que recorta lo que se salga para
que no aparezca desplazamiento horizontal. En el teléfono la hoja de estilos
esconde las que sobran —seis en vez de catorce— y con `prefers-reduced-motion`
no se crea ninguna.

El detalle al interactuar —un puñado de formas que suben desde el punto tocado
y se desvanecen— se engancha por su cuenta a los favoritos, al formulario de
añadir al carrito y al aviso de éxito que el servidor ya pinta al confirmar un
pedido. No se tocó ni una línea de la lógica del carrito: si mañana cambia,
esto deja de dispararse, pero no la rompe.

### Un arreglo de paso

El `INSERT`/`UPDATE` de temporadas usaba una lista de columnas escrita a mano y
`array_values($datos)` como valores. Añadir un campo arriba y olvidar la lista
habría guardado cada dato en la columna equivocada —el mismo tipo de fallo que
borró el nombre de la tienda en la entrega anterior—. Ahora las columnas salen
de las claves del propio array y no pueden desalinearse.

### Cómo se probó

El color, ciclo completo por el panel: guardar `#F4C400`, recargar, cambiarlo a
`#7E57C2` comprobando que los derivados y el contraste cambian con él, y volver
al primero. Con productos vinculados y sin ellos.

Los estilos, en un navegador real: los seis principales, contando partículas y
midiendo que ninguno provoca desplazamiento horizontal; el teléfono con seis en
vez de catorce; `prefers-reduced-motion` sin capa pero con color; la temporada
desactivada y la temporada fuera de fechas sin capa, sin color y sin clase en
el cuerpo. El estallido: diez piezas al marcar un favorito, cero al segundo y
medio, y seis clics seguidos sin pasar de diez. Las doce formas se revisaron
dibujadas en grande y a 26 px; el girasol, la flor y el murciélago se
rehicieron porque no se leían.

Y la regresión de siempre —compra como invitado, comprobante, seguimiento,
zonas de envío, redirección externa, 27 páginas sin un solo aviso de PHP— en
la raíz y en subcarpeta.
