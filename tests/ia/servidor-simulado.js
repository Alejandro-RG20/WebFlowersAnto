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
  if (/regalo|novia|rom[aá]ntic|cumplea|mam[aá]|elegante|econ[oó]mic|tengo|presupuesto|recomi|busco|quiero/.test(bajo)) {
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
  if (/pendiente/.test(bajo)) {
    if (!hechos.length) return { c: [usar('resumen_pedidos', { periodo: 'todo' })], s: 'tool_use' };
    return ultimo.error ? fin('No pude consultarlo: ' + ultimo.datos) : fin(`Hay ${ultimo.datos.por_estado.pendiente || 0} pedidos pendientes de ${ultimo.datos.total} en total.`);
  }
  if (/vend|ventas/.test(bajo)) {
    if (!hechos.length) return { c: [usar('ventas', { periodo: /hoy/.test(bajo) ? 'hoy' : /mes/.test(bajo) ? 'mes' : 'semana' })], s: 'tool_use' };
    return ultimo.error ? fin('No pude consultarlo: ' + ultimo.datos) : fin(`Vendimos ${ultimo.datos.total_cobrado} en ${ultimo.datos.pedidos} pedidos.`);
  }
  if (/m[aá]s vendid|menor movimiento|menos vendid/.test(bajo)) {
    if (!hechos.length) return { c: [usar('ranking_productos', { periodo: 'mes', orden: /menor|menos/.test(bajo) ? 'menos' : 'mas' })], s: 'tool_use' };
    return fin('Ranking: ' + (ultimo.datos.productos || []).slice(0, 3).map(p => `${p.nombre} (${p.unidades})`).join(', '));
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
  const estado = u.match(/marca(?:r)? el pedido (FA-[A-Z0-9-]+) como (\w+)/i);
  if (estado) {
    if (!hechos.length) return { c: [usar('proponer_estado_pedido', { codigo: estado[1], estado: estado[2].toLowerCase(), nota: 'Desde el asistente' })], s: 'tool_use' };
    return ultimo.error ? fin('No se puede: ' + ultimo.datos) : fin('Preparé el cambio de estado. Confírmalo abajo.');
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
http.createServer((req, res) => {
  if (req.method === 'GET' && req.url === '/__estado') {
    res.writeHead(200, { 'content-type': 'application/json' });
    return res.end(JSON.stringify({ peticiones, ultima }));
  }
  if (req.method === 'POST' && req.url === '/__reiniciar') { peticiones = 0; ultima = null; cuenta429 = 0; res.writeHead(204); return res.end(); }
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
}).listen(PUERTO, '127.0.0.1', () => console.log('Messages API simulada en http://127.0.0.1:' + PUERTO));
