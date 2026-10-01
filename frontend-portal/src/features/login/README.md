# login

Acceso con codigo de empleado y PIN, sin correo electronico y sin credencial en el movil (RF-ID-06, ADR-014, ADR-015). Tarea 1.11.

- `LoginView.vue` — el unico formulario del portal. Un solo mensaje de error para cualquier rechazo (RS-03, regla dura 17): el cliente no desune lo que el servidor ya unifica.
- `login.api.ts` — `POST /api/v1/me/login`, la unica peticion anonima, con `requestJson` de `@kronoqr/web-kit/http` (ADR-036).
- `session.store.ts` — token de ambito `self:read` en `sessionStorage` (muere con la pestaña). `signOut` (boton «Salir») llama a `POST /api/v1/me/logout` con un tiempo maximo de 3 s y borra la sesion local SIEMPRE, salga lo que salga la llamada (204, 401, 403, 429, error de red o tiempo agotado); `signOutLocally` solo olvida la sesion en este dispositivo.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
