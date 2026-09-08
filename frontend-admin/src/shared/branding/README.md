# branding

Estado de la marca de la instalacion (RF-PD-08, tarea 5.8; ADR-036): nombre,
color de acento, logotipo e idiomas, tal como los entrega
`GET /api/v1/branding` (publica, sin sesion).

- **`branding.store.ts`** — dos lineas sobre `createBrandingState` de
  `@kronoqr/web-kit/brandingState` (`mode: 'light'`), envueltas en un store de
  Pinia. `createBrandingState` es donde vive la logica de verdad, compartida
  con el portal del empleado: arranca en `PRODUCT_BRANDING`
  (`@kronoqr/web-kit/branding`), `apply()` pinta `current` sobre los tokens
  `--kq-*` del panel, y `load()` pide la marca al servidor, la valida con
  `parseBranding` y la aplica — **siempre**, tambien si la peticion falla (sin
  red, `429`, una respuesta que no pasa la validacion): el titulo de la
  pestaña y el favicon del producto tienen que aparecer aunque el servidor no
  conteste. Antes esta logica vivia por duplicado en el panel y en el
  portal, y habia divergido (uno aplicaba solo si la respuesta era valida, el
  otro siempre); ahora hay una sola fuente y este fichero solo la conecta a
  Pinia.

`main.ts` llama `apply()` y `load()` al arrancar, sin esperar a la segunda:
la primera pintura no se bloquea por una peticion de red.

`shared/ui/AppShellView.vue` (`size="sm"`) y `features/auth/LoginView.vue`
(`size="lg"`) pintan `current` con `BrandMark` de
`@kronoqr/web-kit/components/BrandMark.vue`: el mismo componente que usa el
portal, con el logotipo topado en ancho y alto (`object-contain`) y el
nombre truncado con `title` cuando no cabe (el contrato permite hasta 60
caracteres). `features/settings/BrandingView.vue` es la unica pantalla que
cambia la marca (ambito `settings:*`), y llama a `load()` tras guardar para
que la cabecera se actualice en el acto, sin esperar a una recarga.

Vive en `shared/` y no en `features/settings/` porque lo consumen pantallas
de fuera de esa _feature_ (`AppShellView`, `LoginView`): es el mismo criterio
que ya separa `shared/ui` de `features/settings` en este repositorio.
