# auth

Acceso al panel de gestión: sesión, ámbitos del token, segundo factor obligatorio y guarda de
rutas (RF-ID-01, RF-ID-02, RS-06). Sesión mínima de la tarea 1.6; el segundo factor (`LoginView`
con sus tres pasos, `challenge_token`, alta con QR) es de la 2.1.

**Solo personal de gestión.** El empleado no entra por aquí: su portal usa código de empleado
y PIN (ADR-015, regla dura 12) y vive en `frontend-portal/`.

**El `challenge_token` del segundo factor nunca es una sesión.** `LoginView` lo guarda en su
propio estado efímero, nunca en `session.store.ts` ni en `sessionStorage`: recargar la página a
mitad del reto vuelve al primer paso, no deja nada a medias. `session.store.ts` solo adopta una
sesión ya emitida, con `applySession`, tanto si viene directa de `logIn` como si viene de
`verifyTwoFactor`/`confirmTwoFactor`.

**Cambio de la contraseña propia** (`ChangePasswordView.vue`, ruta `/account/password`, RF-ID-10).
Voluntario para cualquier rol desde el enlace del marco, y obligado cuando la cuenta entra con
una contraseña temporal (alta o restablecimiento desde `accounts/`): mientras
`session.passwordChangeRequired` siga activo, la guarda del router no deja salir de esa pantalla.
La política de robustez la comprueba el servidor (`IDENTITY_PASSWORD_MIN_LENGTH`), no el cliente.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
