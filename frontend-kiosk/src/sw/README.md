# Service worker (Workbox)

El quiosco es la **unica** de las tres aplicaciones que tiene service worker: necesita
instalarse y funcionar sin red (doc 02 §3.3). El panel y el portal son web normal, y el
portal ademas **no es una PWA** por decision explicita (ADR-015).

Hoy el service worker lo genera `vite-plugin-pwa` en modo `generateSW` con `registerType:
'prompt'`. La eleccion de `prompt` frente a `autoUpdate` no es un detalle: una actualizacion
que se aplica sola puede recargar la tablet en mitad del cambio de turno de las 06:00.

Lo que llegara aqui:

- Precacheo del _app shell_ y estrategias por tipo de recurso (tarea 1.8).
- **Ventana controlada de actualizacion** (RF-KI-07, tarea 3.12): la actualizacion se
  descarga cuando toca, pero no se aplica dentro de la franja de cambio de turno.

Cuando eso exija un service worker propio, se pasa a `strategies: "injectManifest"` y el
fuente vive en esta carpeta.
