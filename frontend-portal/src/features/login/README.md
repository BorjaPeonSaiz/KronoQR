# login

Acceso con codigo de empleado y PIN, sin correo electronico y sin credencial en el movil (RF-ID-06, ADR-014, ADR-015). Tarea 1.11.

- `LoginView.vue` — el unico formulario del portal: codigo de empleado y PIN de 6 a 8 cifras (`IDENTITY_PIN_LENGTH`, ADR-050; conviven PIN de 6 y de 8, asi que acepta los dos). Un solo mensaje de error para cualquier rechazo (RS-03, regla dura 17): el cliente no desune lo que el servidor ya unifica.
- **Bloqueo por conexion** (ADR-050). Si el servidor bloquea el origen tras demasiados fallos (`urn:kronoqr:problem:portal-origin-locked`), la pantalla lo avisa con una cuenta atras a partir de `Retry-After` y no deja enviar hasta que termina. La hora limite se fija una vez; el intervalo solo refresca la vista. El aviso no dice nada del codigo ni del PIN.
- `login.api.ts` — `POST /api/v1/me/login`, la unica peticion anonima, con `requestJson` de `@kronoqr/web-kit/http` (ADR-036).
- `session.store.ts` — token de ambito `self:read` en `sessionStorage` (muere con la pestaña). `signOut` (boton «Salir») llama a `POST /api/v1/me/logout` con un tiempo maximo de 3 s y borra la sesion local SIEMPRE, salga lo que salga la llamada (204, 401, 403, 429, error de red o tiempo agotado); `signOutLocally` solo olvida la sesion en este dispositivo.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
