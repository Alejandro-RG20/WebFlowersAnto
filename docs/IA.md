# Asistentes de IA — Flowers Anto v0.1

Guía técnica de los dos asistentes con inteligencia artificial y de los
cambios de seguridad que se hicieron al integrarlos. Rama: `FlowersAntoIA_v0.1`
(parte de `FlowersAnto_v.10`).

- **Massiel, asesora floral** (tienda): ayuda al cliente a elegir, responde dudas de
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
      Anthropic u OpenRouter (solo lectura pública +    (lectura con permisos +
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
| `includes/lib/ia/cliente.php` | `ClaudeCliente`: HTTP al proveedor (Anthropic u OpenRouter) con el adaptador de formatos; tiempo máximo, un reintento en 429/5xx, errores como `IaError` |
| `includes/lib/ia/agente.php` | `IaAgente` (bucle), `IaCaja` (interfaz de herramientas), `IaEntrada` (validación), `IaSesion`, `IaGuardia` |
| `includes/lib/ia/herramientas_cliente.php` | Herramientas de la asesora |
| `includes/lib/ia/herramientas_admin.php` | Herramientas del AI Manager |
| `includes/lib/ia/propuestas.php` | Propuestas de cambio: crear, confirmar, descartar, caducar |
| `includes/lib/ia/registro.php` | `IaRegistro`: escribe en `ai_action_logs` |
| `api/asistente.php` | Endpoint de la tienda |
| `admin/asistente-api.php`, `admin/asistente.php` | Endpoint y página del panel |
| `assets/js/asistente.js`, `assets/js/admin-asistente.js` | Interfaces |
| `db/migraciones/022_asistentes_ia.php` | Tablas `ai_action_logs` y `ai_pending_actions` |

### Proveedores: Anthropic, OpenRouter o Google Gemini

El proveedor se elige en el `.env` con `AI_PROVEEDOR`, sin tocar código:

```
                         agente.php  (formato interno: bloques de la Messages API)
                               │
                         cliente.php  (un solo cliente, un adaptador por proveedor)
             ┌─────────────────┼──────────────────────────┐
             ▼                 ▼                          ▼
   anthropic (por defecto)   openrouter / openai        google
   /v1/messages, tal cual    Chat Completions           Gemini generateContent
   x-api-key                 /chat/completions          /v1beta/models/{modelo}:generateContent
                             Authorization: Bearer      x-goog-api-key
```

Todo lo que no es `cliente.php` trabaja siempre con **un solo formato
interno**, el de la Messages API (bloques `text`, `tool_use`, `tool_result`).
Con OpenRouter, `ClaudeCliente` traduce en los dos sentidos:

| Formato interno | Chat Completions (OpenRouter) |
|-----------------|-------------------------------|
| `system` (bloques) | primer mensaje `{role: system}` con las instrucciones completas |
| herramienta `{name, description, input_schema}` | `{type: function, function: {name, description, parameters}}` (el mismo JSON Schema) |
| bloque `tool_use` del asistente | `tool_calls[]` con `arguments` como texto JSON |
| bloques `tool_result` (uno por herramienta) | un mensaje `{role: tool, tool_call_id}` por resultado, en el mismo orden; `is_error` pasa al texto (`ERROR: …`) |
| `thinking`, `cache_control`, `output_config`, `fallbacks` | no se envían (son de Anthropic) |
| respuesta: `choices[0].message.content` | bloque `text` |
| respuesta: `message.tool_calls[]` | bloques `tool_use` con los argumentos decodificados |
| `finish_reason`: `length` / `content_filter` (o `message.refusal`) | `stop_reason`: `max_tokens` / `refusal` |
| hay `tool_calls` (aunque `finish_reason` diga `stop`) | `stop_reason: tool_use` |
| `usage.prompt_tokens` − `cached_tokens`, `completion_tokens` | `input_tokens`, `output_tokens`, `cache_read_input_tokens` |

Consecuencias:

- **El agente, las herramientas, los permisos, las propuestas, la guardia de
  precios y el historial de la sesión son los mismos con los dos proveedores.**
- El historial guardado en `$_SESSION['ia_cliente']` y `$_SESSION['ia_admin']`
  está siempre en el formato interno: se puede cambiar de proveedor con
  conversaciones abiertas. Si una conversación antigua no la acepta la API
  (400), se repite una vez solo con el mensaje nuevo y se sigue limpia.
- **Argumentos inválidos**: si un modelo manda `arguments` que no son un
  objeto JSON (truncados, texto suelto, una lista), la herramienta **no se
  ejecuta**; el modelo recibe un error y puede reintentarlo. Vale también
  para Anthropic.
- Con Anthropic todo sigue como antes (mismas cabeceras, caché, esfuerzo y
  `fallbacks`). Al añadir el adaptador apareció y se corrigió un fallo que ya
  existía: una herramienta sin argumentos (`ver_carrito`, `mis_pedidos`,
  `consultar_promociones`, `listar_categorias`) se reenviaba con `"input": []`,
  que la API real rechaza con un 400. Ahora va como `{}`.

**Qué hay que saber de OpenRouter:**

- **El modelo tiene que admitir herramientas (*tool calling*).** Sin eso la
  asesora no puede consultar el catálogo. En openrouter.ai/models se filtra por
  «Tools». Si no las admite, `probar-api-real.php` lo detecta.
- Las instrucciones están escritas y probadas para Claude. Otro modelo puede
  seguirlas peor. La guardia de precios, los permisos y la confirmación
  humana no dependen del modelo; lo que sí depende es la calidad de las
  respuestas y que no invente datos distintos de precios (horarios,
  políticas). Si hace falta el mismo comportamiento, se puede usar un modelo
  de Anthropic a través de OpenRouter (`anthropic/…`).
- Sin `cache_control`, cada vuelta paga la entrada completa (con modelos de
  pago).
- Los mensajes de los clientes y, en el panel, los nombres de clientes pasan
  por OpenRouter y por el proveedor del modelo. Los modelos gratuitos
  (`:free`) pueden guardar o usar las peticiones según la política de su
  proveedor: revisa la configuración de privacidad de la cuenta de OpenRouter.

### Google Gemini

Con `AI_PROVEEDOR=google`, `ClaudeCliente` traduce al formato de la API
oficial de Gemini (`generateContent`) y de vuelta. El formato se verificó
contra el documento de descubrimiento oficial de la API
(`https://generativelanguage.googleapis.com/$discovery/rest?version=v1beta`,
revisión 20260923) y contra la API real (respuesta a una clave no válida).

| Formato interno | Gemini |
|-----------------|--------|
| `system` | `systemInstruction.parts[0].text`, una sola vez y completo |
| `user` / `assistant` | `contents[]` con `role: user` / `role: model` y sus `parts` |
| herramienta `{name, description, input_schema}` | `tools[0].functionDeclarations[]` con `parameters` (esquema `Schema` de Gemini: tipos en mayúsculas, solo los campos que admite; sin `additionalProperties`, que la API rechaza. Las herramientas sin argumentos van sin `parameters`) |
| bloque `tool_use` | part `functionCall {id?, name, args}` |
| bloque `tool_result` | part `functionResponse {id?, name, response}` en un contenido `user`, en el mismo orden; `response` es `{result: …}` o `{error: …}` |
| respuesta: part `text` | bloque `text` |
| respuesta: part `functionCall` | bloque `tool_use` (id de Gemini, o uno propio `fa_…` si no trae) |
| `thoughtSignature` de una part | se guarda en el bloque (`firma_google`) y se devuelve intacta en la siguiente vuelta, como exige la API en los modelos con razonamiento |
| `finishReason`: `MAX_TOKENS` / `SAFETY`, `RECITATION`, `BLOCKLIST`, `PROHIBITED_CONTENT`, `SPII` / `promptFeedback.blockReason` | `stop_reason`: `max_tokens` / `refusal` / `refusal` |
| hay `functionCall` (Gemini termina en `STOP` también entonces) | `stop_reason: tool_use` |
| `usageMetadata` | `input_tokens` (sin la caché), `output_tokens` (incluye el razonamiento), `cache_read_input_tokens` |

- La clave viaja en la cabecera `x-goog-api-key`, **nunca en la URL**
  (`?key=` quedaría en registros de proxies).
- La URL se arma con `AI_BASE_URL` (la raíz del servicio) y el modelo de la
  petición: el del panel puede ser otro que el de la tienda. Si alguien pone
  `/v1beta` o `/v1beta/models` en `AI_BASE_URL`, o `models/` delante del
  modelo, no se duplica.
- Errores: una clave no válida Google la contesta con **400**
  `API_KEY_INVALID` (no 401) → se trata como configuración. 403 → sin
  permiso para la API. 404 → el modelo no existe o no admite
  `generateContent` (lo dice el registro). 429 → cuota o límite. 500/503 →
  saturado (un reintento). 504 → tiempo agotado.
  `MALFORMED_FUNCTION_CALL`, `UNEXPECTED_TOOL_CALL`, `TOO_MANY_TOOL_CALLS` →
  aviso amable sin ejecutar nada; `MISSING_THOUGHT_SIGNATURE` → se repite con
  la conversación limpia.
- **Sin respaldo automático entre proveedores.** Si Gemini falla, el
  asistente responde con un aviso y la tienda sigue igual. No se reenvía a
  otro proveedor: una herramienta podría ejecutarse dos veces (añadir al
  carrito dos veces, por ejemplo). Cada llamada del modelo se ejecuta una
  sola vez; los reintentos (429/5xx) son de la petición HTTP, antes de
  ejecutar nada. Los cambios del panel necesitan confirmación humana y se
  reclaman de forma atómica (no se pueden aplicar dos veces).
- **Cuotas:** las del plan gratuito de Google cambian según el modelo, el
  proyecto y las condiciones del servicio. El sistema no supone ninguna
  cifra: un 429 se muestra como «muchas consultas a la vez» y la tienda sigue
  funcionando. `AI_LIMITE_DIARIO` sigue siendo el tope **interno** de Flowers
  Anto.
- El modelo inicial es `gemini-2.5-flash-lite`. Se cambia solo con
  `AI_MODEL` / `AI_MODEL_ADMIN`; `probar-api-real.php` comprueba contra la
  API real que el modelo existe y admite `generateContent`.

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

## 2. Massiel, asesora floral (tienda)

La asesora se llama **Massiel**: así se presenta (instrucciones en
`IaHerramientasCliente::instrucciones()`) y así aparece en la interfaz. Si le
preguntan si es una persona, dice que es una asistente virtual de la tienda.

### Qué se ve

- **Móvil y tableta (hasta 900 px, iOS y Android):** botón flotante rosa con
  la flor, justo encima del de WhatsApp, con el mismo tamaño (60 px) y el
  mismo latido. Al cargar la página muestra unos segundos la etiqueta
  «Massiel · Asesora floral» y luego queda solo el círculo, para no tapar el
  contenido. Respeta la zona segura del iPhone y sube con el aviso de cookies.
  La barra ya no lleva botón de la asesora: el nombre de la tienda no se corta
  (antes, en un iPhone de 430 px, sí).
- **Escritorio (más de 900 px):** botón «Massiel» en la barra superior.
- En el catálogo, una invitación sobre los filtros.
- Panel lateral en escritorio y hoja a pantalla completa en móvil. No aparece
  en `checkout.php` para no distraer del pago.
- Las recomendaciones llegan como tarjetas con foto, precio y el mismo
  formulario «Agregar» del catálogo. Enlaces e imágenes los arma el servidor.
- Todo se pinta con `textContent`: el texto del modelo nunca se interpreta
  como HTML.

### Mientras espera la respuesta

- Los puntos de «escribiendo» explican qué pasa a los 6, 15 y 30 segundos
  («Massiel está revisando el catálogo…», «Sigue buscando…», «Está tardando
  más de lo normal…»).
- El navegador espera como mucho `AI_TIMEOUT` + 20 segundos. Nunca se queda
  cargando para siempre: termina con la respuesta o con un aviso que ofrece
  «Reintentar» y WhatsApp.
- Si la conexión se corta (datos móviles, pantalla bloqueada), el servidor
  termina igual y guarda la respuesta (`ignore_user_abort`). El navegador la
  busca en la conversación guardada, por la referencia de ese mensaje, antes
  de dar el error.
- Si la respuesta llega con el chat cerrado, el botón muestra un punto verde.

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
| Exposición de la clave | Solo en `.env`, solo en su cabecera (`x-api-key` con Anthropic, `Authorization: Bearer` con OpenRouter), nunca en logs, respuestas ni JavaScript; si un proveedor la repitiera en un error, el registro la tapa. Una clave con saltos de línea se descarta (no permite inyectar cabeceras). `AI_BASE_URL` solo acepta `https` y las redirecciones no se siguen |
| Argumentos rotos del modelo | Si no son un objeto JSON válido, la herramienta no se ejecuta |
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
| `AI_PROVEEDOR` | `anthropic` | `anthropic`, `openrouter`, `google` u `openai` (otra API compatible con OpenAI). Un valor desconocido apaga los asistentes |
| `AI_API_KEY` | vacío | Clave del proveedor elegido. Vacía = asistentes apagados |
| `AI_BASE_URL` | la del proveedor | Vacía: `https://api.anthropic.com`, `https://openrouter.ai/api/v1` o `https://generativelanguage.googleapis.com`. Solo `https` (salvo `http://127.0.0.1` en desarrollo, para el simulador) |
| `AI_MODEL` | `claude-opus-5` con Anthropic; **obligatorio** con OpenRouter y Google | Modelo de la tienda (y del panel si no se define el siguiente). Con OpenRouter, su identificador: `autor/modelo:variante`; con Google, p. ej. `gemini-2.5-flash-lite` (minúsculas, números, punto y guion). Si falta o no es válido, los asistentes se apagan (no se usa `claude-opus-5` a escondidas) |
| `AI_MODEL_ADMIN` | igual que `AI_MODEL` | Modelo del panel |
| `AI_CLIENTE_ACTIVO` | `1` | `0` apaga solo la asesora de la tienda |
| `AI_ADMIN_ACTIVO` | `1` | `0` apaga solo el AI Manager |
| `AI_TIMEOUT` | `40` | Segundos máximos por respuesta (10–90) |
| `AI_LIMITE_DIARIO` | `1500` (el `.env.example` propone `50`) | Tope **interno** de Flowers Anto: mensajes de clientes a la asesora por día en toda la tienda (0 = sin tope). Ver abajo |
| `AI_APP_URL`, `AI_APP_NOMBRE` | vacíos | Opcionales, solo OpenRouter: se envían como `HTTP-Referer` y `X-Title` para identificar la tienda en su panel. Datos públicos, nunca secretos |

### Cambiar de proveedor

Anthropic:

```
AI_PROVEEDOR=anthropic
AI_API_KEY=<clave de Anthropic>
AI_BASE_URL=
AI_MODEL=claude-opus-5
AI_MODEL_ADMIN=
```

OpenRouter:

```
AI_PROVEEDOR=openrouter
AI_BASE_URL=https://openrouter.ai/api/v1
AI_API_KEY=<clave de OpenRouter>
AI_MODEL=nvidia/nemotron-3-ultra-550b-a55b:free
AI_MODEL_ADMIN=nvidia/nemotron-3-ultra-550b-a55b:free
AI_CLIENTE_ACTIVO=1
AI_ADMIN_ACTIVO=1
AI_TIMEOUT=40
AI_LIMITE_DIARIO=50
```

Google Gemini:

```
AI_PROVEEDOR=google
AI_API_KEY=<clave de Google AI Studio>
AI_BASE_URL=https://generativelanguage.googleapis.com
AI_MODEL=gemini-2.5-flash-lite
AI_MODEL_ADMIN=gemini-2.5-flash-lite
AI_CLIENTE_ACTIVO=1
AI_ADMIN_ACTIVO=1
AI_TIMEOUT=40
AI_LIMITE_DIARIO=50
```

Después, `php tests/ia/probar-api-real.php`. Al cambiar de proveedor, cambia
también `AI_BASE_URL` (o déjala vacía para usar la del proveedor): si se
queda la de otro, las peticiones irían al sitio equivocado.

### Dos límites distintos: no confundirlos

- **`AI_LIMITE_DIARIO` es de Flowers Anto.** Cuenta *mensajes de clientes* a la
  asesora al día, en toda la tienda. El AI Manager del panel no cuenta aquí
  (tiene su propio límite por persona: 60 cada 10 minutos).
- **El límite del proveedor es otro.** Según la documentación de OpenRouter,
  hoy su plan gratuito admite unas **50 peticiones al día** a modelos `:free`
  y **20 por minuto** (puede cambiar; consulta su página de límites). Cuenta
  *peticiones a la API*, no mensajes: cada mensaje de un cliente hace como
  mínimo 1 petición y normalmente 2 o 3 (pregunta → herramienta → respuesta),
  hasta 6. Las pruebas del panel y `probar-api-real.php` también gastan.

Con el plan gratuito de OpenRouter, `AI_LIMITE_DIARIO=50` no impide llegar
antes al límite del proveedor: unos 20 mensajes con herramientas bastan. Cuando
OpenRouter corta (429), la asesora responde «Tengo muchas consultas a la vez…» y
la tienda sigue funcionando. Para evitarlo: bajar `AI_LIMITE_DIARIO` a ~15–20,
o usar créditos o un modelo de pago.

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
asistentes **sin gastar ni una llamada real** hay un servidor que imita las
dos APIs y rechaza (400) las peticiones que no cumplan sus reglas:

- Messages API (`/v1/messages`): cabeceras, alternancia de roles,
  emparejamiento `tool_use`/`tool_result`, `input` como objeto, firmas de los
  bloques de razonamiento…
- Chat Completions, como OpenRouter (`/api/v1/chat/completions`): `Bearer`,
  nada propio de Anthropic, herramientas `type: function`, `arguments` como
  texto con un objeto JSON, cada `tool_call` contestado por su mensaje `tool`
  en orden… Una de cada dos respuestas con herramientas termina en
  `finish_reason: stop`, como hacen algunos modelos.

```bash
node tests/ia/servidor-simulado.js 8799
```

y en el `.env` local (nunca en el servidor):

```
APP_ENTORNO=dev
AI_API_KEY=cualquier-texto-local
# Anthropic simulado:
AI_PROVEEDOR=anthropic
AI_BASE_URL=http://127.0.0.1:8799
# …o OpenRouter simulado:
# AI_PROVEEDOR=openrouter
# AI_BASE_URL=http://127.0.0.1:8799/api/v1
# AI_MODEL=nvidia/nemotron-3-ultra-550b-a55b:free
```

Frases que activan casos especiales del simulador: `__error500__`,
`__429__`, `__401__`, `__lento__`, `__bucle__`, `__rechazo__`, `__max__`,
`__inventa__` (intenta dar un precio falso), `__herramienta_prohibida__`,
`__otro_pedido__`, `__basura__`, `__varias__` (dos herramientas en un turno).
Solo en modo OpenRouter: `__402__`, `__args_rotos__`, `__args_lista__`,
`__stop_con_herramienta__`, `__length_con_herramienta__`, `__refusal_campo__`,
`__vacio__`, `__error_en_200__`, `__sin_choices__`, `__eco_clave__`.

### Prueba con la API real (una vez, en el servidor)

```bash
php tests/ia/probar-api-real.php
```

Sirve con los dos proveedores. Comprueba la configuración y hace cuatro
pruebas: un mensaje simple, «¿Cuánto cuesta la Gerbera?» (tiene que consultar
el catálogo sin que la guardia bloquee la respuesta), «¿Qué productos tienen
disponibles?» y añadir un producto al carrito (el de ese proceso de consola, que
no se guarda). Solo lee. Son unas 6–10 peticiones: con el plan gratuito de
OpenRouter cuentan para su límite diario. La carpeta `tests/` no es accesible
desde el navegador.

### Pruebas realizadas en esta versión

Todas en local contra el simulador, con navegador real (Chromium) donde
aplica. **Toda la batería se ejecutó completa con cada proveedor: Anthropic,
OpenRouter y Google Gemini simulados, con los mismos resultados.**

| Suite | Anthropic | OpenRouter | Google |
|-------|-----------|------------|--------|
| Adaptador de proveedores: configuración, traducción de peticiones y respuestas, herramientas (una, varias y en cadena), argumentos inválidos, guardia de precios, rechazos, errores (401/402/403/404/429/5xx/504), clave tapada en el registro, cambio de proveedor con la conversación abierta (en todas las direcciones), AI Manager con permisos y confirmación | 146/146 (los tres en la misma suite) | | |
| Aislamiento: 3 clientes a la vez, sin mezclar historial, carrito ni respuestas | 9/9 | 9/9 | 9/9 |
| Espera de Massiel (progreso, conexión cortada, recuperación, aviso con el chat cerrado) | 8/8 | 8/8 | 8/8 |
| Seguridad IA (inyección, herramienta prohibida, precio inventado, pedido ajeno, XSS, CSRF, límites, errores de la API) | 31/31 | 31/31 | 31/31 |
| Interfaz de la asesora | 42/42 | 42/42 | 42/42 |
| AI Manager (propuestas, confirmación, caducidad, cambio concurrente, doble clic) | 30/30 | 30/30 | 30/30 |
| Venta completa (registro → … → el cliente ve el estado cambiado por el AI Manager) | 27/27 | 27/27 | 27/27 |
| Concurrencia: 4 compradores por la última unidad y 20 chats simultáneos | 8/8 | 8/8 | 8/8 |
| Herramientas de la tienda / del panel | 34/34 · 29/29 | 34/34 · 29/29 | 34/34 · 29/29 |
| Regresión de la tienda, verificación de correo, recuperación y sesiones | 23/23 · 20/20 · 17/17 | igual | igual |
| Precios, compra y panel de la v.10 | 7/7 · 8/8 · 7/7 | igual | igual |
| Asistente apagado (sin clave, `AI_CLIENTE_ACTIVO=0`, sin `AI_MODEL`, sin migración 022) | 5/5 | 5/5 | 5/5 |
| 13 páginas públicas + 17 del panel: consola, CSP y registro de PHP | limpio | limpio | limpio |

Contra la **API real de Google** (alcanzable desde el entorno de pruebas, sin
clave válida): la URL y la cabecera `x-goog-api-key` son las correctas; una
clave no válida da `400 API_KEY_INVALID`, se trata como error de
configuración y el registro no contiene la clave.

Fallos encontrados y corregidos al hacer estas pruebas:

- **Anthropic, herramientas sin argumentos**: `"input": []` rechazado por la
  API real (400). Ya existía; corregido en el adaptador.
- **Checkout con la última unidad** (ya existía):
  - a veces el cliente que se quedaba sin el arreglo veía «Tu carrito está
    vacío.»; ahora ve qué se agotó;
  - con varias compras a la vez, MariaDB deshacía a veces una transacción
    (deadlock) y **el cliente veía el error SQL en crudo**. Ahora nunca se
    muestra un error de base de datos y la compra se repite, dando el aviso
    correcto. Lo mismo en `api/paypal.php`.

No se probó con una respuesta real de un modelo (sin clave en este entorno):
para eso es `probar-api-real.php` (§9).

---

## 9. Despliegue

1. Subir los archivos de la rama como siempre.
2. **Panel → Base de datos → Aplicar migraciones** (o `php db/migrar.php`).
   Aplica la 021 (`usuarios.sesion_version`) y la 022 (tablas de IA). Las dos
   solo añaden; no tocan datos existentes. Si el código sube antes que las
   migraciones, la tienda sigue funcionando y los asistentes no aparecen.
3. Crear la clave del proveedor: Anthropic en <https://console.anthropic.com>
   (recomendado: un *workspace* propio con límite de gasto) u OpenRouter en
   <https://openrouter.ai/keys> (recomendado: límite de crédito en la clave).
4. Añadir al `.env` del servidor la configuración del proveedor (§6,
   «Cambiar de proveedor») con la clave en `AI_API_KEY`.
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
| No aparecen con `AI_PROVEEDOR=openrouter` | Falta `AI_MODEL` o no es un identificador válido. El registro del servidor lo dice (una vez por hora): «IA apagada por configuración…» |
| «La clave de la IA no es válida» | Clave mal copiada o revocada, sin acceso al modelo de `AI_MODEL` o, con OpenRouter, **sin créditos (402)**; el registro lo indica |
| La asesora contesta sin mirar el catálogo | Con OpenRouter: el modelo no admite herramientas o las usa mal. Cambiar `AI_MODEL` por uno con «Tools» |
| Massiel tarda mucho en contestar | Casi siempre es el modelo: los gratuitos grandes (p. ej. `…-ultra-…:free`) tienen cola y razonan antes de responder, y cada mensaje necesita 2 o más llamadas. Probar un modelo más rápido que admita herramientas, o uno de pago. Ver la duración real: `SELECT created_at, estado, detalle, ms FROM ai_action_logs WHERE agente='cliente' AND accion='conversacion' ORDER BY id DESC LIMIT 20;` (`detalle` = `tiempo` si se cortó) |
| «Estoy tardando más de la cuenta» | Se agotó `AI_TIMEOUT` antes de que el modelo terminara. Subirlo (p. ej. 60) si el hosting lo permite, o usar un modelo más rápido |
| «Tengo muchas consultas a la vez» | El proveedor devolvió 429. Con el plan gratuito de OpenRouter: 20 peticiones por minuto y el tope diario |
| «No puede responder ahora» | La API no respondió a tiempo o está saturada. Ver el log de PHP: línea `Flowers Anto — IA: HTTP …` (Anthropic) o `Flowers Anto — IA (openrouter): HTTP …` |
| «El asistente descansa por hoy» | Se alcanzó `AI_LIMITE_DIARIO` |
| Una propuesta dice «No se aplicó» | Alguien cambió el dato entre la propuesta y la confirmación, o el usuario ya no tiene el permiso. Pedirla de nuevo |
| Una propuesta dice «Caducó» | Pasaron 15 minutos sin confirmar. Pedirla de nuevo |
| El servidor no llega a la API | El hosting bloquea la salida HTTPS hacia `api.anthropic.com`, `openrouter.ai` o `generativelanguage.googleapis.com`; hay que pedir que la permitan |
| Con Google: «La clave de la IA no es válida» | El registro dice `API_KEY_INVALID` (clave mal copiada) o `403` (la clave no tiene habilitada la API de Gemini). Crear la clave en Google AI Studio |
| Con Google: «No puedo responder ahora mismo» y en el registro `404 … revisa AI_MODEL` | El modelo de `AI_MODEL` no existe o no admite `generateContent`. `probar-api-real.php` lo comprueba |

---

## 12. Riesgos y pendientes conocidos

- **No hay prueba con la API real** desde este entorno (sin clave, y la red
  de desarrollo no llega a `openrouter.ai`): hacerla al desplegar (§9, paso 5).
  En concreto, no está comprobado que `nvidia/nemotron-3-ultra-550b-a55b:free`
  exista en OpenRouter con ese nombre, que admita herramientas ni cómo sigue
  las instrucciones.
- **Límite del plan gratuito de OpenRouter** por debajo de `AI_LIMITE_DIARIO`
  (ver §6): la asesora dejará de responder antes de lo que marca el tope interno.
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
