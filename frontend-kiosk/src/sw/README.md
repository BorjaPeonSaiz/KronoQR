# Service worker (Workbox)

El quiosco es la **unica** de las tres aplicaciones que tiene service worker: necesita
instalarse y funcionar sin red (doc 02 §3.3). El panel y el portal son web normal, y el
portal ademas **no es una PWA** por decision explicita (ADR-015).

Lo genera `vite-plugin-pwa` en modo `generateSW`. El registro lo hace
`registerServiceWorker.ts`, y no el guion que el plugin inyecta, para que la decision de
**cuando** aplicar una version nueva viva en codigo nuestro.

## Nada se actualiza solo

`registerType: 'prompt'` frente a `autoUpdate` no es un detalle de configuracion: una
actualizacion que se aplica sola **recarga la pagina**, y si eso pasa a las 06:00 con quince
personas en la cola, el quiosco esta muerto justo en el minuto que existe para cubrir.

`onNeedRefresh` se limita a anotar que hay version nueva. `applyUpdate()` es lo unico que la
aplica, y lo llama quien decide el cuando.

## Que precachea

`globPatterns: **/*.{js,css,html,svg,woff2}`, con `maximumFileSizeToCacheInBytes` subido a
6 MiB. El techo por defecto de Workbox (2 MiB) dejaria fuera el trozo del decodificador de
ZXing, y el quiosco arrancaria **sin poder escanear** precisamente cuando no hay red, que es
cuando el precacheo importa.

`navigateFallbackDenylist` excluye `/api/`: un fichaje servido desde la cache seria un
registro legal inventado.

## Lo que falta

**Ventana configurable de actualizacion** (RF-KI-07, tarea 3.12): la version nueva se
descarga cuando toca, pero no se aplica dentro de la franja de cambio de turno. La mitad que
no se podia dejar para despues —que no se aplique nada sin que alguien lo decida— ya esta.

Cuando eso exija un service worker propio, se pasa a `strategies: 'injectManifest'` y el
fuente vive en esta carpeta.
