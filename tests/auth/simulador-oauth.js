/**
 * Simulador de Google OAuth/OIDC y de Facebook Login para pruebas locales.
 *
 * Permite probar de punta a punta, sin cuentas reales, todo lo que hace la
 * tienda con los proveedores: el ida y vuelta del navegador (state, nonce,
 * redirect_uri), el canje del código con el secreto, la validación del token
 * y los casos que con los servicios reales no se pueden provocar: cancelar,
 * token de otra app, emisor falso, token caducado, perfil sin correo…
 *
 * Solo lo usa la tienda en APP_ENTORNO=dev con
 *   OAUTH_SIMULADOR=http://127.0.0.1:8798
 *
 * Uso:  node tests/auth/simulador-oauth.js [puerto]      (por defecto 8798)
 *   POST /__identidad {"google": {...}, "facebook": {...}}  identidad de la próxima vez
 *   POST /__escenario {"nombre": "cancelar" | "aud_ajeno" | "iss_falso" | "caducado"
 *                               | "nonce_ajeno" | "sin_verificar" | "app_ajena"
 *                               | "usuario_distinto" | "sin_correo" | "token_invalido" | ""}
 *   GET  /__registro   → últimas peticiones recibidas (sin secretos)
 *
 * Credenciales que acepta (solo de prueba): GOOGLE_CLIENT_ID=sim-google.apps.googleusercontent.com,
 * GOOGLE_CLIENT_SECRET=sim-google-secreto, FACEBOOK_APP_ID=1234567890,
 * FACEBOOK_APP_SECRET=simfacebooksecreto0123456789abcd.
 */
'use strict';
const http = require('http');
const crypto = require('crypto');

const PUERTO = +process.argv[2] || 8798;
const G = { id: 'sim-google.apps.googleusercontent.com', secreto: 'sim-google-secreto' };
const F = { id: '1234567890', secreto: 'simfacebooksecreto0123456789abcd' };

let identidad = {
  google: { sub: '1000000000000000001', email: 'cliente.google@gmail.com', given_name: 'Cliente', family_name: 'Google' },
  facebook: { id: '2000000000000001', email: 'cliente.facebook@ejemplo.test', first_name: 'Cliente', last_name: 'Facebook' },
};
let escenario = '';
const codigos = new Map();   // code → {proveedor, redirect_uri, nonce, id}
const tokens = new Map();    // token de Facebook → id
const registro = [];

function responder(res, estado, cuerpo, cabeceras = {}) {
  res.writeHead(estado, Object.assign({ 'content-type': 'application/json' }, cabeceras));
  res.end(typeof cuerpo === 'string' ? cuerpo : JSON.stringify(cuerpo));
}
function anotar(ruta, datos) { registro.push({ ruta, ...datos }); if (registro.length > 50) registro.shift(); }

http.createServer((req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  const q = Object.fromEntries(url.searchParams);
  let raw = '';
  req.on('data', d => raw += d);
  req.on('end', () => {
    const cuerpo = req.headers['content-type'] && req.headers['content-type'].includes('json')
      ? (raw ? JSON.parse(raw) : {}) : Object.fromEntries(new URLSearchParams(raw));
    const p = url.pathname;

    if (p === '/__identidad') { identidad = Object.assign({}, identidad, cuerpo); return responder(res, 200, { ok: true }); }
    if (p === '/__escenario') { escenario = cuerpo.nombre || ''; return responder(res, 200, { ok: true }); }
    if (p === '/__registro') return responder(res, 200, registro);

    // --- Google ------------------------------------------------------------
    if (p === '/google/o/oauth2/v2/auth') {
      anotar(p, { client_id: q.client_id, redirect_uri: q.redirect_uri, scope: q.scope, con_state: !!q.state, con_nonce: !!q.nonce });
      if (q.client_id !== G.id || q.response_type !== 'code') return responder(res, 400, { error: 'invalid_request' });
      const vuelta = new URL(q.redirect_uri);
      if (escenario === 'cancelar') { vuelta.searchParams.set('error', 'access_denied'); vuelta.searchParams.set('state', q.state || ''); }
      else {
        const code = 'gc_' + crypto.randomBytes(8).toString('hex');
        codigos.set(code, { proveedor: 'google', redirect_uri: q.redirect_uri, nonce: q.nonce, id: { ...identidad.google } });
        vuelta.searchParams.set('code', code); vuelta.searchParams.set('state', q.state || '');
      }
      res.writeHead(302, { Location: vuelta.toString() }); return res.end();
    }
    if (p === '/google/token' && req.method === 'POST') {
      anotar(p, { client_id: cuerpo.client_id, secreto_ok: cuerpo.client_secret === G.secreto, redirect_uri: cuerpo.redirect_uri });
      const c = codigos.get(cuerpo.code); codigos.delete(cuerpo.code);   // un solo uso
      if (!c || c.proveedor !== 'google' || cuerpo.client_id !== G.id || cuerpo.client_secret !== G.secreto
          || cuerpo.redirect_uri !== c.redirect_uri || cuerpo.grant_type !== 'authorization_code')
        return responder(res, 400, { error: 'invalid_grant', error_description: 'Bad Request' });
      const idToken = 'sim.' + crypto.randomBytes(12).toString('hex');
      codigos.set(idToken, c);
      return responder(res, 200, { access_token: 'ya29.sim', expires_in: 3599, token_type: 'Bearer', id_token: idToken, scope: 'openid email profile' });
    }
    if (p === '/google/tokeninfo') {
      anotar(p, {});
      const c = codigos.get(q.id_token);
      if (!c) return responder(res, 400, { error: 'invalid_token', error_description: 'Invalid Value' });
      const ahora = Math.floor(Date.now() / 1000);
      const id = c.id;
      const claims = {
        iss: escenario === 'iss_falso' ? 'https://evil.example' : 'https://accounts.google.com',
        aud: escenario === 'aud_ajeno' ? 'otra-app.apps.googleusercontent.com' : G.id,
        azp: G.id, sub: id.sub, email: id.email,
        email_verified: escenario === 'sin_verificar' ? 'false' : 'true',
        nonce: escenario === 'nonce_ajeno' ? 'nonce-de-otra-peticion' : c.nonce,
        iat: String(ahora - 5), exp: String(escenario === 'caducado' ? ahora - 60 : ahora + 3600),
        given_name: id.given_name || '', family_name: id.family_name || '', picture: 'https://lh3.googleusercontent.com/a/sim',
      };
      if (id.hd) claims.hd = id.hd;
      return responder(res, 200, claims);
    }

    // --- Facebook ----------------------------------------------------------
    const fb = p.match(/^\/facebook\/(v\d+\.\d)\/(.+)$/);
    if (fb) {
      const ruta = fb[2];
      if (ruta === 'dialog/oauth') {
        anotar(p, { client_id: q.client_id, redirect_uri: q.redirect_uri, scope: q.scope, con_state: !!q.state, auth_type: q.auth_type || '' });
        if (q.client_id !== F.id) return responder(res, 400, { error: { message: 'Invalid App ID', type: 'OAuthException', code: 101 } });
        const vuelta = new URL(q.redirect_uri);
        if (escenario === 'cancelar') {
          vuelta.searchParams.set('error', 'access_denied'); vuelta.searchParams.set('error_code', '200');
          vuelta.searchParams.set('error_description', 'Permissions error'); vuelta.searchParams.set('error_reason', 'user_denied');
          vuelta.searchParams.set('state', q.state || '');
        } else {
          const code = 'fc_' + crypto.randomBytes(8).toString('hex');
          codigos.set(code, { proveedor: 'facebook', redirect_uri: q.redirect_uri, id: { ...identidad.facebook } });
          vuelta.searchParams.set('code', code); vuelta.searchParams.set('state', q.state || '');
        }
        res.writeHead(302, { Location: vuelta.toString() }); return res.end();
      }
      if (ruta === 'oauth/access_token') {
        anotar(p, { client_id: q.client_id, secreto_ok: q.client_secret === F.secreto, redirect_uri: q.redirect_uri });
        const c = codigos.get(q.code); codigos.delete(q.code);
        if (!c || c.proveedor !== 'facebook' || q.client_id !== F.id || q.client_secret !== F.secreto || q.redirect_uri !== c.redirect_uri)
          return responder(res, 400, { error: { message: 'Error validating verification code.', type: 'OAuthException', code: 100 } });
        const token = 'EAAsim' + crypto.randomBytes(12).toString('hex');
        tokens.set(token, c.id);
        return responder(res, 200, { access_token: token, token_type: 'bearer', expires_in: 5183944 });
      }
      if (ruta === 'debug_token') {
        anotar(p, { app_token_ok: q.access_token === F.id + '|' + F.secreto });
        if (q.access_token !== F.id + '|' + F.secreto) return responder(res, 400, { error: { message: 'Invalid OAuth access token.', type: 'OAuthException', code: 190 } });
        const id = tokens.get(q.input_token);
        if (!id || escenario === 'token_invalido') return responder(res, 200, { data: { is_valid: false, error: { code: 190, message: 'Invalid' } } });
        return responder(res, 200, { data: {
          app_id: escenario === 'app_ajena' ? '9999999999' : F.id, type: 'USER', application: 'Flowers Anto',
          expires_at: Math.floor(Date.now() / 1000) + (escenario === 'caducado' ? -60 : 3600),
          is_valid: true, scopes: ['email', 'public_profile'], user_id: id.id,
        } });
      }
      if (ruta === 'me') {
        const id = tokens.get(q.access_token);
        const proofOk = id && q.appsecret_proof === crypto.createHmac('sha256', F.secreto).update(q.access_token).digest('hex');
        anotar(p, { fields: q.fields, appsecret_proof_ok: !!proofOk });
        if (!id) return responder(res, 400, { error: { message: 'Invalid OAuth access token.', type: 'OAuthException', code: 190 } });
        if (!proofOk) return responder(res, 400, { error: { message: 'Invalid appsecret_proof provided in the API argument', type: 'GraphMethodException', code: 100 } });
        const perfil = { id: escenario === 'usuario_distinto' ? '2999999999999999' : id.id, first_name: id.first_name, last_name: id.last_name,
                         picture: { data: { url: 'https://platform-lookaside.fbsbx.com/sim' } } };
        if (id.email && escenario !== 'sin_correo') perfil.email = id.email;
        return responder(res, 200, perfil);
      }
    }
    responder(res, 404, { error: 'no encontrado: ' + p });
  });
}).listen(PUERTO, '127.0.0.1', () => console.log('Simulador OAuth (Google + Facebook) en http://127.0.0.1:' + PUERTO));
