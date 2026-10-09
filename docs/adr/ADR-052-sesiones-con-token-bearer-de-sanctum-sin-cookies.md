# ADR-052 — Las sesiones son tokens Bearer de Sanctum guardados por cada SPA, sin cookies ni CSRF

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. Describe lo que el código hace desde la Fase 1; no cambia nada. Revisión de `seguridad-cumplimiento` hecha en el bloque 14 (nota del 09-10-2026) |
| **Fecha** | 8 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 14 de la 2.2.0, hallazgo A6-1 de la [tanda 6 de la 2.1.0](../verificacion/2.1.0-tanda-6.md), abierto en la [re-verificación](../verificacion/2.2.0-reverificacion-tandas-5-6-7.md)) |
| **Afecta a** | Recoge decisiones repartidas en [ADR-015](ADR-015-portal-con-codigo-y-pin.md), [ADR-020](ADR-020-soporte-con-paquete-de-diagnostico.md), [ADR-039](ADR-039-que-hechos-de-autenticacion-dejan-asiento.md), [ADR-041](ADR-041-descarga-de-ficheros-diferidos-con-enlace-de-un-solo-uso.md), [ADR-044](ADR-044-el-token-del-quiosco-rota-en-el-latido-con-solape.md), [ADR-050](ADR-050-portal-accesible-desde-internet.md) y [ADR-051](ADR-051-cuentas-de-gestion-desde-el-panel-con-contrasenas-temporales.md) · `backend/config/sanctum.php`, `backend/config/cors.php`, `backend/config/identity.php` · `backend/app/Modules/Identity/IdentityServiceProvider.php` · `infra/docker/nginx/snippets/security-headers.conf` · `frontend-admin/src/features/auth/session.store.ts`, `frontend-portal/src/features/login/session.store.ts`, `frontend-kiosk/src/shared/telemetry/deviceIdentity.ts` · doc 02 §7.2 y §7.3 |
| **Requisitos** | RF-ID-01, RF-ID-04, RF-ID-07, RF-PD-11, RS-04, RS-06, RS-09, RS-12, RS-13 · reglas duras 12, 16 y 18 |

## Contexto

El modelo de autenticación de la API decide tres cosas a la vez: qué se lleva un atacante con un XSS, qué tiene que prohibir la CSP y qué forma tiene cada petición de las tres SPA. Se fue construyendo tarea a tarea, desde la 1.5 hasta el bloque 12c de la 2.2.0. Hay piezas en siete ADR, en los docblocks de los almacenes de sesión y en `IdentityServiceProvider`, pero ningún ADR lo decide como un todo. La verificación de la 2.1.0 lo registró como A6-1, y la re-verificación de la 2.2.0 lo confirmó abierto: `grep -l "sanctum\|sessionStorage\|localStorage\|bearer\|csrf" docs/adr/*.md` solo devolvía una alternativa descartada de ADR-015, el contexto de ADR-041 y el token del quiosco de ADR-044. Mientras tanto, la CSP cambió (`'wasm-unsafe-eval'`, PIN-01) sin ningún ADR que fijara sus límites.

Este ADR recoge lo que hay. No propone ningún cambio de comportamiento.

## Decisión

**Toda la API `/api/v1` se autentica con tokens personales de Laravel Sanctum enviados en la cabecera `Authorization: Bearer`. Ninguna ruta del producto usa una cookie de sesión. Cada SPA guarda su propio token en el almacenamiento del navegador que corresponde a la vida de su sesión.**

### 1. Sanctum solo en modo token

`backend/config/sanctum.php` deja `stateful => []` y `guard => []`. Ningún origen recibe autenticación por cookie, y Sanctum no cae al guard `web`. El producto no tiene rutas `web` (`bootstrap/app.php`, comentario de `withBroadcasting`): la autorización de los canales de Reverb también va bajo `/api/v1`, con `auth:sanctum` y `ability:attendance:read`. Sanctum guarda cada token como SHA-256 en `personal_access_tokens`, así que el token en claro solo existe en la respuesta que lo emite y en el cliente que lo guarda. `SANCTUM_TOKEN_PREFIX` permite que un escáner de secretos lo reconozca. `expiration` es `null` de forma global porque **cada emisor fija su `expires_at`**.

### 2. Cuatro titulares, cuatro emisores y una sola comprobación

| Titular (`tokenable`) | Emisor | Ámbitos | Caducidad | Dónde lo guarda el cliente |
|---|---|---|---|---|
| Cuenta de gestión (`users`) | `Identity\…\SanctumAccessTokenIssuer` | los del rol (doc 02 §7.3); `2fa:pending` durante el reto; `password:change` con contraseña temporal (ADR-051) | `IDENTITY_SESSION_TOKEN_HOURS`, 12 h de serie; el reto, `IDENTITY_2FA_CHALLENGE_MINUTES`, 10 min | `sessionStorage` (`kronoqr.admin.session`): token y caducidad, sin datos personales. El `challenge_token` del 2FA **no** se persiste |
| Empleado en el portal (`employees`) | `Workforce\…\SanctumPortalSessionIssuer` | `self:read` | `IDENTITY_PORTAL_SESSION_HOURS`, 2 h de serie | `sessionStorage` (`kronoqr.portal.session`): token, caducidad y los datos propios de quien entró |
| Quiosco (`devices`) | `Identity\…\SanctumDeviceTokenIssuer` | `scan:write`, `roster:read`, `heartbeat:write` | `IDENTITY_DEVICE_TOKEN_DAYS`, 90 días, con rotación en el latido y solape (ADR-044) | `localStorage` (`kronoqr.kiosk.device_token` y el anterior durante el solape) |
| Acceso de soporte (`support_grants`) | `Product\…\SanctumSupportTokenIssuer` | los de `SupportScope::abilities()` (ADR-020, enmienda del 08-10-2026) | la de la concesión, 24 h de serie y 72 h como máximo | no lo guarda ninguna SPA: el `admin` lo entrega al fabricante |

**Las dos sesiones restringidas de una cuenta de gestión llegan solo a lo suyo.** `2fa:pending` llega a `/auth/2fa/*` y a `/auth/logout`; desde el bloque 14 de la 2.2.0, ya no a `POST /client-errors` (la revisión de seguridad lo encontró: quien tiene la contraseña y no el segundo factor podía escribir en `error_events`; los errores de la pantalla del TOTP esperan en el búfer del cliente y salen con la primera sesión completa). `password:change` llega a lo que deja pasar `session.password-settled` (cambiar la contraseña y cerrar sesión, ADR-051). `backend/tests/Feature/AuthorizationMatrixTest.php` prueba las dos ruta por ruta.

**Una sola puerta comprueba si un token sigue valiendo:** `IdentityServiceProvider::rejectTokensOfDeactivatedAccounts()`, sobre `Sanctum::authenticateAccessTokensUsing`. Mira el estado de su titular en **cada** petición: `users.is_active`, la revocación del dispositivo, RN-14 para el empleado y la vigencia de la concesión de soporte. Un titular que no reconoce **falla cerrado**. Por eso una baja o una revocación valen en la petición siguiente y no cuando caduca el token. Los dos titulares que no son de `Identity` se reconocen por su tabla y no por su clase, para no romper la frontera del doc 02 §1.6.

### 3. Por qué `sessionStorage` en el panel y el portal, y `localStorage` en el quiosco

- **Panel y portal: `sessionStorage`.** La sesión muere al cerrar la pestaña. El panel se abre en el ordenador compartido de recepción, y el portal en el móvil personal o en un ordenador del centro que usa más gente. Una sesión que sobreviviera al navegador dejaría al siguiente turno dentro. El precio es que cada pestaña nueva pide otra vez las credenciales, y se acepta.
- **Quiosco: `localStorage`.** La tablet no tiene a nadie delante que pueda volver a autenticarla. Tiene que seguir fichando después de un reinicio o de un corte de corriente (regla dura 19), y su token dura 90 días. La cola offline vive en IndexedDB (Dexie), nunca en `localStorage`.
- **Ningún secreto de una sola vez se persiste.** El PIN, la contraseña temporal y el `challenge_token` del 2FA viven en el estado efímero del componente que los muestra o los pide, y desaparecen al cerrarlo.

### 4. Sin cookies, CSRF no aplica, y CORS se cierra al propio origen

El navegador no adjunta la cabecera `Authorization` por su cuenta, así que una página ajena no puede hacer que el navegador de la víctima envíe una petición autenticada. La protección CSRF de Laravel no se usa porque no hay nada que proteger con ella, no por descuido. `config/cors.php` lleva `supports_credentials => false` y limita el origen al de `APP_URL` (ADR-050 §6). Los enlaces que se abren sin cabecera, como las descargas en diferido, llevan su propio secreto de un solo uso (ADR-041), nunca la sesión en la URL.

### 5. Lo que expone un XSS, dicho con exactitud, y lo que la CSP tiene que impedir

Con este modelo, **un script ajeno que llegue a ejecutarse en una SPA lee el token de esa SPA y lo puede usar desde fuera** hasta que caduque o se revoque. Es la contrapartida conocida del token en el almacenamiento del navegador frente a una cookie `HttpOnly`. Por eso la defensa contra el XSS forma parte de esta decisión:

- **CSP sin `'unsafe-inline'` ni `'unsafe-eval'`** en `script-src` (`infra/docker/nginx/snippets/security-headers.conf`). La única excepción es `'wasm-unsafe-eval'`: libsodium compila WebAssembly para sellar el PIN del quiosco (PIN-01) y no permite `eval` de JavaScript. `connect-src 'self' wss:`, `frame-ancestors 'none'`, `object-src 'none'` y `base-uri 'self'`. **Cualquier relajación de `script-src` o de `connect-src` exige un ADR nuevo.** `QualityGatesTest` comprueba que `'unsafe-inline'` y `'unsafe-eval'` no aparecen.
- **Vue escapa por defecto y `v-html` está prohibido** por la regla de ESLint `vue/no-v-html` en las tres SPA y en `packages/web-kit`.
- **El daño queda acotado por la vida del token y por la revocación inmediata:** 12 h la gestión, 2 h el portal; salir revoca el token en el servidor (`LogoutController`, `PortalLogoutController`), y una baja lo invalida en la petición siguiente. Un token robado de gestión no basta para crear cuentas ni para restablecer el segundo factor de otra persona, porque esas acciones exigen reautenticarse con TOTP en la misma petición (ADR-051 §7).
- **Las tres SPA comparten origen** (`/admin/`, `/kiosk/` y `/portal/` en el mismo servidor). `sessionStorage` es además de cada pestaña, así que un XSS en una pestaña del portal no alcanza el token del panel abierto en otra. Pero `localStorage` es del origen, así que un XSS que se ejecutara **en el navegador de la tablet** leería el token del quiosco. El quiosco no navega fuera de `/kiosk/`, y su token solo da los tres ámbitos del dispositivo (RS-04).

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Sanctum en modo SPA: cookie de sesión `HttpOnly` con `stateful` y CSRF** | Un XSS ya no se llevaría el token, pero seguiría pudiendo actuar en nombre de la víctima mientras la pestaña está abierta: la diferencia es menor de lo que parece. A cambio, hay que dar CSRF a toda la API. El quiosco seguiría necesitando un token, porque no es una persona y no tiene sesión. El portal, abierto desde internet (ADR-050), tendría que compartir sesión de cookie con el panel en el mismo origen. Habría dos mecanismos de autenticación donde ahora hay uno |
| **`localStorage` también en panel y portal** | La sesión sobreviviría a cerrar el navegador en un equipo compartido, que es justo el escenario real de los dos |
| **Guardar el token solo en memoria (Pinia)** | Una recarga cierra la sesión. En el panel, cada recarga a mitad de una corrección pediría contraseña y TOTP otra vez, y la presión acabaría pidiendo alargar la sesión, que es peor |
| **JWT firmados sin estado** | No se pueden revocar en la petición siguiente sin una lista de revocación, que es reinventar la tabla de Sanctum. La baja inmediata (ADR-051) y la revocación del quiosco robado (RS-04) dependen de esa comprobación |

## Consecuencias

- **La forma de toda la API queda fijada:** cada petición autenticada lleva `Authorization: Bearer`, y ninguna depende de una cookie. El contrato OpenAPI declara tres esquemas, `kioskToken`, `employeeToken` y `managementToken`. Son de tipo `oauth2` solo porque es la única forma de que OpenAPI exprese ámbitos por operación (comentario en `components.securitySchemes`). No declara ningún esquema de cookie.
- **La CSP es parte del modelo de sesión**, no solo una cabecera más. Relajarla cambia lo que expone un XSS, y por eso exige un ADR. Hoy es **común a las tres SPA** (`security-headers.conf`), así que `'wasm-unsafe-eval'`, que solo necesita el quiosco, llega también al panel y al portal. **Estrecharla por ruta** (una CSP por `location` de nginx) es endurecimiento y no necesita ADR.
- **Cada titular nuevo de token** tiene que pasar por `rejectTokensOfDeactivatedAccounts()` o fallará cerrado. Es la condición para añadir un quinto `tokenable`.
- **Pendiente, sin guarda que lo vigile:** nada impide hoy que el panel o el portal empiecen a usar `localStorage`. La decisión vive en el docblock de cada `session.store.ts` y en sus pruebas unitarias, que comprueban `sessionStorage`, pero ninguna regla falla si aparece `localStorage` en esas dos SPA. **Propuesta para `frontend-panel` y `frontend-portal-empleado`:** `no-restricted-globals` / `no-restricted-properties` de ESLint contra `localStorage` en `frontend-admin/src` y `frontend-portal/src`.

## Verificación

- Arquitectura: `QualityGatesTest` comprueba la CSP (`'wasm-unsafe-eval'` presente, sin `'unsafe-inline'` ni `'unsafe-eval'`).
- Integración: `CredentialLeakGuardsTest` («no consulta ningún guard de sesión antes del token portador») afirma `sanctum.guard` y `sanctum.stateful` vacíos y `sanctum.expiration` nulo. Un guard de sesión adjuntaría un `TransientToken` cuyo `can()` acepta cualquier ámbito.
- Feature: `CorsSameOriginTest` comprueba `supports_credentials => false`, la ausencia de `Access-Control-Allow-Credentials` y el origen único.
- Integración y feature de cada titular: una cuenta dada de baja, un quiosco desvinculado, un empleado que deja de cumplir RN-14 y una concesión de soporte revocada reciben `401` en la petición siguiente.
- Unitarias de las SPA: `frontend-admin/tests/unit/session.store.spec.ts` y `frontend-portal/tests/unit/session.store.spec.ts` comprueban que la sesión va a `sessionStorage`.
- ESLint: `vue/no-v-html` en las tres SPA y en `packages/web-kit`.

## Nota 09-10-2026 (2.2.0 publicada): estado

**Aceptada, con la revisión de `seguridad-cumplimiento` hecha.** La decisión no cambia.

- **Revisión:** se hizo en el bloque 14 de la 2.2.0, sobre el ADR y el código que describe. Encontró que la sesión restringida `2fa:pending` llegaba a `POST /client-errors`; se corrigió en el mismo bloque y quedó escrito en §2 («Las dos sesiones restringidas…») con el commit `docs(seguridad): doc 07 revisado en el bloque 14, … sesiones restringidas en ADR-052…` (08-10-2026). La misma revisión añadió en Consecuencias que la CSP es común a las tres SPA.
- **Verificación final:** la de seguridad lo comprueba en `main` ([2.2.0-verificacion-final-tanda-4.md](../verificacion/2.2.0-verificacion-final-tanda-4.md), «Suplantación: ADR-052, Bearer sin cookies»: `config/sanctum.php` sin `stateful` ni `guard`, y ninguna `Set-Cookie` en `/api/v1/health` ni en `/auth/login`). La de arquitectura da A6-1 por corregido con este ADR ([2.2.0-verificacion-final-tanda-3.md](../verificacion/2.2.0-verificacion-final-tanda-3.md)).
- **Siguen pendientes para la 2.2.1**, y no son de la revisión: la regla de ESLint contra `localStorage` en el panel y el portal (Consecuencias) y la CSP por ubicación ([`2.2.1-lista-cambios.md`](../verificacion/2.2.1-lista-cambios.md) §3).
- La cabecera se actualiza por esta nota.
