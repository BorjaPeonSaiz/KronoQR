#!/usr/bin/env node
//
// KronoQR — marcador de la suite E2E del quiosco.
//
// `make e2e` invoca `npm run test:e2e` en cuanto existe frontend-kiosk/package.json,
// y desde la tarea 0.5 ya existe. La suite de Playwright con camara simulada
// (doc 02 §9.4) necesita dos cosas que aun no estan:
//
//   - `e2e/fixtures/qr-video.y4m`, que se genera a partir de una credencial real
//     de prueba en la tarea 1.5;
//   - las pantallas de escaneo, que llegan en las tareas 1.8 y 1.9.
//
// Este guion existe para que `make e2e` diga eso en lugar de fallar con
// "Missing script: test:e2e". Termina en 0 porque no hay nada roto; lo que hay
// es trabajo pendiente, y lo dice.
//
// Codigos de salida:
//   0  siempre

process.stdout.write(
  [
    '[test:e2e] La suite E2E del quiosco todavia no existe.',
    '[test:e2e] Le faltan dos piezas:',
    '[test:e2e]   1) e2e/fixtures/qr-video.y4m — se genera en la tarea 1.5, con una credencial real.',
    '[test:e2e]   2) Las pantallas de escaneo y la cola offline — tareas 1.8 y 1.9.',
    '[test:e2e] Playwright y su configuracion entran con ellas. NO se ha ejecutado ninguna prueba.',
    '',
  ].join('\n'),
)
