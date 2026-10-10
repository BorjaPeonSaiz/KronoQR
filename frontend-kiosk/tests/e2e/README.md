# Pruebas E2E del quiosco

Playwright con camara simulada (doc 02 §9.4 y §2). `make e2e` invoca `npm run test:e2e` en
esta aplicacion.

```bash
npm run test:e2e                       # todos los proyectos
npx playwright test --project=kiosk-qr # solo el QR limpio
npx playwright test --grep @RF-KI-09   # por etiqueta de requisito (§9.6)
```

## Que hay aqui

| Fichero                    | Cubre                                                                                                                                              |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `scan.spec.ts`             | `@RF-KI-01`, `@RF-KI-02`, `@RF-KI-05`, `@RF-KI-06`, `@RF-KI-09`, `@RF-AT-01`, `@RF-AT-05`, `@RL-09`, `@RQ-04` — escaneo y confirmacion             |
| `degraded.spec.ts`         | `@RF-KI-02`, `@RF-QR-05`, `@RF-AT-05`, `@RQ-04` — tarjeta deteriorada                                                                              |
| `worn.spec.ts`             | `@RF-QR-05`, `@RQ-04` — tarjeta gastada, al limite de la correccion de errores                                                                     |
| `qr-decode-budget.spec.ts` | `@RF-QR-05`, `@RNF-P-03` — presupuesto de tiempo de la decodificacion                                                                              |
| `layout.spec.ts`           | `@RF-KI-06` — disposicion de la pantalla, con la camara sin QR delante                                                                             |
| `accessibility.spec.ts`    | `@RF-KI-05`, `@RF-KI-06`, `@RF-AT-05`, `@RF-AT-10`, `@RF-AT-12`, `@RF-PD-08`, `@RS-03` con `@axe-core/playwright`, 0 violaciones criticas o graves |
| `offline.spec.ts`          | `@RF-KI-03`, `@RF-KI-04`, `@RF-AT-07`, `@RQ-05`, `@RN-18`, `@RN-21`, `@RN-22` — cola offline, orden y descartes                                    |
| `break.spec.ts`            | `@RF-AT-12`, `@RF-AT-10` — fichaje de pausa y aviso de desfase                                                                                     |
| `pin.spec.ts`              | `@RF-AT-11`, `@RF-ID-09`, `@RS-03`, `@RQ-05` — fichaje por PIN de 6 a 8 cifras                                                                     |
| `pin-lockout.spec.ts`      | `@RF-AT-11`, `@RS-12`, `@RS-03` — bloqueo del PIN                                                                                                  |
| `pin-csp.spec.ts`          | `@RF-AT-11`, `@RS-09` — PIN sellado con WebAssembly bajo la CSP de Nginx                                                                           |
| `pairing.spec.ts`          | `@RF-PD-06` — emparejamiento                                                                                                                       |
| `token-rotation.spec.ts`   | `@RF-ID-04`, `@RF-KI-03`, `@RQ-05` — relevo del token en el latido                                                                                 |
| `heartbeat-errors.spec.ts` | `@RF-PD-15`, `@RF-ID-04`, `@RF-KI-03`, `@RQ-05` — errores enviados en el latido                                                                    |
| `traceparent.spec.ts`      | `@RF-PD-15`, `@RF-KI-02` — cabecera `traceparent`                                                                                                  |
| `branding.spec.ts`         | `@RF-PD-08`, `@RF-KI-03`, `@RF-KI-09`, `@RL-09` — marca y aviso de privacidad, tambien sin red                                                     |
| `manifest.spec.ts`         | `@RF-KI-01`, `@RF-KI-08` — manifiesto de la PWA                                                                                                    |
| `diagnostics.spec.ts`      | `@RF-KI-08`, `@RF-PD-06` — pantalla de diagnostico                                                                                                 |
| `update-window.spec.ts`    | `@RF-KI-07`, `@RF-KI-04`, `@RQ-05`, `@RF-KI-08` — ventana de actualizacion                                                                         |
| `kiosk-upgrade.spec.ts`    | `@RF-AT-11`, `@RF-KI-07`, `@RF-PD-10`, `@RS-09` — tablet abierta con la CSP de la 2.1.0 aplica la version nueva y ficha por PIN                    |

## Varios proyectos, y por que

Chromium admite **un solo** fichero de video falso por proceso: `--use-file-for-fake-video-capture`
es un argumento de arranque, no algo que se cambie por pestana. Cada video es un proyecto con su
propio navegador:

- `kiosk-qr` → `e2e/fixtures/qr-video.y4m` (todo salvo los tres siguientes)
- `kiosk-qr-degraded` → `e2e/fixtures/qr-video-degraded.y4m` (solo `degraded.spec.ts`)
- `kiosk-qr-worn` → `e2e/fixtures/qr-video-worn.y4m` (solo `worn.spec.ts`)
- `kiosk-layout` → `e2e/fixtures/qr-video-blank.y4m` (solo `layout.spec.ts`)
- `kiosk-upgrade` → `e2e/fixtures/qr-video-blank.y4m` (solo `kiosk-upgrade.spec.ts`; ver abajo)

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

## Actualizacion con la tablet abierta

`kiosk-upgrade.spec.ts` (tarea 3.1 de la 2.2.1) no usa `vite preview` ni el gancho de
`src/sw/testHooks.ts`: construye DOS builds de produccion (`7.0.0` y `7.0.1`, `KRONOQR_BASE=/kiosk/`,
unos segundos cada uno) en un directorio temporal y los sirve con `support/kioskReleases.ts`,
un servidor minimo que reproduce la `location ^~ /kiosk/` de Nginx en un puerto libre. La
tablet arranca con la 7.0.0 bajo la CSP de la 2.1.0 (la del snippet SIN `'wasm-unsafe-eval'`,
derivada, no copiada), queda controlada por su service worker y, a mitad de prueba, el mismo
origen pasa a servir la 7.0.1 con la CSP del snippet y el latido declara
`minimum_app_version: 7.0.1`. Con el reloj de la pagina a las 11:00 (fuera de la ventana de
serie), solo el modo urgente puede aplicarla; la prueba exige que la tablet declare la 7.0.1,
que su documento llegue con la CSP nueva y que el PIN se selle con el WebAssembly real. Con
la puerta anterior a la 2.2.1, o con la version nueva servida aun con la CSP vieja, se pone
en rojo (comprobado rompiendolo a proposito).

## El backend no participa

Las llamadas a `/api/v1/*` se interceptan con `page.route` en `support/kiosk.ts` (y los dobles
de la cola, del emparejamiento y del PIN en el resto de `support/`). Lo que se prueba aqui es la
**pantalla** del quiosco: que decodifica, que confirma en menos de 300 ms y que no bloquea a
nadie cuando no hay servidor. El ciclo offline completo —fichar sin red, verificar la cola en
IndexedDB, reconectar y comprobar que se consolida con el `occurred_at` original— es
`offline.spec.ts` (RQ-05).

## Donde se ejecuta

En la CI (etapa ⑦ de `.github/workflows/ci.yml`) o en el host. No en el contenedor
`node-kiosk`: es Alpine (musl) y el Chromium de Playwright no arranca ahi.

## Lo que ningun comando de aqui cierra

El Anexo A del doc 02 exige una **prueba de resistencia de 12 h en el dispositivo real**
antes de dar por buena la Fase 1: el escaneo continuo por camara durante turnos de ocho
horas es un caso de uso poco habitual y las fugas de memoria en el bucle de decodificacion
no aparecen en pruebas cortas. Necesita hardware y una persona; no lo sustituye ninguna
prueba automatica.
