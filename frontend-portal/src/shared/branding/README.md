# branding

Marca blanca en tiempo de ejecucion (RF-PD-08, tarea 5.8, regla dura 13). Nada de esto vive en el codigo de un cliente concreto: el nombre, el color de acento y el logotipo llegan de `GET /api/v1/branding`, el unico endpoint publico ademas del acceso.

- `branding.store.ts` — `useBrandingStore`, una envoltura de dos lineas: `defineStore('portal-branding', () => createBrandingState({ mode: 'light' }))`. Toda la logica —empezar en `PRODUCT_BRANDING`, pintar de inmediato sin esperar a la red, pedir `GET /api/v1/branding` sin bloquear la primera pantalla, sustituir la marca solo si `parseBranding` la valida y nunca lanzar— vive en `@kronoqr/web-kit/brandingState` (ADR-036): el panel y el portal la escribian cada uno la suya y ya habian divergido (uno aplicaba solo si la respuesta era valida, el otro siempre), asi que ahora es una sola pieza compartida. Este fichero no reinventa nada de eso.

**Ya no hay `branding.api.ts` ni un `BrandMark.vue` local.** El logotipo o el nombre se pintan con `@kronoqr/web-kit/components/BrandMark.vue` (prop `branding: Branding`, `size?: 'sm'|'lg'`, `tone?: 'light'|'kiosk'`, `data-testid="brand-mark"`), usado en `AppShellView.vue` y `LoginView.vue` pasandole `:branding="branding.current"` del store. Con logotipo, el nombre en texto no se repite (un solo significado para quien usa lector de pantalla): la imagen lleva su `alt` y tope de alto/ancho con `object-contain`; sin logotipo, el nombre se lee como texto con `truncate` + `title` (hasta 60 caracteres del contrato no caben en una linea en un movil de 320-375 px sin recortarse).

`main.ts` conecta `apply()` y `load()` al arrancar y, si la marca trae un `locales.default` distinto y el navegador no pedia ningun idioma soportado, lo usa como idioma inicial de una visita anonima -nunca por encima de una preferencia real del navegador ni del idioma de quien ya ha entrado (`employees.locale`, ver `shared/i18n`).

**En las pruebas**, como `BrandMark.vue` recibe la marca por prop y no lee ninguna tienda, hace falta cargar el store antes de montar la pantalla: `tests/unit/AppShellView.spec.ts` y `LoginView.spec.ts` traen un helper `loadBranding(pinia, overrides)` que simula `GET /api/v1/branding` con `stubFetch` (el mismo doble de `fetch` global del resto de la suite) y llama a `useBrandingStore(pinia).load()`. El componente de web-kit no tiene prueba propia en el paquete: la de `LoginView.spec.ts` con un nombre de 60 caracteres es la que lo ejercita.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
