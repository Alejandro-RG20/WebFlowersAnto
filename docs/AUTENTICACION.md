# Autenticación: cuentas, sesiones, Google y Facebook

Cómo entra la gente en Flowers Anto, qué reglas protegen cada paso y qué hay
que configurar fuera del código. Complementa las secciones «Acceso con Google»
y «Acceso con Facebook» del README.

---

## 1. Formas de entrar

| Forma | Dónde | Identificador estable |
|-------|-------|-----------------------|
| Correo y contraseña | `cuenta/entrar.php`, `cuenta/registrar.php` | `usuarios.email` |
| Google (OpenID Connect) | `cuenta/google.php` → `cuenta/google-callback.php` | `usuarios.google_id` (`sub`) |
| Facebook (OAuth 2.0 de Meta) | `cuenta/facebook.php` → `cuenta/facebook-callback.php` | `usuarios.facebook_id` (id de usuario para esta app) |

Un solo sistema de cuentas: Google y Facebook son formas de entrar en la misma
fila de `usuarios`, con el mismo rol, los mismos pedidos y la misma sesión.
Las dos pasan por `includes/lib/cuentas_externas.php` (`CuentasExternas`), que
decide con las mismas reglas.

---

## 2. Cuándo una identidad externa entra en una cuenta que ya existe

El peligro clásico es «mismo correo = misma persona». No siempre es cierto:

- Alguien puede **registrar antes** el correo de otra persona (sin confirmarlo)
  y esperar a que la dueña entre con Google: si la cuenta se enlazara sin más,
  el atacante conservaría su contraseña y vería todo lo de la víctima.
- Un proveedor puede entregar un correo que **no controla** (Google con un
  correo de otra empresa; Facebook no dice si el correo está verificado).

Reglas:

| Caso | Qué pasa |
|------|----------|
| El id del proveedor ya está en una cuenta | Entra en esa cuenta (si está activa) |
| Correo nuevo en la tienda | Se crea una cuenta `cliente`. Con Google (correo verificado) queda verificada; con Facebook se envía el enlace de confirmación |
| Existe una cuenta con ese correo y **Google es la autoridad del correo** (`@gmail.com`, `@googlemail.com` o Workspace con `hd`) | Se enlaza. Si el correo de esa cuenta nunca se había confirmado, se **anulan** su contraseña y sus otras identidades, se cierran sus otras sesiones y se avisa por correo (neutraliza la cuenta preparada por un atacante) |
| Existe una cuenta con ese correo y el proveedor no es la autoridad (Google con otro dominio, o **Facebook siempre**) | No entra. Se explica que entre como siempre y conecte el proveedor desde «Mis datos» |
| La cuenta ya tiene otra identidad de ese proveedor | No entra |
| Facebook sin correo | No se crea cuenta; se explica |
| Cuenta desactivada | No entra |

**Conectar desde «Mis datos → Cuentas conectadas»**: con la sesión abierta y,
si la cuenta tiene contraseña, escribiéndola. Da un permiso de 10 minutos, de
un solo uso, para ese proveedor y ese usuario. Una sesión abierta y olvidada no
basta para que otra persona añada su Google o Facebook. Conectar y desconectar
avisa por correo. No se puede desconectar la única forma de entrar.

**Recuperar la contraseña** de una cuenta cuyo correo nunca se había confirmado
quita las identidades externas que tuviera (se añadieron antes de que nadie
demostrara ser el dueño del correo).

---

## 3. Verificaciones del protocolo

**Google** (`includes/lib/google.php`)
- `state` (anti-CSRF) y `nonce` (anti-reenvío) aleatorios, de un solo uso,
  comparados con `hash_equals`.
- Código canjeado en el servidor con el secreto; `redirect_uri` fija desde
  `APP_URL`.
- `id_token` validado por el endpoint oficial `tokeninfo` (firma), y además
  `aud` = nuestro client id, `iss` = `accounts.google.com`, `exp` futuro,
  `nonce` igual al enviado, `email_verified` verdadero.
- Sin seguir redirecciones en las llamadas del servidor.

**Facebook** (`includes/lib/facebook.php`)
- `state` de un solo uso con `hash_equals`; `scope=email,public_profile`.
- Código canjeado en el servidor con el secreto.
- `debug_token` con el token de app: `is_valid`, `app_id` = el nuestro, tipo
  `USER`, `expires_at` futuro; el `user_id` debe coincidir con el `id` de `/me`.
- `/me` con `appsecret_proof` (HMAC-SHA256 del token con el secreto).
- Las URLs con secreto o token nunca se escriben en registros; solo la ruta y
  el tipo de error de Meta.

**Ambos**: cancelar (`error=access_denied`) muestra «Cancelaste…»; otro error
del proveedor, un aviso genérico; callbacks sin código, con `state` ajeno o
reutilizados se rechazan. El destino tras entrar (`?volver=`) pasa por
`url_interna()`: solo rutas del propio sitio.

---

## 4. Contraseñas, login y sesiones

- `password_hash(PASSWORD_DEFAULT)` (bcrypt) con rehash automático. Mínimo 8
  caracteres con letras y números. bcrypt usa los primeros 72 bytes.
- Mismo mensaje para correo inexistente y contraseña incorrecta, con tiempo de
  respuesta igualado.
- Límites (tabla `rate_limits`, atómicos): 20 intentos por IP / 15 min; 5 por
  identidad escrita / 15 min (exista o no); 6 altas por IP / hora; 15 retornos
  de Google o Facebook por IP / 15 min; 10 comprobaciones de contraseña actual
  por cuenta / 15 min en «Mis datos».
- Sesión: cookie `FLOWERSANTO_SESS` HttpOnly, Secure (con HTTPS), SameSite=Lax;
  `use_strict_mode`, solo cookies; nuevo identificador al entrar y cada 30 min;
  cierre por 8 h de inactividad; cambiar la contraseña cierra las demás
  sesiones (`sesion_version`); salir solo por POST con token.
- Permisos siempre en el servidor (`Rbac::exigir`). El registro fija el rol
  `cliente`. Solo quien tiene `roles.gestionar` modifica a un super
  administrador.

---

## 5. Configuración externa

### Google Cloud (Credenciales → ID de cliente de OAuth → Aplicación web)
- **Orígenes de JavaScript autorizados**: `https://flowersanto.site.je`
- **URI de redireccionamiento autorizados**:
  `https://flowersanto.site.je/cuenta/google-callback.php`
  (añade también la del otro dominio si se usa para entrar, p. ej.
  `https://flowersantopedidos.site.je/cuenta/google-callback.php`)
- Pantalla de consentimiento: publicada («En producción»), permisos `openid`,
  `email`, `profile`.
- `.env`: `APP_URL=https://flowersanto.site.je`, `GOOGLE_CLIENT_ID`,
  `GOOGLE_CLIENT_SECRET`.

La URI tiene que coincidir **exactamente** con `{APP_URL}/cuenta/google-callback.php`
(esquema, dominio, sin `www` si `APP_URL` no lo lleva). Si no, Google responde
`redirect_uri_mismatch`.

### Meta for Developers
- App de tipo Consumidor con el producto **Inicio de sesión con Facebook**.
- *Configuración de Inicio de sesión con Facebook*: URI de redireccionamiento
  de OAuth válido `https://flowersanto.site.je/cuenta/facebook-callback.php`;
  OAuth del cliente y OAuth web activados; modo estricto y HTTPS obligatorios.
- *Configuración → Básica*: dominio `flowersanto.site.je`; política de
  privacidad `https://flowersanto.site.je/legal.php?doc=privacidad`;
  instrucciones de eliminación de datos
  `https://flowersanto.site.je/legal.php?doc=privacidad#borrar-datos`;
  icono y categoría.
- Permisos: `email`, `public_profile` (acceso estándar).
- Modo **Activo** (en desarrollo solo entran los roles/usuarios de prueba).
- `.env`: `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET` (y opcional
  `FACEBOOK_GRAPH_VERSION`). La clave secreta nunca va al navegador ni a Git.
- Aplicar la migración 023 (Admin → Base de datos).

---

## 6. Probar sin cuentas reales

`tests/auth/simulador-oauth.js` imita Google y Facebook (incluidos los casos
que con los servicios reales no se pueden provocar: token de otra app, emisor
falso, caducado, nonce ajeno, perfil sin correo, usuario distinto…).

```bash
node tests/auth/simulador-oauth.js 8798
# .env (solo desarrollo)
APP_ENTORNO=dev
APP_URL=http://127.0.0.1:8080
OAUTH_SIMULADOR=http://127.0.0.1:8798
GOOGLE_CLIENT_ID=sim-google.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=sim-google-secreto
FACEBOOK_APP_ID=1234567890
FACEBOOK_APP_SECRET=simfacebooksecreto0123456789abcd
```

`OAUTH_SIMULADOR` solo se obedece con `APP_ENTORNO=dev` y una dirección
`http://127.0.0.1:puerto`; en producción se ignora. Las credenciales de arriba
son de mentira y solo las acepta el simulador.

---

## 7. Riesgos que dependen de fuera del código

- **HTTPS**: sin certificado, la cookie no lleva `Secure`. En producción el
  sitio debe servirse siempre por HTTPS.
- **Correo**: la verificación y la recuperación dependen de que los correos
  lleguen (SMTP configurado).
- **Claves**: si se filtra `GOOGLE_CLIENT_SECRET` o `FACEBOOK_APP_SECRET`, hay
  que rotarlas en la consola del proveedor y actualizar el `.env`.
- **Aceptados a conciencia**: el alta devuelve la sesión abierta, lo que
  permite deducir si un correo ya existía (limitado a 6 altas por IP y hora);
  no hay caducidad absoluta de la sesión, solo por inactividad; un
  administrador gestiona a otros administradores por diseño.
