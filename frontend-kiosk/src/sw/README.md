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

`globPatterns: ['**/*.{js,css,html,svg,png}', '**/*-latin-*.woff2']` (las fuentes, solo los
subconjuntos latin y latin-ext), con `maximumFileSizeToCacheInBytes` subido a 6 MiB. El techo por defecto de Workbox (2 MiB) dejaria fuera el trozo del decodificador de
ZXing, y el quiosco arrancaria **sin poder escanear** precisamente cuando no hay red, que es
cuando el precacheo importa.

`navigateFallbackDenylist` excluye `/api/`: un fichaje servido desde la cache seria un
registro legal inventado.

## La UNICA excepcion: la marca (RF-PD-08, tarea 5.8)

`workbox.runtimeCaching` cachea `GET /api/v1/branding` (`NetworkFirst`, techo de 3 s) y
`GET /api/v1/branding/logo` (`CacheFirst`, hasta 4 logotipos y 30 dias). No es un fichaje: es
el nombre, el color y el logotipo del cliente. Sin esto, un quiosco sin red arrancaria con la
marca del producto en vez de la del hotel (RF-KI-03) hasta recuperar conexion, lo que en un
turno de 8 horas sin wifi es "siempre".

`CacheFirst` en el logotipo es seguro porque la URL lleva la huella del contenido en `?v=`
(`GET /api/v1/branding` la publica): un logotipo nuevo es una URL nueva, la vieja puede quedar
cacheada para siempre sin que nadie la vea.

El nombre y el color de acento NO dependen de esta cache: `shared/branding/useBranding.ts` los
guarda en `localStorage` (`kronoqr.kiosk.branding`) y los reaplica de inmediato al arrancar,
antes de que el service worker o la red hayan contestado nada. Lo unico que SI depende del
service worker es el logotipo, porque sus bytes no caben en `localStorage`.

## Ventana de actualizacion (RF-KI-07, tarea 3.12)

La franja YA NO esta en el codigo: la declara el centro (`KIOSK_UPDATE_WINDOW`,
`KIOSK_UPDATE_QUIET_MINUTES` en `installation_settings`) y viaja en cada latido
(`KioskHeartbeat.update_window`), cacheada en `shared/telemetry/deviceIdentity.ts` para que
la puerta funcione sin red. `features/offline/domain/updateWindow.ts` -> `canApplyUpdate` es
verdadera solo si la cola esta vacia, no hubo ningun escaneo en los ultimos `quiet_minutes`
minutos, y la hora LOCAL de la tablet cae dentro de la ventana (que puede cruzar la
medianoche). Sin configuracion recibida todavia, la ventana de serie es `03:00-05:00` con 10
minutos de silencio.

Como una tablet de quiosco no vuelve a navegar en dias, `registerServiceWorker()` no confia en
la deteccion por defecto del navegador: comprueba si hay version nueva cada hora
(`registration.update()`) y, en cuanto hay una pendiente, reevalua la puerta cada minuto y
aplica en el instante en que la deja pasar. Si la ventana se cierra antes de que la cola se
vacie, simplemente espera al minuto siguiente.

La pantalla de diagnostico (RF-KI-08) enseña, junto a la version, si hay una actualizacion
pendiente y en que ventana se aplicara.

**Recargar la pagina no aplica la version nueva** (`registerType: 'prompt'`): el SW nuevo
espera hasta que la puerta lo aplica, o hasta que no queda ninguna pestaña del origen.

## Actualizacion urgente (RF-KI-07, 2.2.1)

Desde la 2.2.1 el latido trae `minimum_app_version` (nucleo X.Y.Z de la version del
servidor). `features/offline/domain/minimumVersion.ts` la compara por nucleo con
`APP_VERSION` (`2.2.1-dev` frente a `2.2.1` no es desfasada) y, si la tablet va por debajo,
entra en **modo urgente** (`shared/telemetry/urgentUpdate.ts`):

- `registration.update()` en el acto, al arrancar y en cada latido, como mucho cada 5 min;
- la puerta (`canApplyUpdate(..., urgent)`) relaja **solo** la ventana horaria. Siguen
  siendo obligatorias la cola vacia, la cola **duradera** (con la cola en memoria nunca se
  recarga, ADR-047) y ningun escaneo en `quiet_minutes` (regla dura 19);
- cada recarga urgente se anota antes de recargar (`localStorage`,
  `kronoqr.kiosk.urgent_update_attempts`). Tras **3** sin cambio de version, se rinde: vuelve a
  la cadencia normal y reporta `kiosk.update.unreachable` una vez por arranque.

Una tablet con la 2.2.0 o anterior no tiene este codigo: se pone al dia en su ventana, o una
2.1.0 (declara `0.0.0`) desregistrando el SW (`chrome://serviceworker-internals` o F12 →
Application → Service workers → Unregister) **solo** con red, la cola a 0 y sin cola en
memoria, y **nunca** borrando los datos del sitio (IndexedDB guarda la cola y `localStorage`
el emparejamiento). Procedimiento completo: `docs/cliente/operacion.md` §11.1, «Que hacer si
una tablet no cambia sola tras actualizar el servidor».

## `testHooks.ts`

Gancho de pruebas acotado para el guardian de actualizacion: fuerza el estado «hay version
pendiente» y sustituye la recarga final por un marcador observable desde Playwright, dejando
que decidan la MISMA puerta y el MISMO temporizador. Solo existe si la compilacion lo activa
(`__KRONOQR_TEST_HOOKS__`, `define` de `vite.config.ts`) y la pagina pone
`window.__KRONOQR_ENABLE_TEST_HOOKS__`.

Cuando esto exija un service worker propio, se pasa a `strategies: 'injectManifest'` y el
fuente vive en esta carpeta.
