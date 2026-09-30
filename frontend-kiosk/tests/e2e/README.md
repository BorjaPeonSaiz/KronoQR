# Pruebas E2E del quiosco

Playwright con camara simulada (doc 02 §9.4 y §2). `make e2e` invoca `npm run test:e2e` en
esta aplicacion.

```bash
npm run test:e2e                       # los dos proyectos
npx playwright test --project=kiosk-qr # solo el QR limpio
npx playwright test --grep @RF-KI-09   # por etiqueta de requisito (§9.6)
```

## Que hay aqui

| Fichero                 | Cubre                                                                        |
| ----------------------- | ---------------------------------------------------------------------------- |
| `scan.spec.ts`          | `@RF-KI-01`, `@RF-KI-02`, `@RF-KI-05`, `@RF-KI-06`, `@RF-KI-09`, `@RF-AT-05` |
| `degraded.spec.ts`      | `@RF-KI-02`, `@RF-QR-05` — tarjeta deteriorada                               |
| `accessibility.spec.ts` | `@RF-KI-06` con `@axe-core/playwright`, 0 violaciones criticas o graves      |
| `offline.spec.ts`       | `@RF-KI-03`, `@RF-KI-04`, `@RQ-05` — cola offline y sincronizacion           |
| `diagnostics.spec.ts`   | `@RF-KI-08` — pantalla de diagnostico                                        |
| `update-window.spec.ts` | `@RF-KI-07`, `@RF-KI-04`, `@RQ-05`, `@RF-KI-08` — ventana de actualizacion   |
| `pin-csp.spec.ts`       | `@RF-AT-11`, `@RS-09` — PIN sellado con WebAssembly bajo la CSP de Nginx     |

## Dos proyectos, y por que

Chromium admite **un solo** fichero de video falso por proceso: `--use-file-for-fake-video-capture`
es un argumento de arranque, no algo que se cambie por pestana. Como hay que probar el QR
limpio y el degradado, hay dos proyectos con dos navegadores:

- `kiosk-qr` → `e2e/fixtures/qr-video.y4m`
- `kiosk-qr-degraded` → `e2e/fixtures/qr-video-degraded.y4m` (solo `degraded.spec.ts`)

Los videos se generan antes de arrancar el servidor; ver `e2e/fixtures/README.md`.

## Se prueba el BUILD, no `vite dev`

`playwright.config.ts` construye con `vite build --mode test` y levanta `vite preview` sobre
ese `dist/`: mismos trozos, misma **carga diferida** del decodificador y el mismo minificado
que se instala en la tablet. Un E2E contra el servidor de desarrollo no habria detectado
nunca que ZXing llega por `import()`.

`--mode test`, y no el build por defecto (`npm run build`, sin `--mode`, el que se despliega
de verdad): es lo unico que distingue los dos. El gancho de pruebas del guardian de
actualizacion (`src/sw/testHooks.ts`, `window.__kronoqrTest`, RF-KI-07 tarea 3.12) se
elimina del bundle cuando `mode === 'production'` (`vite.config.ts` -> `define
__KRONOQR_TEST_HOOKS__`); sin ese modo, `update-window.spec.ts` no tendria como simular una
version pendiente. Verificar que el bundle de PRODUCCION no lo lleva es cosa de
`npm run build` + `scripts/check-bundle-budget.mjs`, no de este E2E.

## Con las cabeceras de seguridad de produccion

`vite preview` sirve las MISMAS cabeceras que Nginx (CSP incluida), leidas del snippet real
`infra/docker/nginx/snippets/security-headers.conf` por `support/securityHeaders.ts`
(`playwright.config.ts` -> `KRONOQR_PREVIEW_SECURITY_HEADERS` -> `vite.config.ts`
`preview.headers`). Sin esto, la 2.1.0 llego a produccion con el PIN roto (PIN-01: la CSP no
dejaba compilar el WebAssembly de libsodium) y ningun E2E lo vio. Si el snippet no existe o
no declara una CSP con `script-src`, la ejecucion se para al cargar la configuracion.

`pin-csp.spec.ts` sella el PIN con el WebAssembly real bajo esa CSP. Para demostrar que se
pone en rojo con otra CSP, se apunta `KRONOQR_SECURITY_HEADERS_SNIPPET` a una copia
modificada FUERA del repositorio; nunca se edita el snippet real para eso.
`KRONOQR_E2E_PORT` mueve el puerto (4173 por defecto) si ya hay otro `vite preview` en marcha.

## El backend no participa (todavia)

Las llamadas a `/api/v1/*` se interceptan con `page.route` en `support/kiosk.ts`. Lo que se
prueba aqui es la **pantalla** del quiosco: que decodifica, que confirma en menos de 300 ms
y que no bloquea a nadie cuando no hay servidor.

El **ciclo offline completo** —fichar sin red, verificar la cola en IndexedDB, reconectar y
comprobar que se consolida con el `occurred_at` original— es de la **tarea 1.9**, que es la
que construye la cola. Sera tambien el momento de apuntar el E2E contra el backend real,
inyectando un payload firmado por `KIOSK_E2E_QR_PAYLOAD`.

## Lo que ningun comando de aqui cierra

El Anexo A del doc 02 exige una **prueba de resistencia de 12 h en el dispositivo real**
antes de dar por buena la Fase 1: el escaneo continuo por camara durante turnos de ocho
horas es un caso de uso poco habitual y las fugas de memoria en el bucle de decodificacion
no aparecen en pruebas cortas. Necesita hardware y una persona; no lo sustituye ninguna
prueba automatica.
