# Pruebas E2E del quiosco

Playwright con camara simulada (doc 02 §9.4 y §2). `make e2e` ya invoca `npm run test:e2e`
en esta aplicacion.

La suite todavia no existe y el guion lo dice al ejecutarse en lugar de fallar con un
"Missing script". Le faltan dos piezas:

1. `e2e/fixtures/qr-video.y4m`, que necesita una credencial real (**tarea 1.5**).
2. Las pantallas de escaneo y la cola offline (**tareas 1.8 y 1.9**).

Escenarios que tendran que vivir aqui, del §9.4:

- **Ciclo offline completo**: fichar sin red, verificar la cola en IndexedDB, reconectar y
  comprobar que se consolida con el `occurred_at` original.
- **Camara simulada** con un QR real, y **QR degradado** parcialmente ocluido.
- **Accesibilidad** con `@axe-core/playwright`: 0 violaciones criticas o graves.
