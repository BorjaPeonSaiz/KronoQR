# Pruebas del portal del empleado

## Unitarias (`tests/unit/`)

Vitest + Vue Test Utils, sin backend: `npm run test:unit`. Cubre las tres pantallas
(`login`, `my-records`, `my-export`), la tienda de sesión, la marca y el enrutador. Ver
`tests/unit/support/fixtures.ts` para los datos de ejemplo, calcados del contrato.

## Playwright (`tests/e2e/`, `tests/screenshots/`) — nace en la tarea 5.11b

El portal no tenía Playwright: hasta la tarea 5.11b (bloque C, guía de uso del portal para
el empleado) solo existían las unitarias de arriba. Lo que se añadió es:

- **`tests/e2e/support/portal.ts`**: el doble de la API del portal (`stubPortalApi`), con la
  misma disciplina que `frontend-admin/tests/e2e/support/admin.ts` — cada respuesta tiene la
  forma del contrato (`import type` de `@/shared/api/types`), datos de demostración nunca
  reales (regla dura 21: «Youssef Amrani», el mismo personaje que usan los dobles del panel y
  del quiosco) y una jornada con una **corrección visible**, con su motivo y el valor
  anterior. También lleva `logInToPortal(page, credentials)`, que entra por la pantalla de
  acceso con código y PIN (nunca correo ni contraseña, regla dura 12).
- **`tests/screenshots/portal.screenshots.ts`**: genera las cuatro capturas de
  `docs/cliente/guia-portal-empleado.md` en `docs/cliente/img/{es,en}/`
  (`portal-01-acceso`, `portal-02-jornadas`, `portal-03-correccion-visible`,
  `portal-04-descarga`), sobre el doble anterior. Se ejecuta a mano, nunca en la CI (no se
  suben binarios desde un runner):

  ```bash
  npm run docs:screenshots
  ```

- **`playwright.screenshots.config.ts`**: viewport de **móvil** (412×915, `Pixel 7`,
  `isMobile: true`) y a propósito — quien consulta el portal casi siempre lo abre desde su
  teléfono personal, no del ordenador del centro (doc 02 §11, prioridad móvil). Construye y
  sirve el `dist/` real con `vite preview`, igual que el panel y el quiosco. Puerto 4178,
  distinto del 4174/4176 del panel y del 4177 del quiosco.

### Lo que falta: el recorrido E2E funcional

`stubPortalApi` y `logInToPortal` se escribieron pensando en un E2E futuro (acceso con PIN
incorrecto y bloqueo, `redirect` tras entrar, filtro de periodo, descarga con
`waitForEvent('download')`, comprobación de autorización negativa entre pestañas), pero **ese
recorrido no se ha escrito todavía**: el alcance de la tarea 5.11b, bloque C, era el doble y
las capturas, no el E2E. No hay `playwright.config.ts` de funcional (solo el de capturas) ni
`npm run test:e2e`. Cuando se escriba, sigue el patrón de
`frontend-admin/tests/e2e/README.md`: se prueba el `dist/` con `vite preview`, el backend no
participa (regla dura 18: la autorización real se prueba en el backend), y cada prueba se
etiqueta con el requisito que cubre (`RF-ID-06`, `RF-ID-07`, `RL-05`).
