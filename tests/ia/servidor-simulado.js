/**
 * Servidor de pruebas que imita la Messages API de Claude.
 *
 * Sirve para probar de punta a punta todo lo que no es el modelo: el cliente
 * HTTP de PHP, el bucle de herramientas, las herramientas, los límites, la
 * guardia de precios y la interfaz. No sustituye una prueba con la API real
 * (ver probar-api-real.php), pero permite ensayar lo que con la real sería
 * caro o imposible de provocar: caídas, 429, lentitud, rechazos y un modelo
 * «secuestrado» que intenta usar herramientas que no le tocan.
 *
 * Valida las peticiones con las reglas de la API real y responde 400 si no se
 * cumplen, igual que ella:
 *   · cabeceras x-api-key y anthropic-version;
 *   · roles alternos empezando por el usuario;
 *   · cada tool_use del asistente contestado con su tool_result, y solo esos;
 *   · bloques de razonamiento devueltos intactos (firma incluida);
 *   · `fallbacks` solo con su cabecera beta;
 *   · `properties` de cada herramienta como objeto, no como lista.
 *
 * También imita una API compatible con OpenAI (OpenRouter) en
 * POST /api/v1/chat/completions (y /v1/chat/completions), con sus reglas:
 *   · Authorization: Bearer y Content-Type JSON, sin cabeceras de Anthropic;
 *   · nada exclusivo de Anthropic (system arriba, cache_control, output_config,
 *     fallbacks, input_schema, is_error, bloques de razonamiento);
 *   · herramientas como {type: function, function: {name, parameters}};
 *   · `arguments` de cada tool_call como texto con un objeto JSON;
 *   · cada tool_call contestado por un mensaje `tool` con su tool_call_id,
 *     en el mismo orden y antes de cualquier otro mensaje.
 * El «modelo» con guion es el mismo para los dos formatos. En modo OpenAI,
 * además, una de cada dos respuestas con herramientas termina en
 * finish_reason «stop» (hay modelos que lo hacen), para probar que el cliente
 * no depende de ese campo.
 *
 * Y la API de Google Gemini en POST /v1beta/models/{modelo}:generateContent,
 * con los campos que admite según su documento de descubrimiento oficial
 * (un campo desconocido es un 400, como en la API real):
 *   · clave en x-goog-api-key, nunca en la URL; sin cabeceras de otros;
 *   · modelo conocido (si no, 404 como la API real);
 *   · esquema de parámetros con tipos en mayúsculas y sin additionalProperties;
 *   · cada functionCall contestado por su functionResponse, en orden, con el
 *     mismo nombre y el mismo id si lo traía; la firma de razonamiento
 *     (thoughtSignature) devuelta intacta en el turno en curso.
 * La mitad de las respuestas con herramientas traen id en la llamada y la
 * otra mitad no, para probar los dos casos.
 *
 * Uso:  node tests/ia/servidor-simulado.js [puerto]      (por defecto 8799)
 *       GET /__estado  → número de peticiones y la última recibida (sin clave)
 */
'use strict';
const http = require('http');
const crypto = require('crypto');

const PUERTO = +process.argv[2] || 8799;
const firmas = new Map();          // firma → JSON del bloque de razonamiento emitido
let peticiones = 0, ultima = null, cuenta429 = 0;

const firmar = (b) => { const s = 'sig_' + crypto.createHash('sha256').update(JSON.stringify(b) + Math.random()).digest('hex').slice(0, 24); return s; };

function error400(res, msg) {
  res.writeHead(400, { 'content-type': 'application/json' });
  res.end(JSON.stringify({ type: 'error', error: { type: 'invalid_request_error', message: msg } }));
}

function validar(req, body) {
  if (!req.headers['x-api-key']) return 'falta x-api-key';
  if (req.headers['anthropic-version'] !== '2023-06-01') return 'anthropic-version incorrecta';
  if (typeof body.model !== 'string' || !body.model) return 'model obligatorio';
  if (!Number.isInteger(body.max_tokens) || body.max_tokens < 1) return 'max_tokens inválido';
  if (!Array.isArray(body.messages) || !body.messages.length) return 'messages vacío';
  if ('fallbacks' in body && !(req.headers['anthropic-beta'] || '').includes('server-side-fallback-2026-07-01'))
    return 'fallbacks requiere la cabecera beta server-side-fallback-2026-07-01';
  if (body.output_config && !['low', 'medium', 'high', 'xhigh', 'max'].includes(body.output_config.effort)) return 'effort inválido';
  for (const t of body.tools || []) {
    if (!t.name || !t.description || !t.input_schema || t.input_schema.type !== 'object') return 'herramienta mal definida: ' + t.name;
    if (Array.isArray(t.input_schema.properties)) return `tools.${t.name}.input_schema.properties debe ser un objeto`;
  }
  let esperado = 'user', pendientes = null;
  for (const [i, m] of body.messages.entries()) {
    if (m.role !== esperado) return `messages.${i}: se esperaba rol ${esperado}`;
    const bloques = typeof m.content === 'string' ? [{ type: 'text', text: m.content }] : m.content;
    if (!Array.isArray(bloques)) return `messages.${i}: content inválido`;
    if (m.role === 'user') {
      const resultados = bloques.filter(b => b.type === 'tool_result').map(b => b.tool_use_id);
      if (pendientes) {
        const faltan = pendientes.filter(id => !resultados.includes(id));
        if (faltan.length) return `messages.${i}: falta tool_result para ${faltan.join(',')}`;
      }
      const sobran = resultados.filter(id => !(pendientes || []).includes(id));
      if (sobran.length) return `messages.${i}: tool_result sin tool_use: ${sobran.join(',')}`;
      pendientes = null;
    } else {
      for (const b of bloques) {
        if (b.type === 'tool_use' && (b.input === null || typeof b.input !== 'object' || Array.isArray(b.input)))
          return `messages.${i}: tool_use.input debe ser un objeto`;
        if (b.type === 'thinking') {
          if (!firmas.has(b.signature) || firmas.get(b.signature) !== JSON.stringify(b))
            return `messages.${i}: bloque de razonamiento modificado o desconocido`;
        }
      }
      const usos = bloques.filter(b => b.type === 'tool_use').map(b => b.id);
      pendientes = usos.length ? usos : null;
    }
    esperado = m.role === 'user' ? 'assistant' : 'user';
  }
  if (esperado !== 'assistant') return 'el último mensaje debe ser del usuario';
  return '';
}

// ---------------------------------------------------------------------------
// «Modelo» con guion
// ---------------------------------------------------------------------------
let nUso = 0;
const usar = (name, input) => ({ type: 'tool_use', id: 'toolu_sim_' + (++nUso), name, input });
const texto = (t) => ({ type: 'text', text: t });

function ultimoTextoUsuario(msgs) {
  for (let i = msgs.length - 1; i >= 0; i--) {
    const m = msgs[i];
    if (m.role !== 'user') continue;
    if (typeof m.content === 'string') return m.content;
    const t = m.content.find(b => b.type === 'text');
    if (t) return t.text;
  }
  return '';
}

/** Resultados de herramientas desde el último mensaje de texto del usuario. */
function resultadosDelTurno(msgs) {
  const out = [];
  for (let i = msgs.length - 1; i >= 0; i--) {
    const m = msgs[i];
    if (m.role === 'user' && typeof m.content === 'string') break;
    if (m.role === 'user' && Array.isArray(m.content) && !m.content.some(b => b.type === 'tool_result')) break;
    if (m.role === 'assistant') {
      for (const b of m.content) if (b.type === 'tool_use') {
        const r = msgs[i + 1].content.find(x => x.tool_use_id === b.id);
        let datos = null; try { datos = JSON.parse(r.content); } catch (e) { datos = r.content; }
        out.unshift({ nombre: b.name, entrada: b.input, datos, error: !!r.is_error });
      }
    }
  }
  return out;
}

const listar = (ps) => ps.slice(0, 3).map(p => `${p.nombre} (${p.precio})`).join(', ');

function turnoCliente(msgs) {
  const u = ultimoTextoUsuario(msgs), bajo = u.toLowerCase();
  const hechos = resultadosDelTurno(msgs);
  const ultimo = hechos[hechos.length - 1];

  // --- escenarios especiales ---------------------------------------------
  if (bajo.includes('__herramienta_prohibida__')) {
    if (!hechos.length) return { c: [texto('Claro.'), usar('listar_usuarios', { incluir_hash: true })], s: 'tool_use' };
    if (hechos.length === 1) return { c: [usar('ejecutar_sql', { sql: 'SELECT email, password_hash FROM usuarios' })], s: 'tool_use' };
    return { c: [texto('No tengo acceso a eso: ' + (ultimo.error ? ultimo.datos : ''))], s: 'end_turn' };
  }
  if (bajo.includes('__basura__')) {
    if (!hechos.length) return { c: [usar('agregar_al_carrito', { producto_id: '1; DROP TABLE productos', cantidad: 99999 })], s: 'tool_use' };
    return { c: [texto('No pude agregarlo: ' + ultimo.datos)], s: 'end_turn' };
  }
  if (bajo.includes('__bucle__')) return { c: [usar('ver_carrito', {})], s: 'tool_use' };
  if (bajo.includes('__varias__')) {
    // Dos herramientas en el mismo turno.
    if (!hechos.length) return { c: [texto('Lo consulto.'), usar('buscar_productos', { limite: 2 }), usar('informacion_tienda', { tema: 'pagos' })], s: 'tool_use' };
    const ps = hechos.find(h => h.nombre === 'buscar_productos').datos.productos || [];
    const pagos = hechos.find(h => h.nombre === 'informacion_tienda').datos.metodos || [];
    return { c: [texto(`Te recomiendo ${listar(ps)}. Puedes pagar con: ${pagos.join('; ')}.`)], s: 'end_turn' };
  }
  const cuesta = u.match(/cu[aá]nto cuesta (?:el |la |los |las |un |una )?(.+?)\??$/i);
  if (cuesta) {
    if (!hechos.length) return { c: [usar('buscar_productos', { consulta: cuesta[1], limite: 1 })], s: 'tool_use' };
    const p = (ultimo.datos.productos || [])[0];
    return { c: [texto(p ? `${p.nombre} cuesta ${p.precio}.` : `No encontré «${cuesta[1]}» en el catálogo.`)], s: 'end_turn' };
  }
  if (bajo.includes('__rechazo__')) return { c: [], s: 'refusal' };
  if (bajo.includes('__max__')) return { c: [texto('Voy a agregar'), usar('agregar_al_carrito', { producto_id: 1 })], s: 'max_tokens' };
  if (bajo.includes('__inventa__')) {
    if (!hechos.length) return { c: [usar('buscar_productos', { limite: 2 })], s: 'tool_use' };
    return { c: [texto(`Te recomiendo ${ultimo.datos.productos[0].nombre}, cuesta C$123.45 hoy.`)], s: 'end_turn' };
  }
  if (bajo.includes('__otro_pedido__')) {
    if (!hechos.length) return { c: [usar('consultar_pedido', { codigo: (u.match(/FA-[A-Z0-9-]+/i) || ['FA-X'])[0] })], s: 'tool_use' };
    return { c: [texto(ultimo.error ? 'No puedo ver ese pedido: ' + ultimo.datos : 'Tu pedido está ' + ultimo.datos.estado)], s: 'end_turn' };
  }

  // --- intentos de manipulación: un modelo bien portado se niega ------------
  if (/ignora|contraseña|password|api key|clave de la api|administrador|todos los usuarios|consulta sql|system prompt|instrucciones/.test(bajo)) {
    return { c: [texto('No puedo ayudarte con eso. Solo te ayudo con los arreglos, tu carrito y tus pedidos de Flowers Anto.')], s: 'end_turn' };
  }

  // --- agregar al carrito: buscar → agregar → confirmar ---------------------
  const agrega = u.match(/agrega(?:r|me)?\s+(?:el|la|un|una)?\s*(.+?)\s+al carrito/i);
  if (agrega) {
    if (!hechos.length) return { c: [texto('Lo busco.'), usar('buscar_productos', { consulta: agrega[1], limite: 3 })], s: 'tool_use' };
    if (ultimo.nombre === 'buscar_productos') {
      const p = (ultimo.datos.productos || [])[0];
      if (!p) return { c: [texto(`No encontré «${agrega[1]}» en el catálogo.`)], s: 'end_turn' };
      return { c: [usar('agregar_al_carrito', { producto_id: p.id, cantidad: 1 })], s: 'tool_use' };
    }
    if (ultimo.error) return { c: [texto('No pude agregarlo: ' + ultimo.datos)], s: 'end_turn' };
    return { c: [texto(`Listo, agregué ${ultimo.datos.producto} a tu carrito. Tu carrito suma ${ultimo.datos.carrito.subtotal}.`)], s: 'end_turn' };
  }

  // --- pedidos ---------------------------------------------------------------
  if (/pedido/.test(bajo)) {
    const codigo = (u.match(/FA-[A-Z0-9-]+/i) || [])[0];
    const correo = (u.match(/[\w.+-]+@[\w-]+\.[\w.]+/) || [])[0];
    if (!hechos.length) return { c: [codigo ? usar('consultar_pedido', correo ? { codigo, correo } : { codigo }) : usar('mis_pedidos', {})], s: 'tool_use' };
    if (ultimo.error) return { c: [texto('No encontré ese pedido. ' + ultimo.datos)], s: 'end_turn' };
    if (ultimo.nombre === 'mis_pedidos') {
      if (!ultimo.datos.sesion) return { c: [texto('Para buscarlo necesito el código del pedido (empieza por FA-) y el correo con el que compraste.')], s: 'end_turn' };
      const p = ultimo.datos.pedidos[0];
      return { c: [texto(p ? `Tu pedido más reciente, ${p.codigo}, está: ${p.estado}. Total ${p.total}.` : 'Todavía no tienes pedidos.')], s: 'end_turn' };
    }
    return { c: [texto(`El pedido ${ultimo.datos.codigo} está: ${ultimo.datos.estado}. Total ${ultimo.datos.total}.`)], s: 'end_turn' };
  }

  // --- información de la tienda ----------------------------------------------
  const tema = /env[ií]o|entrega|zona|domicilio/.test(bajo) ? 'entregas' : /pag(o|ar)|transferencia|efectivo/.test(bajo) ? 'pagos' : /horario|abren|abierto/.test(bajo) ? 'horario' : '';
  if (tema) {
    if (!hechos.length) return { c: [usar('informacion_tienda', { tema })], s: 'tool_use' };
    const d = ultimo.datos;
    if (tema === 'entregas') return { c: [texto(`Entregamos en ${d.ciudades}. Por ejemplo, ${d.zonas[0].zona}: ${d.zonas[0].costo_envio}.`)], s: 'end_turn' };
    if (tema === 'pagos') return { c: [texto('Puedes pagar con: ' + d.metodos.join('; ') + '.')], s: 'end_turn' };
    return { c: [texto(`Nuestro horario es ${d.horario}.`)], s: 'end_turn' };
  }
  if (/devoluci|reembolso|cambio/.test(bajo)) {
    if (!hechos.length) return { c: [usar('consultar_politica', { documento: 'devoluciones' })], s: 'tool_use' };
    return { c: [texto(ultimo.datos.texto.split('\n')[0])], s: 'end_turn' };
  }
  if (/oferta|promoci|descuento/.test(bajo)) {
    if (!hechos.length) return { c: [usar('consultar_promociones', {})], s: 'tool_use' };
    const ps = ultimo.datos.productos_en_oferta;
    return { c: [texto(ps.length ? 'Ahora tenemos en oferta: ' + listar(ps) + '.' : 'Hoy no hay arreglos rebajados.')], s: 'end_turn' };
  }

  // --- recomendación ------------------------------------------------------------
  if (/regalo|novia|rom[aá]ntic|cumplea|mam[aá]|elegante|econ[oó]mic|tengo|presupuesto|recomi|busco|quiero|disponibles/.test(bajo)) {
    if (!hechos.length) {
      const entrada = { limite: 3 };
      const m = u.match(/C\$\s?([\d,]+)/i); if (m) entrada.presupuesto_max = +m[1].replace(/,/g, '');
      const no = u.match(/no (?:quiero |me gustan )?(rosas|lirios|girasoles|tulipanes)/i); if (no) entrada.excluir_flores = [no[1].replace(/s$/, '')];
      if (/cumplea/.test(bajo)) entrada.consulta = 'cumpleaños';
      if (/econ[oó]mic/.test(bajo)) entrada.orden = 'precio_asc';
      return { c: [usar('buscar_productos', entrada)], s: 'tool_use' };
    }
    const ps = ultimo.datos.productos || [];
    if (!ps.length && hechos.length === 1) {
      const e = Object.assign({}, ultimo.entrada); delete e.consulta;
      return { c: [usar('buscar_productos', e)], s: 'tool_use' };
    }
    if (!ps.length) return { c: [texto('No encontré arreglos con esas condiciones. ¿Ampliamos el presupuesto?')], s: 'end_turn' };
    return { c: [texto(`Te recomiendo ${listar(ps)}.`)], s: 'end_turn' };
  }

  return { c: [texto('¡Hola! Puedo ayudarte a elegir un arreglo, revisar tu carrito o seguir un pedido. ¿Qué buscas hoy?')], s: 'end_turn' };
}

function turnoAdmin(msgs) {
  const u = ultimoTextoUsuario(msgs), bajo = u.toLowerCase();
  const hechos = resultadosDelTurno(msgs), ultimo = hechos[hechos.length - 1];
  const fin = (t) => ({ c: [texto(t)], s: 'end_turn' });

  if (bajo.includes('__herramienta_cliente__')) {
    if (!hechos.length) return { c: [usar('agregar_al_carrito', { producto_id: 1 })], s: 'tool_use' };
    return fin('Esa herramienta no está disponible aquí: ' + ultimo.datos);
  }
  // El orden importa: las reglas más concretas van primero.
  const estado = u.match(/marca(?:r)? el pedido (FA-[A-Z0-9-]+) como (\w+)/i);
  if (estado) {
    if (!hechos.length) return { c: [usar('proponer_estado_pedido', { codigo: estado[1], estado: estado[2].toLowerCase(), nota: 'Desde el asistente' })], s: 'tool_use' };
    return ultimo.error ? fin('No se puede: ' + ultimo.datos) : fin('Preparé el cambio de estado. Confírmalo abajo.');
  }
  if (/m[aá]s vendid|vendiendo m[aá]s|menor movimiento|menos vendid/.test(bajo)) {
    if (!hechos.length) return { c: [usar('ranking_productos', { periodo: 'mes', orden: /menor|menos/.test(bajo) ? 'menos' : 'mas' })], s: 'tool_use' };
    return fin('Ranking: ' + (ultimo.datos.productos || []).slice(0, 3).map(p => `${p.nombre} (${p.unidades})`).join(', '));
  }
  if (/pendiente/.test(bajo)) {
    if (!hechos.length) return { c: [usar('resumen_pedidos', { periodo: 'todo' })], s: 'tool_use' };
    return ultimo.error ? fin('No pude consultarlo: ' + ultimo.datos) : fin(`Hay ${ultimo.datos.por_estado.pendiente || 0} pedidos pendientes de ${ultimo.datos.total} en total.`);
  }
  if (/vend|ventas/.test(bajo)) {
    if (!hechos.length) return { c: [usar('ventas', { periodo: /hoy/.test(bajo) ? 'hoy' : /mes/.test(bajo) ? 'mes' : 'semana' })], s: 'tool_use' };
    return ultimo.error ? fin('No pude consultarlo: ' + ultimo.datos) : fin(`Vendimos ${ultimo.datos.total_cobrado} en ${ultimo.datos.pedidos} pedidos.`);
  }
  if (/poca disponibilidad|stock|inventario|agotad/.test(bajo)) {
    if (!hechos.length) return { c: [usar('inventario_bajo', { umbral: 3 })], s: 'tool_use' };
    return fin(`Hay ${ultimo.datos.productos.length} productos con poca disponibilidad.`);
  }
  if (/pedidos de hoy|pedido fa-/.test(bajo)) {
    const codigo = (u.match(/FA-[A-Z0-9-]+/i) || [])[0];
    if (!hechos.length) return { c: [codigo ? usar('ver_pedido', { codigo }) : usar('listar_pedidos', { periodo: 'hoy' })], s: 'tool_use' };
    if (ultimo.error) return fin('No pude: ' + ultimo.datos);
    return fin(codigo ? `El pedido ${ultimo.datos.codigo} está ${ultimo.datos.estado}.` : `Hoy hay ${ultimo.datos.pedidos.length} pedidos.`);
  }
  const precio = u.match(/(?:cambia|pon|sube|baja)r? el precio de (.+?) a C?\$?\s?([\d,.]+)/i);
  if (precio) {
    if (!hechos.length) return { c: [usar('buscar_productos_admin', { consulta: precio[1] })], s: 'tool_use' };
    if (ultimo.nombre === 'buscar_productos_admin') {
      const p = (ultimo.datos.productos || [])[0];
      if (!p) return fin('No encontré ese producto.');
      return { c: [usar('proponer_cambio_precio', { producto_id: p.id, precio_nuevo: +precio[2].replace(/,/g, '') })], s: 'tool_use' };
    }
    return ultimo.error ? fin('No pude preparar el cambio: ' + ultimo.datos) : fin('Preparé el cambio. Revísalo y confírmalo abajo.');
  }
  const desc = u.match(/descripci[oó]n para (.+)/i);
  if (desc) {
    if (!hechos.length) return { c: [usar('buscar_productos_admin', { consulta: desc[1] })], s: 'tool_use' };
    if (ultimo.nombre === 'buscar_productos_admin') {
      const p = (ultimo.datos.productos || [])[0];
      if (!p) return fin('No encontré ese producto.');
      return { c: [usar('proponer_descripcion', { producto_id: p.id, descripcion: `${p.nombre}: un arreglo hecho a mano con ${p.flores || 'flores de temporada'}, pensado para sorprender.` })], s: 'tool_use' };
    }
    return ultimo.error ? fin('No pude: ' + ultimo.datos) : fin('Te propongo esta descripción. Confírmala abajo si te gusta.');
  }
  if (/ignora|contraseña|password|api key|usuarios/.test(bajo)) return fin('No puedo hacer eso.');
  return fin('Puedo consultar pedidos, ventas, productos e inventario, y preparar cambios para que los confirmes.');
}

// ---------------------------------------------------------------------------
// API compatible con OpenAI (OpenRouter)
// ---------------------------------------------------------------------------
const RUTAS_OPENAI = ['/api/v1/chat/completions', '/v1/chat/completions'];
let peticionesOa = 0;

function errorOa(res, estado, msg, extra = {}) {
  res.writeHead(estado, Object.assign({ 'content-type': 'application/json' }, extra));
  res.end(JSON.stringify({ error: { code: estado, message: msg, metadata: {} } }));
}

function validarOpenAi(req, body) {
  if (!/^Bearer \S+$/.test(req.headers['authorization'] || '')) return 'falta Authorization: Bearer';
  for (const h of ['x-api-key', 'anthropic-version', 'anthropic-beta'])
    if (req.headers[h]) return `cabecera de Anthropic no admitida: ${h}`;
  if (!/^application\/json/i.test(req.headers['content-type'] || '')) return 'Content-Type debe ser application/json';
  if (typeof body.model !== 'string' || !body.model) return 'model obligatorio';
  for (const k of ['system', 'output_config', 'fallbacks', 'stop_sequences', 'thinking'])
    if (k in body) return `campo de Anthropic no admitido: ${k}`;
  if (JSON.stringify(body).includes('"cache_control"')) return 'cache_control no existe en Chat Completions';
  if (body.max_tokens !== undefined && (!Number.isInteger(body.max_tokens) || body.max_tokens < 1)) return 'max_tokens inválido';
  if (!Array.isArray(body.messages) || !body.messages.length) return 'messages vacío';
  for (const t of body.tools || []) {
    if ('input_schema' in t || 'name' in t) return 'herramienta en formato de Anthropic';
    if (t.type !== 'function' || !t.function || typeof t.function.name !== 'string' || !t.function.name) return 'herramienta mal definida';
    const p = t.function.parameters;
    if (!p || p.type !== 'object' || typeof p.properties !== 'object' || Array.isArray(p.properties))
      return `tools.${t.function.name}.parameters debe ser un objeto con properties`;
  }
  let pendientes = [];
  for (const [i, m] of body.messages.entries()) {
    if (!['system', 'user', 'assistant', 'tool'].includes(m.role)) return `messages.${i}: rol desconocido ${m.role}`;
    if ('is_error' in m) return `messages.${i}: is_error no existe en Chat Completions`;
    if (m.role === 'system' && i !== 0) return `messages.${i}: system solo puede ir al principio`;
    if (pendientes.length && m.role !== 'tool') return `messages.${i}: faltan mensajes tool para ${pendientes.join(',')}`;
    if (m.role === 'tool') {
      if (!pendientes.length) return `messages.${i}: mensaje tool sin tool_call pendiente`;
      if (m.tool_call_id !== pendientes[0]) return `messages.${i}: tool_call_id ${m.tool_call_id} fuera de orden (se esperaba ${pendientes[0]})`;
      if (typeof m.content !== 'string') return `messages.${i}: el content de un mensaje tool debe ser texto`;
      pendientes.shift();
      continue;
    }
    if (m.role === 'user' || m.role === 'system') {
      if (typeof m.content !== 'string' || !m.content) return `messages.${i}: content debe ser texto`;
      continue;
    }
    if (m.content !== null && typeof m.content !== 'string') return `messages.${i}: content del asistente debe ser texto o null`;
    if (m.tool_calls !== undefined) {
      if (!Array.isArray(m.tool_calls) || !m.tool_calls.length) return `messages.${i}: tool_calls vacío`;
      for (const tc of m.tool_calls) {
        if (typeof tc.id !== 'string' || !tc.id || tc.type !== 'function' || !tc.function || typeof tc.function.name !== 'string')
          return `messages.${i}: tool_call mal formado`;
        if (typeof tc.function.arguments !== 'string') return `messages.${i}: arguments debe ser texto JSON`;
        let a; try { a = JSON.parse(tc.function.arguments); } catch (e) { return `messages.${i}: arguments no es JSON`; }
        if (a === null || typeof a !== 'object' || Array.isArray(a)) return `messages.${i}: arguments debe ser un objeto`;
      }
      pendientes = m.tool_calls.map(tc => tc.id);
    } else if (m.content === null) return `messages.${i}: asistente sin contenido`;
  }
  if (pendientes.length) return `faltan mensajes tool para ${pendientes.join(',')}`;
  if (!['user', 'tool'].includes(body.messages[body.messages.length - 1].role)) return 'el último mensaje debe ser user o tool';
  return '';
}

/** Chat Completions → bloques, para reutilizar el mismo «modelo» con guion. */
function aBloques(msgs) {
  const out = [];
  for (const m of msgs) {
    if (m.role === 'system') continue;
    if (m.role === 'user') { out.push({ role: 'user', content: m.content }); continue; }
    if (m.role === 'assistant') {
      const c = m.content ? [texto(m.content)] : [];
      for (const tc of m.tool_calls || []) c.push({ type: 'tool_use', id: tc.id, name: tc.function.name, input: JSON.parse(tc.function.arguments) });
      out.push({ role: 'assistant', content: c });
      continue;
    }
    const error = m.content.startsWith('ERROR: ');
    const r = { type: 'tool_result', tool_use_id: m.tool_call_id, content: error ? m.content.slice(7) : m.content, is_error: error };
    const prev = out[out.length - 1];
    if (prev && prev.role === 'user' && Array.isArray(prev.content)) prev.content.push(r);
    else out.push({ role: 'user', content: [r] });
  }
  return out;
}

function atenderOpenAi(req, res) {
  let raw = '';
  req.on('data', d => raw += d);
  req.on('end', () => {
    peticiones++; peticionesOa++;
    let body; try { body = JSON.parse(raw); } catch (e) { return errorOa(res, 400, 'JSON inválido'); }
    ultima = { ruta: req.url, cabeceras: {
      authorization: req.headers['authorization'] ? 'Bearer (presente)' : null,
      'content-type': req.headers['content-type'] || null,
      'x-api-key': req.headers['x-api-key'] ? '(presente)' : null,
      'anthropic-version': req.headers['anthropic-version'] || null,
      'anthropic-beta': req.headers['anthropic-beta'] || null,
      'http-referer': req.headers['http-referer'] || null, 'x-title': req.headers['x-title'] || null,
    }, body };
    const fallo = validarOpenAi(req, body);
    if (fallo) return errorOa(res, 400, fallo);

    const msgs = aBloques(body.messages);
    const u = ultimoTextoUsuario(msgs);
    const hayResultados = body.messages[body.messages.length - 1].role === 'tool';
    if (u.includes('__error500__')) return errorOa(res, 500, 'Internal');
    if (u.includes('__429__')) return errorOa(res, 429, 'Rate limit exceeded: free-models-per-day', { 'retry-after': '1' });
    if (u.includes('__429una__') && cuenta429++ === 0) return errorOa(res, 429, 'slow down', { 'retry-after': '1' });
    if (u.includes('__401__')) return errorOa(res, 401, 'No auth credentials found');
    if (u.includes('__402__')) return errorOa(res, 402, 'Insufficient credits. Add more using https://openrouter.ai/credits');
    if (u.includes('__eco_clave__')) return errorOa(res, 401, 'Invalid key: ' + (req.headers['authorization'] || '').slice(7));
    if (u.includes('__error_en_200__')) { res.writeHead(200, { 'content-type': 'application/json' }); return res.end(JSON.stringify({ error: { code: 502, message: 'Provider returned error' } })); }
    if (u.includes('__sin_choices__')) { res.writeHead(200, { 'content-type': 'application/json' }); return res.end('{"id":"gen-x","choices":[]}'); }

    const esAdmin = (body.tools || []).some(t => t.function.name === 'resumen_pedidos');
    let r;
    let crudo = null, finForzado = null, rechazo = null;
    if ((u.includes('__args_rotos__') || u.includes('__args_lista__')) && !hayResultados) {
      r = { c: [usar(esAdmin ? 'proponer_stock' : 'buscar_productos', {})], s: 'tool_use' };
      crudo = u.includes('__args_rotos__') ? '{"consulta": "ros' : '[1, 2]';
    } else if (u.includes('__args_rotos__') || u.includes('__args_lista__')) {
      const t = body.messages[body.messages.length - 1].content;
      r = { c: [texto('No pude usar la herramienta: ' + t)], s: 'end_turn' };
    } else if (u.includes('__vacio__')) {
      r = { c: [], s: 'end_turn' };
    } else if (u.includes('__refusal_campo__')) {
      r = { c: [], s: 'end_turn' }; rechazo = 'I cannot help with that.';
    } else if (u.includes('__stop_con_herramienta__') && !hayResultados) {
      r = { c: [usar('listar_categorias', {})], s: 'tool_use' }; finForzado = 'stop';
    } else if (u.includes('__length_con_herramienta__')) {
      r = { c: [usar('agregar_al_carrito', { producto_id: 1 })], s: 'tool_use' }; finForzado = 'length';
    } else {
      r = esAdmin ? turnoAdmin(msgs) : turnoCliente(msgs);
    }

    const usos = r.c.filter(b => b.type === 'tool_use');
    const textoRespuesta = r.c.filter(b => b.type === 'text').map(b => b.text).join('\n\n');
    const message = { role: 'assistant', content: textoRespuesta || null, refusal: rechazo };
    if (usos.length) {
      message.tool_calls = usos.map(b => ({ id: b.id.replace('toolu_', 'call_'), type: 'function',
        function: { name: b.name, arguments: crudo !== null ? crudo : JSON.stringify(b.input) } }));
    }
    const fin = finForzado || (r.s === 'tool_use' ? (peticionesOa % 2 ? 'tool_calls' : 'stop')
      : r.s === 'max_tokens' ? 'length' : r.s === 'refusal' ? 'content_filter' : 'stop');
    const respuesta = {
      id: 'gen-sim-' + peticiones, object: 'chat.completion', created: Math.floor(Date.now() / 1000), model: body.model,
      choices: [{ index: 0, message, finish_reason: fin, native_finish_reason: fin }],
      usage: { prompt_tokens: Math.ceil(raw.length / 4), completion_tokens: 40 + JSON.stringify(r.c).length / 4 | 0,
               total_tokens: 0, prompt_tokens_details: { cached_tokens: body.messages.length > 2 ? 900 : 0 } },
    };
    respuesta.usage.total_tokens = respuesta.usage.prompt_tokens + respuesta.usage.completion_tokens;
    const demora = u.includes('__lento__') ? 20000 : 30;
    setTimeout(() => { res.writeHead(200, { 'content-type': 'application/json' }); res.end(JSON.stringify(respuesta)); }, demora);
  });
}

// ---------------------------------------------------------------------------
// Google Gemini (generateContent)
// ---------------------------------------------------------------------------
// Campos que admite la API, sacados de su documento de descubrimiento oficial
// (https://generativelanguage.googleapis.com/$discovery/rest?version=v1beta,
// revisión 20260923).
const CAMPOS_GEMINI = {"GenerateContentRequest":["cachedContent","contents","generationConfig","labels","model","safetySettings","serviceTier","store","systemInstruction","toolConfig","tools"],"Content":["parts","role"],"Part":["audioTranscription","codeExecutionResult","executableCode","fileData","functionCall","functionResponse","inlineData","mediaProcessing","mediaResolution","partMetadata","speechMetadata","text","thought","thoughtSignature","toolCall","toolResponse","videoMetadata"],"FunctionCall":["args","id","name"],"FunctionResponse":["id","name","parts","response","scheduling","willContinue"],"Tool":["codeExecution","computerUse","fileSearch","functionDeclarations","googleMaps","googleSearch","googleSearchRetrieval","mcpServers","urlContext"],"FunctionDeclaration":["behavior","description","name","parameters","parametersJsonSchema","response","responseJsonSchema"],"Schema":["anyOf","default","description","enum","example","format","items","maxItems","maxLength","maxProperties","maximum","minItems","minLength","minProperties","minimum","nullable","pattern","properties","propertyOrdering","required","title","type"],"GenerationConfig":["_responseJsonSchema","audioTranscriptionConfig","candidateCount","enableAffectiveDialog","enableEnhancedCivicAnswers","frequencyPenalty","imageConfig","logprobs","maxOutputTokens","mediaResolution","presencePenalty","responseFormat","responseJsonSchema","responseLogprobs","responseMimeType","responseModalities","responseSchema","seed","speechConfig","stopSequences","temperature","thinkingConfig","topK","topP","translationConfig"],"ToolConfig":["functionCallingConfig","includeServerSideToolInvocations","retrievalConfig"],"FunctionCallingConfig":["allowedFunctionNames","mode"]};
const TIPOS_GEMINI = ['STRING', 'NUMBER', 'INTEGER', 'BOOLEAN', 'ARRAY', 'OBJECT', 'NULL'];
const MODELOS_GEMINI = ['gemini-2.5-flash-lite', 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-flash-latest', 'gemini-flash-lite-latest'];
const firmasGemini = new Set();
let peticionesGe = 0;

function errorGe(res, code, status, message, reason, extra = {}) {
  res.writeHead(code, Object.assign({ 'content-type': 'application/json' }, extra));
  const details = reason ? [{ '@type': 'type.googleapis.com/google.rpc.ErrorInfo', reason, domain: 'googleapis.com' }] : [];
  res.end(JSON.stringify({ error: { code, message, status, details } }));
}

const esObjeto = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

function camposGe(obj, tipo, ruta) {
  if (!esObjeto(obj)) return `${ruta}: se esperaba un objeto`;
  for (const k of Object.keys(obj)) {
    if (!CAMPOS_GEMINI[tipo].includes(k)) return `Invalid JSON payload received. Unknown name "${k}" at '${ruta}': Cannot find field.`;
  }
  return '';
}

function esquemaGe(sch, ruta) {
  let f = camposGe(sch, 'Schema', ruta); if (f) return f;
  if (!TIPOS_GEMINI.includes(sch.type)) return `${ruta}.type: valor no válido «${sch.type}»`;
  if (sch.enum && (!Array.isArray(sch.enum) || sch.enum.some(e => typeof e !== 'string'))) return `${ruta}.enum: solo textos`;
  for (const [k, v] of Object.entries(sch.properties || {})) { f = esquemaGe(v, `${ruta}.properties[${k}]`); if (f) return f; }
  if (sch.items) { f = esquemaGe(sch.items, `${ruta}.items`); if (f) return f; }
  if (sch.type === 'OBJECT' && sch.properties !== undefined && !Object.keys(sch.properties).length) return `${ruta}.properties: should be non-empty for OBJECT type`;
  return '';
}

function validarGemini(req, body, modelo) {
  if (!req.headers['x-goog-api-key']) return [403, 'PERMISSION_DENIED', "Method doesn't allow unregistered callers (callers without established identity). Please use API Key or other form of API consumer identity to call this API."];
  if (/[?&]key=/.test(req.url)) return [400, 'INVALID_ARGUMENT', 'la clave no debe ir en la URL (prueba de Flowers Anto)'];
  for (const h of ['authorization', 'x-api-key', 'anthropic-version', 'anthropic-beta'])
    if (req.headers[h]) return [400, 'INVALID_ARGUMENT', `cabecera de otro proveedor: ${h}`];
  if (!/^application\/json/i.test(req.headers['content-type'] || '')) return [400, 'INVALID_ARGUMENT', 'Content-Type debe ser application/json'];
  const falla = (m) => [400, 'INVALID_ARGUMENT', m];
  let f = camposGe(body, 'GenerateContentRequest', 'GenerateContentRequest'); if (f) return falla(f);
  if (body.systemInstruction) {
    f = camposGe(body.systemInstruction, 'Content', 'system_instruction'); if (f) return falla(f);
    if (!Array.isArray(body.systemInstruction.parts) || body.systemInstruction.parts.some(p => typeof p.text !== 'string')) return falla('system_instruction: solo texto');
  }
  if (!Array.isArray(body.contents) || !body.contents.length) return falla('* GenerateContentRequest.contents: contents is not specified');
  for (const [i, t] of (body.tools || []).entries()) {
    f = camposGe(t, 'Tool', `tools[${i}]`); if (f) return falla(f);
    for (const [j, d] of (t.functionDeclarations || []).entries()) {
      f = camposGe(d, 'FunctionDeclaration', `tools[${i}].function_declarations[${j}]`); if (f) return falla(f);
      if (!/^[a-zA-Z0-9_.:-]{1,128}$/.test(d.name || '')) return falla('nombre de función no válido');
      if (d.parameters) { f = esquemaGe(d.parameters, `tools[${i}].function_declarations[${j}].parameters`); if (f) return falla(f); }
    }
  }
  if (body.generationConfig) { f = camposGe(body.generationConfig, 'GenerationConfig', 'generation_config'); if (f) return falla(f); }
  if (body.toolConfig) { f = camposGe(body.toolConfig, 'ToolConfig', 'tool_config'); if (f) return falla(f); }

  // Turno en curso: desde el último mensaje de texto del usuario.
  let inicioTurno = 0;
  body.contents.forEach((c, i) => { if (c.role === 'user' && (c.parts || []).some(p => typeof p.text === 'string')) inicioTurno = i; });
  let pendientes = null;
  for (const [i, c] of body.contents.entries()) {
    f = camposGe(c, 'Content', `contents[${i}]`); if (f) return falla(f);
    if (!['user', 'model'].includes(c.role)) return falla(`contents[${i}].role: debe ser user o model`);
    if (!Array.isArray(c.parts) || !c.parts.length) return falla(`contents[${i}]: must include at least one parts field`);
    for (const [j, p] of c.parts.entries()) {
      f = camposGe(p, 'Part', `contents[${i}].parts[${j}]`); if (f) return falla(f);
      const datos = ['text', 'functionCall', 'functionResponse'].filter(k => k in p);
      if (datos.length !== 1) return falla(`contents[${i}].parts[${j}]: una part lleva un solo dato`);
      if (p.functionCall) {
        f = camposGe(p.functionCall, 'FunctionCall', `contents[${i}].parts[${j}].function_call`); if (f) return falla(f);
        if (c.role !== 'model') return falla('functionCall solo en contenido del modelo');
        if (p.functionCall.args !== undefined && !esObjeto(p.functionCall.args)) return falla('function_call.args debe ser un objeto');
      }
      if (p.functionResponse) {
        f = camposGe(p.functionResponse, 'FunctionResponse', `contents[${i}].parts[${j}].function_response`); if (f) return falla(f);
        if (!esObjeto(p.functionResponse.response)) return falla('function_response.response debe ser un objeto');
      }
      if (p.thoughtSignature !== undefined && !firmasGemini.has(p.thoughtSignature)) return falla('thought_signature modificada o desconocida');
    }
    const llamadas = c.parts.filter(p => p.functionCall);
    const respuestas = c.parts.filter(p => p.functionResponse);
    if (pendientes) {
      if (c.role !== 'user' || respuestas.length !== pendientes.length)
        return falla(`contents[${i}]: faltan function_response para ${pendientes.map(x => x.name).join(',')}`);
      for (const [k, r] of respuestas.entries()) {
        if (r.functionResponse.name !== pendientes[k].name) return falla(`contents[${i}]: function_response ${r.functionResponse.name} fuera de orden`);
        if (pendientes[k].id && r.functionResponse.id !== pendientes[k].id) return falla(`contents[${i}]: function_response sin el id ${pendientes[k].id}`);
      }
      pendientes = null;
    } else if (respuestas.length) return falla(`contents[${i}]: function_response sin function_call`);
    if (llamadas.length) {
      if (i > inicioTurno && !llamadas[0].thoughtSignature) return falla(`contents[${i}]: Function call is missing a thought_signature`);
      pendientes = llamadas.map(p => p.functionCall);
    }
  }
  if (pendientes) return falla('faltan function_response al final');
  if (body.contents[0].role !== 'user') return falla('el primer contenido debe ser del usuario');
  if (body.contents[body.contents.length - 1].role !== 'user') return falla('el último contenido debe ser del usuario');
  if (!MODELOS_GEMINI.includes(modelo)) return [404, 'NOT_FOUND', `models/${modelo} is not found for API version v1beta, or is not supported for generateContent.`];
  return null;
}

/** Contenidos de Gemini → bloques, para reutilizar el mismo «modelo» con guion. */
function geABloques(contents) {
  const out = [];
  contents.forEach((c, i) => {
    if (c.role === 'model') {
      const bl = [];
      c.parts.forEach((p, j) => {
        if (typeof p.text === 'string') bl.push(texto(p.text));
        if (p.functionCall) bl.push({ type: 'tool_use', id: `g${i}_${bl.filter(b => b.type === 'tool_use').length}`, name: p.functionCall.name, input: p.functionCall.args || {} });
      });
      out.push({ role: 'assistant', content: bl });
      return;
    }
    const respuestas = c.parts.filter(p => p.functionResponse);
    if (respuestas.length) {
      out.push({ role: 'user', content: respuestas.map((p, k) => {
        const r = p.functionResponse.response;
        const error = 'error' in r;
        return { type: 'tool_result', tool_use_id: `g${i - 1}_${k}`, content: error ? String(r.error) : JSON.stringify(r.result), is_error: error };
      }) });
    } else {
      out.push({ role: 'user', content: c.parts.map(p => p.text).join('\n') });
    }
  });
  return out;
}

function atenderGemini(req, res) {
  let raw = '';
  req.on('data', d => raw += d);
  req.on('end', () => {
    peticiones++; peticionesGe++;
    const ruta = req.url.split('?')[0];
    const m = ruta.match(/^\/v1beta\/models\/([^/:]+):generateContent$/);
    if (!m) return errorGe(res, 404, 'NOT_FOUND', 'ruta no encontrada: ' + ruta);
    const modelo = decodeURIComponent(m[1]);
    let body; try { body = JSON.parse(raw); } catch (e) { return errorGe(res, 400, 'INVALID_ARGUMENT', 'Invalid JSON payload received.'); }
    ultima = { ruta, clave_en_url: /[?&]key=/.test(req.url), cabeceras: {
      'x-goog-api-key': req.headers['x-goog-api-key'] ? '(presente)' : null,
      authorization: req.headers['authorization'] ? '(presente)' : null,
      'x-api-key': req.headers['x-api-key'] ? '(presente)' : null,
      'anthropic-version': req.headers['anthropic-version'] || null,
      'content-type': req.headers['content-type'] || null,
    }, body };
    if (req.headers['x-goog-api-key'] === 'clave-invalida')
      return errorGe(res, 400, 'INVALID_ARGUMENT', 'API key not valid. Please pass a valid API key.', 'API_KEY_INVALID');
    const fallo = validarGemini(req, body, modelo);
    if (fallo) return errorGe(res, fallo[0], fallo[1], fallo[2]);

    const msgs = geABloques(body.contents);
    const u = ultimoTextoUsuario(msgs);
    const hayResultados = body.contents[body.contents.length - 1].parts.some(p => p.functionResponse);
    if (u.includes('__401__')) return errorGe(res, 400, 'INVALID_ARGUMENT', 'API key not valid. Please pass a valid API key.', 'API_KEY_INVALID');
    if (u.includes('__403__')) return errorGe(res, 403, 'PERMISSION_DENIED', 'Generative Language API has not been used in project 0 before or it is disabled.', 'SERVICE_DISABLED');
    if (u.includes('__429__')) return errorGe(res, 429, 'RESOURCE_EXHAUSTED', 'You exceeded your current quota, please check your plan and billing details.', null, { 'retry-after': '1' });
    if (u.includes('__429una__') && cuenta429++ === 0) return errorGe(res, 429, 'RESOURCE_EXHAUSTED', 'Resource has been exhausted (e.g. check quota).', null, { 'retry-after': '1' });
    if (u.includes('__error500__')) return errorGe(res, 500, 'INTERNAL', 'An internal error has occurred.');
    if (u.includes('__503__')) return errorGe(res, 503, 'UNAVAILABLE', 'The model is overloaded. Please try again later.');
    if (u.includes('__504__')) return errorGe(res, 504, 'DEADLINE_EXCEEDED', 'Deadline expired before operation could complete.');
    if (u.includes('__eco_clave__')) return errorGe(res, 400, 'INVALID_ARGUMENT', 'API key not valid: ' + req.headers['x-goog-api-key'], 'API_KEY_INVALID');
    const responder = (cuerpo) => { const demora = u.includes('__lento__') ? 20000 : 30; setTimeout(() => { res.writeHead(200, { 'content-type': 'application/json' }); res.end(JSON.stringify(cuerpo)); }, demora); };
    const uso = { promptTokenCount: Math.ceil(raw.length / 4), candidatesTokenCount: 40, totalTokenCount: Math.ceil(raw.length / 4) + 40, cachedContentTokenCount: body.contents.length > 2 ? 900 : 0 };
    if (u.includes('__bloqueo_prompt__')) return responder({ promptFeedback: { blockReason: 'SAFETY' }, usageMetadata: uso });
    if (u.includes('__sin_candidatos__')) return responder({ candidates: [], usageMetadata: uso });
    if (u.includes('__malformada__')) return responder({ candidates: [{ content: { role: 'model', parts: [] }, finishReason: 'MALFORMED_FUNCTION_CALL', index: 0 }], usageMetadata: uso });
    if (u.includes('__seguridad__')) return responder({ candidates: [{ finishReason: 'SAFETY', index: 0 }], usageMetadata: uso });

    const esAdmin = (body.tools || []).some(t => (t.functionDeclarations || []).some(d => d.name === 'resumen_pedidos'));
    let r, argsLista = false;
    if (u.includes('__args_lista__') && !hayResultados) { r = { c: [usar(esAdmin ? 'proponer_stock' : 'buscar_productos', {})], s: 'tool_use' }; argsLista = true; }
    else if (u.includes('__args_lista__')) { r = { c: [texto('No pude usar la herramienta: ' + JSON.stringify(body.contents[body.contents.length - 1].parts[0].functionResponse.response))], s: 'end_turn' }; }
    else r = esAdmin ? turnoAdmin(msgs) : turnoCliente(msgs);

    const conId = peticionesGe % 2 === 0;
    let firmada = false;
    const parts = r.c.map(b => {
      if (b.type === 'text') return { text: b.text };
      const fc = { name: b.name, args: argsLista ? [1, 2] : b.input };
      if (conId) fc.id = 'gcall_' + (++nUso);
      const p = { functionCall: fc };
      if (!firmada) { firmada = true; p.thoughtSignature = Buffer.from('firma-' + crypto.randomBytes(6).toString('hex')).toString('base64'); firmasGemini.add(p.thoughtSignature); }
      return p;
    });
    const fin = r.s === 'max_tokens' ? 'MAX_TOKENS' : r.s === 'refusal' ? 'SAFETY' : 'STOP';
    responder({
      candidates: [{ content: r.s === 'refusal' ? undefined : { role: 'model', parts }, finishReason: fin, index: 0 }],
      usageMetadata: Object.assign(uso, { candidatesTokenCount: 40 + JSON.stringify(r.c).length / 4 | 0 }),
      modelVersion: modelo, responseId: 'resp-sim-' + peticiones,
    });
  });
}

// ---------------------------------------------------------------------------
http.createServer((req, res) => {
  if (req.method === 'GET' && req.url === '/__estado') {
    res.writeHead(200, { 'content-type': 'application/json' });
    return res.end(JSON.stringify({ peticiones, ultima }));
  }
  if (req.method === 'POST' && req.url === '/__reiniciar') { peticiones = 0; ultima = null; cuenta429 = 0; res.writeHead(204); return res.end(); }
  if (req.method === 'POST' && RUTAS_OPENAI.includes(req.url)) return atenderOpenAi(req, res);
  if (req.method === 'POST' && req.url.startsWith('/v1beta/models/')) return atenderGemini(req, res);
  if (req.method === 'GET' && req.url.startsWith('/v1beta/models/')) {
    // Consulta de un modelo, como GET models/{modelo} de la API real.
    const modelo = decodeURIComponent(req.url.split('?')[0].slice('/v1beta/models/'.length));
    if (!req.headers['x-goog-api-key']) return errorGe(res, 403, 'PERMISSION_DENIED', "Method doesn't allow unregistered callers.");
    if (!MODELOS_GEMINI.includes(modelo)) return errorGe(res, 404, 'NOT_FOUND', `models/${modelo} is not found for API version v1beta.`);
    res.writeHead(200, { 'content-type': 'application/json' });
    return res.end(JSON.stringify({ name: 'models/' + modelo, displayName: 'Gemini simulado (' + modelo + ')', supportedGenerationMethods: ['generateContent', 'countTokens'] }));
  }
  if (req.method !== 'POST' || req.url !== '/v1/messages') { res.writeHead(404); return res.end(); }

  let raw = '';
  req.on('data', d => raw += d);
  req.on('end', () => {
    peticiones++;
    let body; try { body = JSON.parse(raw); } catch (e) { return error400(res, 'JSON inválido'); }
    ultima = { cabeceras: { 'anthropic-version': req.headers['anthropic-version'], 'anthropic-beta': req.headers['anthropic-beta'] || null, 'x-api-key': req.headers['x-api-key'] ? '(presente)' : null }, body };
    const fallo = validar(req, body);
    if (fallo) return error400(res, fallo);

    const u = ultimoTextoUsuario(body.messages);
    if (u.includes('__error500__')) { res.writeHead(500, { 'content-type': 'application/json' }); return res.end('{"type":"error","error":{"type":"api_error","message":"Internal"}}'); }
    if (u.includes('__429__')) { res.writeHead(429, { 'content-type': 'application/json', 'retry-after': '1' }); return res.end('{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}'); }
    if (u.includes('__429una__') && cuenta429++ === 0) { res.writeHead(429, { 'content-type': 'application/json', 'retry-after': '1' }); return res.end('{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}'); }
    if (u.includes('__401__')) { res.writeHead(401, { 'content-type': 'application/json' }); return res.end('{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}'); }

    const esAdmin = (body.tools || []).some(t => t.name === 'resumen_pedidos');
    const r = esAdmin ? turnoAdmin(body.messages) : turnoCliente(body.messages);
    const pensamiento = { type: 'thinking', thinking: '', signature: '' };
    pensamiento.signature = firmar(pensamiento);
    firmas.set(pensamiento.signature, JSON.stringify(pensamiento));
    const contenido = r.s === 'refusal' ? [] : [pensamiento, ...r.c];

    const respuesta = {
      id: 'msg_sim_' + peticiones, type: 'message', role: 'assistant', model: body.model,
      content: contenido, stop_reason: r.s, stop_sequence: null,
      usage: { input_tokens: Math.ceil(raw.length / 4), output_tokens: 40 + JSON.stringify(r.c).length / 4 | 0,
               cache_read_input_tokens: body.messages.length > 1 ? 900 : 0, cache_creation_input_tokens: 0 },
    };
    const demora = u.includes('__lento__') ? 20000 : 30;
    setTimeout(() => { res.writeHead(200, { 'content-type': 'application/json' }); res.end(JSON.stringify(respuesta)); }, demora);
  });
}).listen(PUERTO, '127.0.0.1', () => console.log('API simulada en http://127.0.0.1:' + PUERTO
  + ' (Anthropic: /v1/messages · OpenAI/OpenRouter: /api/v1/chat/completions · Gemini: /v1beta/models/{modelo}:generateContent)'));
