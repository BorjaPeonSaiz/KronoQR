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

## Dos proyectos, y por que

Chromium admite **un solo** fichero de video falso por proceso: `--use-file-for-fake-video-capture`
es un argumento de arranque, no algo que se cambie por pestana. Como hay que probar el QR
limpio y el degradado, hay dos proyectos con dos navegadores:

- `kiosk-qr` → `e2e/fixtures/qr-video.y4m`
- `kiosk-qr-degraded` → `e2e/fixtures/qr-video-degraded.y4m` (solo `degraded.spec.ts`)

Los videos se generan antes de arrancar el servidor; ver `e2e/fixtures/README.md`.

## Se prueba el BUILD, no `vite dev`

`playwright.config.ts` levanta `vite preview` sobre `dist/`, que es exactamente lo que se
instala en la tablet: los mismos trozos y la misma **carga diferida** del decodificador. Un
E2E contra el servidor de desarrollo no habria detectado nunca que ZXing llega por
`import()`.

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
