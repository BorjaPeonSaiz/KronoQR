# Pruebas del portal del empleado

## Unitarias (`tests/unit/`)

Vitest + Vue Test Utils, sin backend: `npm run test:unit`. Cubre las tres pantallas
(`login`, `my-records`, `my-export`), la tienda de sesión, la marca y el enrutador. Ver
`tests/unit/support/fixtures.ts` para los datos de ejemplo, calcados del contrato.

## Playwright funcional (`tests/e2e/`)

`npm run test:e2e`. Nace en la tarea 5.11b como doble de la API y datos de ejemplo
(`support/portal.ts`), y se completa en el cierre de la Fase 5 con el recorrido funcional:
acceso con código y PIN, mi registro (con una corrección visible), descarga del historial,
marca de la instalación, accesibilidad y reporte de errores de cliente. Detalle completo,
tabla de ficheros y decisiones en `tests/e2e/README.md`.

## Capturas (`tests/screenshots/`)

`npm run docs:screenshots`. Genera las cuatro capturas de
`docs/cliente/guia-portal-empleado.md` en `docs/cliente/img/{es,en}/` sobre el mismo doble
(`support/portal.ts`). Viewport de móvil (412×915, `Pixel 7`), puerto 4178 —distinto del 4175
del E2E funcional—; se ejecuta a mano, nunca en la CI (no se suben binarios desde un
runner).
