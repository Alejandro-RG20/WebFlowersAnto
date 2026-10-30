/**
 * Simulador de la Graph API de Meta (API de Conversiones) para pruebas locales.
 *
 * Responde como Meta a lo que manda la tienda —`POST /{pixel}/events` y la
 * consulta del píxel de «Probar conexión»— y guarda cada petición en un
 * archivo para poder comprobar qué salió: los eventos, sus identificadores
 * (que tienen que coincidir con los del navegador), el `fbc` del anuncio y
 * los datos del cliente resumidos.
 *
 * Solo lo usa la tienda en APP_ENTORNO=dev con
 *   META_GRAPH_SIMULADOR=http://127.0.0.1:8899
 *
 * Uso:  node tests/meta/simulador-graph.js [archivo-registro] [puerto]
 *   Token válido: TOKENBUENO. Cualquier otro responde como un token caducado
 *   (error 190), para ver el aviso del panel y de la salud del sitio.
 */
'use strict';

const http = require('http');
const fs = require('fs');

const registro = process.argv[2] || 'graph.log';
const puerto = Number(process.argv[3]) || 8899;

http.createServer((req, res) => {
  let cuerpo = '';
  req.on('data', (c) => { cuerpo += c; });
  req.on('end', () => {
    fs.appendFileSync(registro, JSON.stringify({ metodo: req.method, ruta: req.url, cuerpo }) + '\n');
    const param = new URLSearchParams(cuerpo);
    const token = param.get('access_token') || (req.headers.authorization || '').replace(/^Bearer /, '');
    res.setHeader('Content-Type', 'application/json');

    if (token !== 'TOKENBUENO') {
      res.statusCode = 400;
      res.end(JSON.stringify({ error: { message: 'Invalid OAuth access token.', type: 'OAuthException', code: 190 } }));
      return;
    }
    if (req.method === 'GET') {
      res.end(JSON.stringify({ name: 'Píxel de pruebas', id: req.url.split('/')[2].split('?')[0] }));
      return;
    }
    let eventos = [];
    try { eventos = JSON.parse(param.get('data') || '[]'); } catch (e) { /* se responde 0 */ }
    res.end(JSON.stringify({ events_received: eventos.length, messages: [], fbtrace_id: 'simulado' }));
  });
}).listen(puerto, '127.0.0.1', () => console.log('Graph API simulada en http://127.0.0.1:' + puerto));
