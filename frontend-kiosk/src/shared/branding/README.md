# branding

Marca blanca en tiempo de ejecucion (RF-PD-08, tarea 5.8): nombre, color de acento, logotipo e
idiomas de la instalacion, pedidos a `GET /api/v1/branding` y aplicados sobre los tokens
`--kq-*` de `@kronoqr/web-kit/theme.css` sin recompilar nada (ADR-017, doc 06 §7).

La aritmetica de color, la validacion del contrato y la aplicacion al documento **no viven
aqui**: son de `@kronoqr/web-kit/branding` (`parseBranding`, `applyBranding`,
`PRODUCT_BRANDING`), compartidas por las tres SPA. Este modulo es solo el pegamento propio del
quiosco:

- **Cuando se aplica.** `applyCachedBranding()` la llama `main.ts`, sin red y antes de montar
  la aplicacion: lee `localStorage`, y si hay algo valido lo pinta ya. Sin esto, la primera
  pantalla parpadearia de "KronoQR" a la marca del cliente en cuanto llegara la respuesta del
  servidor.
- **Donde se guarda.** `localStorage['kronoqr.kiosk.branding']`, el JSON crudo del contrato
  (snake_case), validado con `parseBranding` tanto al leer como al escribir. Nunca
  `IndexedDB`: esto no es la cola de fichajes (regla dura 2 de "la cola es sagrada" no aplica
  aqui), son dos cadenas y un color que perder no tiene ninguna consecuencia legal.
- **El logotipo no esta en `localStorage`.** Sus bytes no caben ahi. Vive en la cache del
  _service worker_ (`vite.config.ts`, `sw/README.md`): la UNICA excepcion a "la API nunca se
  cachea", porque no es un fichaje.
- **Cuando se vuelve a pedir.** `useBranding({ api, connectivity })`, usado por `ScanView.vue`
  (que ya tiene los dos), pide la marca al crearse y otra vez cada vez que la conectividad
  pasa a "online". Nunca bloquea el escaneo (regla dura 19): todo en `try/catch`, sin `await`
  en el camino del escaneo, y un fallo se ignora en silencio — se queda la marca que ya habia,
  nunca una pantalla en blanco.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
