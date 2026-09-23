# Asistentes de IA — Flowers Anto v0.1

Guía técnica de los dos asistentes con inteligencia artificial y de los
cambios de seguridad que se hicieron al integrarlos. Rama: `FlowersAntoIA_v0.1`
(parte de `FlowersAnto_v.10`).

- **Asesora floral** (tienda): ayuda al cliente a elegir, responde dudas de
  entregas, pagos, horarios y políticas, añade al carrito y consulta pedidos.
- **AI Manager** (panel): responde con datos del negocio al personal
  autorizado y *prepara* cambios que una persona confirma.

Los dos son opcionales. Sin clave de API, o con la migración 022 sin aplicar,
no se muestran y la tienda funciona exactamente igual que antes.

---

## 1. Arquitectura

```
Navegador ──► api/asistente.php ──────┐            (tienda)
Navegador ──► admin/asistente-api.php ┤            (panel)
                                      ▼
                           IaAgente::responder()   bucle de herramientas
                                      │
               ┌──────────────────────┼─────────────────────────┐
               ▼                      ▼                         ▼
      ClaudeCliente (cURL)   IaHerramientasCliente      IaHerramientasAdmin
      api.anthropic.com      (solo lectura pública +    (lectura con permisos +
                              carrito del visitante)     propuestas)
                                      │                         │
                                      ▼                         ▼
                         Carrito, Pedidos, consultas    IaPropuestas ─► confirmación
                         preparadas del propio sitio    humana ─► Pedidos / productos
```

**La IA nunca escribe SQL ni toca la base de datos.** Solo puede pedir una
herramienta por su nombre con unos parámetros. Cada herramienta es código PHP
del sitio que valida los parámetros (`IaEntrada`), comprueba permisos y usa
las mismas clases y consultas preparadas que el resto de la tienda.

| Archivo | Qué hace |
|---------|----------|
| `includes/lib/ia/config.php` | `IaConfig`: lee las variables `AI_*`, decide si cada asistente está activo |
| `includes/lib/ia/cliente.php` | `ClaudeCliente`: HTTP a la Messages API; tiempo máximo, un reintento en 429/5xx, errores como `IaError` |
| `includes/lib/ia/agente.php` | `IaAgente` (bucle), `IaCaja` (interfaz de herramientas), `IaEntrada` (validación), `IaSesion`, `IaGuardia` |
| `includes/lib/ia/herramientas_cliente.php` | Herramientas de la asesora |
| `includes/lib/ia/herramientas_admin.php` | Herramientas del AI Manager |
| `includes/lib/ia/propuestas.php` | Propuestas de cambio: crear, confirmar, descartar, caducar |
| `includes/lib/ia/registro.php` | `IaRegistro`: escribe en `ai_action_logs` |
| `api/asistente.php` | Endpoint de la tienda |
| `admin/asistente-api.php`, `admin/asistente.php` | Endpoint y página del panel |
| `assets/js/asistente.js`, `assets/js/admin-asistente.js` | Interfaces |
| `db/migraciones/022_asistentes_ia.php` | Tablas `ai_action_logs` y `ai_pending_actions` |

### Por qué HTTP directo y no el SDK

El SDK oficial de PHP necesita Composer y un cliente HTTP PSR-18 (Guzzle o
Symfony) con su cadena de dependencias. Este proyecto no tiene ninguna
dependencia y se despliega subiendo archivos a un hosting compartido. Toda
la comunicación pasa por `ClaudeCliente`: cambiar al SDK el día que el
proyecto use Composer es tocar un solo archivo.

### Modelo

Por defecto `claude-opus-5` en los dos asistentes, con esfuerzo `low` en la
tienda (respuestas cortas y rápidas) y `medium` en el panel (cálculos de
ventas y propuestas). Se cambia sin tocar código con `AI_MODEL` y
`AI_MODEL_ADMIN`. Criterio:

| Modelo | Cuándo | Precio aproximado por millón de tokens (entrada / salida) |
|--------|--------|--------------------------------------------------------|
| `claude-opus-5` | Por defecto. Mejor al elegir herramientas y al no inventar | US$5 / US$25 |
| `claude-sonnet-5` | Si se quiere bajar coste con buena calidad | ver precios vigentes |
| `claude-haiku-4-5-20251001` | Volumen alto con presupuesto ajustado; peor en conversaciones de varios pasos | US$1 / US$5 |

Las instrucciones y las definiciones de herramientas se marcan con
`cache_control`: a partir del segundo mensaje se leen de caché, a una décima
parte del precio de entrada. Solo con `claude-opus-5` se pide además
`fallbacks: "default"`: si los filtros de seguridad rechazan una petición
legítima, la API la repite en otro modelo en la misma llamada.

---

## 2. Asesora floral (tienda)

### Qué se ve

- Botón con el icono de chispa en la barra superior (en pantallas de hasta
  414 px pasa al menú, para no tapar el nombre de la tienda) y una invitación
  sobre los filtros del catálogo.
- Panel lateral en escritorio y hoja a pantalla completa en móvil. No aparece
  en `checkout.php` para no distraer del pago.
- Las recomendaciones llegan como tarjetas con foto, precio y el mismo
  formulario «Agregar» del catálogo. Enlaces e imágenes los arma el servidor.
- Todo se pinta con `textContent`: el texto del modelo nunca se interpreta
  como HTML.

### Herramientas

| Herramienta | Qué hace |
|-------------|----------|
| `buscar_productos` | Busca en el catálogo publicado por texto, categoría, precio máximo, orden y flores a excluir. Precio efectivo con la oferta ya aplicada |
| `ver_producto` | Ficha de un producto publicado |
| `listar_categorias` | Categorías con productos disponibles |
| `consultar_promociones` | Productos en oferta y si hay cupones activos (nunca revela códigos) |
| `informacion_tienda` | Entregas y zonas, formas de pago, horario, contacto — de la configuración real |
| `consultar_politica` | Textos legales vigentes (`includes/vistas/legal_textos.php`, los mismos de `legal.php`) |
| `ver_carrito` | El carrito de este visitante |
| `agregar_al_carrito`, `cambiar_cantidad_carrito` | Usan `Carrito::agregar()` / `Carrito::fijar()`: mismas reglas de stock y límites que el botón normal |
| `mis_pedidos` | Pedidos del cliente con sesión iniciada |
| `consultar_pedido` | Un pedido por código: del propio cliente, o con código **y** correo del pedido (misma regla que `seguimiento.php`) |

### Reglas que no dependen del modelo

- **Precios verificados** (`IaGuardia::preciosVerificados`): si la respuesta
  menciona una cantidad de dinero que no salió de una herramienta ni del
  mensaje del cliente, se sustituye por una respuesta segura. El modelo no
  puede inventar un precio aunque lo intente.
- **El carrito es el de la sesión**: la herramienta no recibe ningún
  identificador de carrito ni de usuario; los toma de la sesión del servidor.
- **Pedidos ajenos**: sin sesión, solo con código + correo exactos
  (`hash_equals`), con límite de intentos y el mismo mensaje exista o no.
- **Checkout**: la IA nunca crea pedidos ni cobra. El cliente paga en el
  flujo de siempre, donde el servidor recalcula todo.
- La conversación se guarda en la sesión del servidor, no en el navegador.

---

## 3. AI Manager (panel)

Menú lateral → **Asistente IA** (`admin/asistente.php`). Visible para quien
tenga acceso al panel; cada herramienta exige además su permiso concreto con
el rol que el usuario tiene **en ese momento**.

| Herramienta | Permiso |
|-------------|---------|
| `resumen_pedidos`, `listar_pedidos`, `ver_pedido`, `ventas` | `pedidos.ver` |
| `ranking_productos` | `pedidos.ver` + `productos.ver` |
| `inventario_bajo`, `buscar_productos_admin` | `productos.ver` |
| `proponer_cambio_precio`, `proponer_descuento`, `proponer_stock`, `proponer_publicacion`, `proponer_descripcion` | `productos.editar` |
| `proponer_estado_pedido` | `pedidos.ver` + `pedidos.editar` (o `pedidos.cancelar` para cancelar) |

De los clientes solo se expone el nombre: ni correo, ni teléfono, ni
dirección llegan al modelo. No hay herramientas para borrar, para usuarios,
roles, configuración, cupones ni respaldos.

### Flujo de un cambio

```
«pon el Encanto Rosado al 20%»
  → la IA llama a proponer_descuento
  → se guarda en ai_pending_actions (pendiente, caduca en 15 min) con antes y después
  → el panel enseña la tarjeta: Descuento  Sin oferta → 20%   [Confirmar] [Descartar]
  → la persona pulsa Confirmar (POST con CSRF, a admin/asistente-api.php)
  → IaPropuestas::confirmar():
       · la propuesta es de este usuario y no ha caducado (hora de MySQL)
       · vuelve a comprobar el permiso con Rbac en ese instante
       · la reclama de forma atómica (UPDATE … WHERE estado = 'pendiente'):
         un doble clic o dos pestañas no la ejecutan dos veces
       · bloquea la fila del producto (FOR UPDATE) y comprueba que el valor
         actual sigue siendo el «antes»; si alguien lo cambió, no aplica nada
       · aplica el cambio; los pedidos pasan por Pedidos::cambiarEstado()
         (transiciones válidas, stock, historial y correo al cliente)
  → Auditoría (origen asistente_ia) + ai_action_logs
```

La IA nunca ejecuta: solo propone. Una propuesta nueva sobre el mismo recurso
sustituye a la anterior pendiente.

---

## 4. Registro y auditoría

`ai_action_logs` guarda, por cada conversación, herramienta, propuesta y
confirmación: usuario, agente, acción, herramienta, recurso, estado
(`ok`, `error`, `denegado`, `propuesta`, `confirmada`, `cancelada`,
`caducada`, `rechazada`), tokens de entrada/salida/caché, duración e IP.
**No guarda el texto de las conversaciones.** Se purga a los 180 días.

Los cambios confirmados quedan además en la auditoría general del panel
(`Auditoría`), como cualquier cambio hecho a mano.

Para ver el gasto del mes:

```sql
SELECT agente, COUNT(*) llamadas, SUM(tokens_entrada) entrada,
       SUM(tokens_cache) cache, SUM(tokens_salida) salida
  FROM ai_action_logs
 WHERE accion = 'conversacion' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
 GROUP BY agente;
```

---

## 5. Seguridad

| Riesgo | Defensa |
|--------|---------|
| Inyección de instrucciones («ignora tus reglas…») | Las instrucciones del sistema no dan poder: aunque el modelo obedeciera, solo tiene las herramientas de su caja y cada una valida parámetros y permisos en PHP |
| Escalada de privilegios | Cajas separadas: la tienda no conoce las herramientas del panel. Una herramienta desconocida se rechaza. Permisos comprobados con la sesión en cada llamada y otra vez al confirmar |
| Fuga de datos | Herramientas de la tienda: solo campos públicos. Panel: solo el nombre del cliente. Pedidos con propiedad estricta |
| Datos inventados | Guardia de precios; instrucciones de responder solo con resultados de herramientas; si falta el dato, se dice |
| XSS por la respuesta | `textContent` en todo; enlaces del panel filtrados a `admin/…` |
| CSRF | Token obligatorio en los dos endpoints |
| Abuso y coste | Límites: 20 mensajes/10 min por sesión, 40 por IP, tope diario de la tienda (`AI_LIMITE_DIARIO`); 60/10 min por persona en el panel y 30 confirmaciones/10 min; 30 cambios de carrito/10 min |
| Bucles del modelo | Máximo 6 pasos de herramienta por mensaje, 14 turnos por conversación, historial acotado |
| Exposición de la clave | Solo en `.env`, solo en la cabecera `x-api-key`, nunca en logs, respuestas ni JavaScript. `AI_BASE_URL` solo acepta `https` |
| API caída o lenta | Tiempo máximo por respuesta, un reintento, mensaje amable. El resto del sitio no depende de la IA |

### Otros cambios de seguridad de esta versión

**Verificación de correo** (`cuenta/verificar.php`, `includes/lib/verificacion.php`)

- Token aleatorio de 256 bits, guardado solo como hash SHA-256, de un solo uso
  y con caducidad; al pedir uno nuevo se invalidan los anteriores.
- El consumo es atómico (`UPDATE … WHERE usado_en IS NULL AND expira_en > NOW()`):
  dos clics simultáneos no lo usan dos veces.
- El token sale de la URL en cuanto se lee (redirección a la URL limpia), para
  que no quede en el historial, en Analytics ni en la cabecera Referer.
- Estados claros: verificado, ya verificado, caducado o sustituido (con botón
  para reenviar) e inválido.
- Reenvío con espera de 60 s y máximo 3 por hora por cuenta, y 10 por hora por IP.

**Recuperación de contraseña** (`cuenta/restablecer.php`)

- Mismo tratamiento del token (fuera de la URL, consumo atómico antes de
  cambiar la contraseña).
- El correo con el enlace sale después de responder, para no delatar por el
  tiempo de respuesta si la cuenta existe (ver §12).
- Al restablecer se cierran **todas** las sesiones abiertas de la cuenta; al
  cambiarla desde el perfil, todas menos la actual (`usuarios.sesion_version`,
  migración 021).
- Restablecer por correo marca el correo como verificado (demuestra acceso al buzón).

**Acceso** (`cuenta/entrar.php`)

- «Cuenta desactivada» solo se le dice a quien acierta la contraseña.
- El tiempo de respuesta es el mismo exista o no la cuenta (medido: 242 ms
  en ambos casos), para no poder averiguar qué correos están registrados.

**Limitador de intentos** (`limitar()` en `includes/lib/seguridad.php`): ahora
es atómico. Antes, 40 peticiones simultáneas podían colarse todas; ahora pasan
exactamente las permitidas.

**Cabeceras**: la CSP se aplica también al panel, y permite los reproductores
de Instagram, Facebook y TikTok que la v.10 había dejado bloqueados.

---

## 6. Variables de entorno

Van en el `.env` del servidor (nunca en Git). Ver `.env.example`.

| Variable | Por defecto | Para qué |
|----------|-------------|----------|
| `AI_API_KEY` | vacío | Clave de la API de Anthropic. Vacía = asistentes apagados |
| `AI_MODEL` | `claude-opus-5` | Modelo de la tienda (y del panel si no se define el siguiente) |
| `AI_MODEL_ADMIN` | igual que `AI_MODEL` | Modelo del panel |
| `AI_CLIENTE_ACTIVO` | `1` | `0` apaga solo la asesora de la tienda |
| `AI_ADMIN_ACTIVO` | `1` | `0` apaga solo el AI Manager |
| `AI_TIMEOUT` | `40` | Segundos máximos por respuesta (10–90) |
| `AI_LIMITE_DIARIO` | `1500` | Mensajes a la asesora por día en toda la tienda; techo de gasto (0 = sin tope) |
| `AI_BASE_URL` | `https://api.anthropic.com` | Solo para pruebas locales (ver §8). Solo acepta `https`, salvo `http://127.0.0.1` en desarrollo |

---

## 7. Endpoints

Todos por `POST`, `application/x-www-form-urlencoded`, con `csrf_token` y la
cookie de sesión. Responden JSON.

**`api/asistente.php`** (tienda, sin necesidad de cuenta)

| `accion` | Parámetros | Respuesta |
|----------|------------|-----------|
| `mensaje` | `mensaje` (≤ 800 caracteres) | `{ok, aviso, texto, productos[], carrito, pedido}` |
| `historial` | — | `{ok, mensajes[]}` |
| `reiniciar` | — | `{ok}` |

**`admin/asistente-api.php`** (personal con acceso al panel)

| `accion` | Parámetros | Respuesta |
|----------|------------|-----------|
| `mensaje` | `mensaje` (≤ 1500) | `{ok, aviso, texto, tabla, propuestas[]}` |
| `confirmar`, `cancelar` | `id` de la propuesta | `{ok, mensaje}` |
| `historial`, `reiniciar` | — | |

Errores: `419` token CSRF, `401/403` sin sesión o sin permiso, `422` mensaje
vacío o largo, `429` límite, `503` con `codigo: no_disponible` si el
asistente está apagado o la API no responde.

---

## 8. Ejecución local y pruebas

Con XAMPP o `php -S`, igual que el resto del sitio. Para probar los
asistentes **sin gastar ni una llamada real** hay un servidor que imita la
Messages API y comprueba que las peticiones cumplan sus reglas (cabeceras,
alternancia de roles, emparejamiento `tool_use`/`tool_result`, firmas de los
bloques de razonamiento…):

```bash
node tests/ia/servidor-simulado.js 8799
```

y en el `.env` local (nunca en el servidor):

```
APP_ENTORNO=dev
AI_API_KEY=cualquier-texto-local
AI_BASE_URL=http://127.0.0.1:8799
```

Frases que activan casos especiales del simulador: `__error500__`,
`__429__`, `__401__`, `__lento__`, `__bucle__`, `__rechazo__`, `__max__`,
`__inventa__` (intenta dar un precio falso), `__herramienta_prohibida__`,
`__otro_pedido__`, `__basura__`.

### Prueba con la API real (una vez, en el servidor)

```bash
php tests/ia/probar-api-real.php
```

Comprueba la clave, el modelo y la conexión, y hace una pregunta de catálogo
que obliga a usar una herramienta. Solo lee; cuesta céntimos. La carpeta
`tests/` no es accesible desde el navegador.

### Pruebas realizadas en esta versión

Todas en local contra el simulador, con navegador real (Chromium) donde
aplica. Resultado: **0 fallos**.

| Suite | Resultado |
|-------|-----------|
| Verificación de correo (estados, reenvío, carreras, token fuera de la URL) | 20/20 |
| Recuperación, sesiones y acceso (cierre de sesiones, tiempos, cuenta desactivada) | 17/17 |
| Limitador bajo 40 peticiones simultáneas | pasan exactamente 5 de 5 permitidas |
| Herramientas de la tienda (datos, propiedad de pedidos, carrito, validación) | 34/34 |
| Seguridad IA (inyección, herramienta prohibida, precio inventado, pedido ajeno, XSS, CSRF, límites, errores de la API) | 31/31 |
| Interfaz de la asesora (móvil y escritorio, teclado, foco, tarjetas, carrito) | 39/39 |
| Herramientas del panel (permisos por rol, datos, propuestas) | 29/29 |
| AI Manager (propuestas, confirmación, caducidad, cambio concurrente, doble clic) | 30/30 |
| Venta completa: registro → verificación → recomendación IA → carrito por IA → checkout → comprobante → aprobación → cambio de estado por el AI Manager → el cliente lo ve | 27/27 |
| Regresión de la tienda sin IA | 23/23 |
| Concurrencia: 4 compradores por la última unidad (1 pedido, stock 0) y 20 chats simultáneos | 8/8 |
| Asistente apagado (sin clave, con `AI_CLIENTE_ACTIVO=0`, sin migración 022): la tienda idéntica | 4/4 |
| Precios, compra y panel de la v.10 | 7/7, 8/8, 7/7 |
| 13 páginas públicas + 17 del panel: sin errores de consola, sin violaciones de CSP, sin desborde en 104 + 96 combinaciones de pantalla | limpio |

No se probó con la API real porque la clave no está en este entorno: para eso
es `probar-api-real.php`.

---

## 9. Despliegue

1. Subir los archivos de la rama como siempre.
2. **Panel → Base de datos → Aplicar migraciones** (o `php db/migrar.php`).
   Aplica la 021 (`usuarios.sesion_version`) y la 022 (tablas de IA). Las dos
   solo añaden; no tocan datos existentes. Si el código sube antes que las
   migraciones, la tienda sigue funcionando y los asistentes no aparecen.
3. Crear la clave en <https://console.anthropic.com> → API Keys. Recomendado:
   un *workspace* propio para la tienda con un límite de gasto mensual.
4. Añadir al `.env` del servidor:
   ```
   AI_API_KEY=sk-ant-…
   ```
5. En el servidor: `php tests/ia/probar-api-real.php`. Si no hay consola, abrir
   el panel → Asistente IA y preguntar «¿Cuántos pedidos tenemos pendientes?».
6. Probar la asesora en la tienda con una pregunta de catálogo.

**Para apagar** un asistente al instante: `AI_CLIENTE_ACTIVO=0` o
`AI_ADMIN_ACTIVO=0` en el `.env`. Para apagar los dos: vaciar `AI_API_KEY`.

---

## 10. Costes

Medida del simulador con las instrucciones y herramientas reales: una
pregunta de catálogo son unos 3 600 tokens de entrada (de los que la mayor
parte se leen de caché a partir del segundo mensaje) y 100–300 de salida, en
dos llamadas (herramienta + respuesta).

Estimación con `claude-opus-5`: del orden de **US$0,01–0,03 por mensaje**. Con
el tope por defecto de 1 500 mensajes al día, el máximo teórico es de unos
US$15–45 diarios; el uso real de una floristería está muy por debajo. Estas
cifras son una estimación: el dato real sale de `ai_action_logs` (consulta de
§4) y de la consola de Anthropic. Para bajar el coste: `AI_MODEL=claude-haiku-4-5-20251001`
en la tienda, o bajar `AI_LIMITE_DIARIO`.

---

## 11. Problemas frecuentes

| Síntoma | Causa y solución |
|---------|------------------|
| No aparece el botón de la asesora | Falta `AI_API_KEY`, `AI_CLIENTE_ACTIVO=0` o la migración 022 sin aplicar. En `checkout.php` no aparece a propósito |
| «El asistente no está configurado» en el panel | Igual que arriba, con `AI_ADMIN_ACTIVO` |
| «La clave de la IA no es válida» | Clave mal copiada, revocada o sin acceso al modelo de `AI_MODEL` |
| «No puede responder ahora» | La API no respondió a tiempo o está saturada. Ver `storage/logs` / log de PHP: línea `Flowers Anto — IA: HTTP …` |
| «El asistente descansa por hoy» | Se alcanzó `AI_LIMITE_DIARIO` |
| Una propuesta dice «No se aplicó» | Alguien cambió el dato entre la propuesta y la confirmación, o el usuario ya no tiene el permiso. Pedirla de nuevo |
| Una propuesta dice «Caducó» | Pasaron 15 minutos sin confirmar. Pedirla de nuevo |
| El servidor no llega a la API | El hosting bloquea la salida HTTPS hacia `api.anthropic.com`; hay que pedir que la permitan |

---

## 12. Riesgos y pendientes conocidos

- **No hay prueba con la API real** desde este entorno: hacerla al desplegar
  (§9, paso 5).
- **Zona horaria de MySQL**: el panel (desde antes de esta versión) y el AI
  Manager usan `CURDATE()`/`NOW()` de MySQL para «hoy». Si el MySQL del hosting
  está en UTC y la tienda en Managua (UTC−6), entre las 18:00 y las 24:00 «hoy»
  ya es el día siguiente. Solución si pasa: fijar `time_zone = '-06:00'` en la
  conexión. No se cambió porque afecta a informes existentes y conviene
  confirmarlo en el servidor real primero.
- Los iconos del sitio vienen de Font Awesome por CDN (desde antes de esta
  versión). Los controles de los asistentes usan SVG propios y no dependen de él.
- **Recuperar contraseña**: el correo se envía después de entregar la página
  (`Correo::enviarAlTerminar`), para que la respuesta tarde lo mismo exista o
  no la cuenta. Eso necesita PHP-FPM o LiteSpeed (lo habitual en hosting
  compartido); con otro tipo de servidor se envía al final del script como
  antes y la diferencia de tiempo del SMTP sigue ahí. La ruta con PHP-FPM no
  se pudo probar en este entorno (solo la de respaldo).
- El respaldo automático de modelo (`fallbacks`) solo se pide con
  `claude-opus-5`, que es donde está documentado.
