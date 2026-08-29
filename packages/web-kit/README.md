# `@kronoqr/web-kit`

Paquete interno compartido por las SPA de KronoQR (`frontend-admin`, `frontend-portal` y, en lo que aplique, `frontend-kiosk`), creado por **ADR-036** tras encontrar que `frontend-portal` habia copiado ~1450 lineas de `frontend-admin` — incluido el calculo de totales de jornada, con divergencia real ya detectada — en vez de reutilizarlas.

Lee `docs/adr/ADR-036-las-spa-comparten-un-paquete-de-calculo-y-presentacion.md` antes de añadir nada aqui: define que va y que no va en este paquete.

## Que hay dentro

| Modulo                                  | Contenido                                                                                                                                                                                                                                    | Por que es compartido                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `src/http.ts`                           | `ApiError`, `ApiErrorKind`, `request`/`requestJson`/`requestBlob`, `setAuthTokenProvider`/`setUnauthenticatedHandler`, `apiBaseUrl`                                                                                                          | Manejo de errores HTTP, cabeceras y autenticacion identicos en cualquier SPA. **No** incluye ningun endpoint concreto: eso vive en cada aplicacion (`credentials.api.ts`, `employees.api.ts`, etc.)                                                                                                                                                                                                                                                    |
| `src/datetime.ts`                       | Formateo de instantes UTC en la zona del centro, lectura de `LocalTimestamp` sin reconvertir                                                                                                                                                 | Regla dura 3. `readLocalTimestamp`/`formatLocalTime` son la pieza que el portal no reutilizaba y por eso divergia de la hora que pintaba el panel en un cambio de horario                                                                                                                                                                                                                                                                              |
| `src/announcer.ts`                      | Region viva WCAG 2.2 AA (4.1.3)                                                                                                                                                                                                              | Mismo patron de accesibilidad en las tres SPA                                                                                                                                                                                                                                                                                                                                                                                                          |
| `src/downloadDocument.ts`               | Descarga y suelta inmediata de un blob                                                                                                                                                                                                       | Un PDF de credencial o un CSV de historico son datos sensibles: ninguna SPA los debe retener                                                                                                                                                                                                                                                                                                                                                           |
| `src/dateRange.ts`                      | Validacion de un rango de jornadas (`DateRange`, `MAX_RANGE_DAYS`)                                                                                                                                                                           | El panel y el portal piden el mismo tipo de rango a endpoints distintos con el mismo techo de contrato (366 dias)                                                                                                                                                                                                                                                                                                                                      |
| `src/workdayTotals.ts`                  | Aritmetica de la jornada: suma de tramos, contraste con el total declarado                                                                                                                                                                   | **La pieza critica.** Es la que diverguio de verdad entre panel y portal antes de esta migracion. No puede haber una segunda copia                                                                                                                                                                                                                                                                                                                     |
| `src/theme.css` + `src/fonts.css`       | Sistema visual compartido: tokens `--kq-*` en `:root`, bloque `@theme inline` de Tailwind 4 y tipografias autoalojadas (`@fontsource`)                                                                                                       | Un solo sitio para colores, fuentes, radios y sombras de las tres SPA (`docs/06-guia-visual.md`). Marca por defecto del fabricante, sobreescribible en tiempo de ejecucion por la tarea 5.8 (regla dura 13)                                                                                                                                                                                                                                            |
| `src/base.css`                          | Capa base de HTML puro que usa esos tokens: fondo y color de `body`, tipografia de encabezados, `:focus-visible` y `prefers-reduced-motion`. Importado por `theme.css`, asi que una SPA solo necesita `@import '@kronoqr/web-kit/theme.css'` | Era identica, caracter por caracter, en `main.css` del panel y del portal, y el quiosco repetia ademas su propio `:focus-visible`. Los tokens `--kq-page-*` (`bg`, `text`, `focus`, `focus-offset`) son la indireccion que deja al quiosco (fondo oscuro) reusar la regla sin declararla otra vez: por defecto apuntan a los tokens claros, y `frontend-kiosk/src/assets/main.css` los redefine a los tokens `kiosk-*` despues de importar `theme.css` |
| `src/contrast.ts` + `src/themePairs.ts` | Formula de contraste WCAG 2.2 y tabla de parejas texto/fondo con su exigencia                                                                                                                                                                | `tests/unit/theme.spec.ts` mide cada pareja contra `theme.css`; la tarea 5.8 reutiliza ambos para avisar cuando los colores de un cliente no alcanzan el minimo                                                                                                                                                                                                                                                                                        |
| `src/components/*.vue`                  | `EmptyState`, `ErrorNotice`, `FormField`, `LoadingPanel`, `NotFoundView`                                                                                                                                                                     | Los cinco componentes de interfaz genericos que `frontend-portal` habia copiado de `frontend-admin`                                                                                                                                                                                                                                                                                                                                                    |
| `src/qr/renderQrPath.ts`                | Codifica un texto como QR y lo devuelve como trazado SVG (`QrPath { size, path }`), con `@zxing/library` en import dinamico                                                                                                                  | Movido de `frontend-kiosk` (aviso de privacidad, RF-KI-09) cuando `frontend-admin` necesito lo mismo para el QR del `otpauth://` del alta de segundo factor (RS-06, tarea 2.1): el codificador y el patron "SVG por `<path>`, nunca `v-html`" (CSP §7.2) eran identicos                                                                                                                                                                                |

**Que NO esta aqui, a proposito** (ADR-036): nada especifico de una sola pantalla o de un flujo de una sola aplicacion. Los endpoints concretos (`/api/v1/employees`, `/api/v1/credentials/...`, `/api/v1/me/...`), el catalogo de motivos de correccion, `ChangePreview`/`ConfirmDialog`/`BaseDialog` (el patron de confirmacion "que cambia, desde que, hacia que") y `ForbiddenView` siguen siendo de cada SPA. Este paquete crece solo cuando una **segunda** aplicacion necesita de verdad lo que la primera ya construyo, nunca de forma especulativa.

## Decisiones de diseño (para quien migre `frontend-portal` a continuacion)

### Sin paso de build propio

El paquete se consume **directamente en TypeScript fuente**, sin compilar a `dist/`. `package.json` declara:

```json
"exports": {
  "./package.json": "./package.json",
  "./components/*": "./src/components/*",
  "./*": "./src/*.ts"
}
```

Cada SPA importa un modulo por su ruta dentro de `src/`, por ejemplo:

```ts
import { requestJson } from '@kronoqr/web-kit/http'
import { workDayTotals } from '@kronoqr/web-kit/workdayTotals'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
```

Hacen falta dos patrones y no uno: en el mapa de `exports`, un patron `*` sustituye literalmente la parte que casa, sin añadir extension. Los modulos `.ts` se piden sin extension (`@kronoqr/web-kit/http`), asi que su patron la añade (`./src/*.ts`); los componentes se piden con `.vue` ya puesto en el propio import (`@kronoqr/web-kit/components/EmptyState.vue`), asi que su patron solo cambia de carpeta. Node y TypeScript (resolucion `bundler`) eligen el patron mas especifico —el de mayor prefijo literal— asi que `./components/*` gana sobre `./*` para cualquier ruta que empiece por `components/`, sin depender del orden en el que esten escritos.

Vite resuelve paquetes de workspace enlazados por `npm workspaces` como codigo fuente (no los pre-empaqueta con `esbuild` en `optimizeDeps` porque detecta que estan enlazados fuera de un `node_modules` real), asi que TypeScript se transpila igual que el resto de la aplicacion, sin build intermedio que mantener sincronizado. Se eligio esto en vez de compilar a `dist/` porque las tres SPA ya usan Vite y añadir un paso de build al paquete solo aportaria friccion (recordar reconstruirlo, otro `tsconfig` de emision, otro `watch`) sin ningun beneficio: el paquete nunca se publica ni se consume fuera de este monorepo (ADR-036, alternativa de registro npm privado descartada explicitamente).

Se importa **por subruta, nunca desde la raiz del paquete** (`@kronoqr/web-kit` a secas no exporta nada): eso es lo que mantiene el paquete _tree-shakeable_ de forma explicita — cada SPA declara exactamente que modulos usa, no "todo el kit".

### Tipos: estructurales, no importados del `schema.d.ts` de ninguna SPA

Cada SPA genera su propio cliente tipado desde `docs/api/openapi.yaml` (`npm run api:generate`, ADR-013) en su propio `src/shared/api/schema.d.ts`. Este paquete **no importa esos tipos generados** — haria que `@kronoqr/web-kit` dependiera de que aplicacion lo usa, exactamente al reves de lo que se busca.

En su lugar, cada modulo declara la forma minima estructural que necesita:

- `workdayTotals.ts` declara `ShiftEntryDuration { duration_minutes: number | null }` y `WorkDayDurations { shift_entries; total_minutes }`. El `WorkDayDetail`/`WorkDayShiftEntry` que genera cada SPA cumple esa forma de sobra (tiene mas campos), y TypeScript lo acepta sin conversion porque la comprobacion de propiedades excedentes solo aplica a literales de objeto, no a variables ya tipadas.
- `dateRange.ts` declara su propio `DateRange { from: string; to: string }`. Cada SPA puede seguir teniendo su `WorkDateRange` local junto a la llamada al endpoint (es la forma que espera ESE endpoint) sin que haga falta unificarlas: son estructuralmente compatibles.
- `http.ts` declara sus propios `Problem`/`ValidationProblem` (forma RFC 9457 del contrato), en vez de importarlos del `schema.d.ts` de una SPA.

Si `frontend-portal` necesita pasar su propio `WorkDayDetail` (con los campos que trae `GET /me/workdays`) a `workDayTotals()`, deberia funcionar sin ningun cambio en este paquete, siempre que la forma de esos tramos incluya `duration_minutes`.

### `requestBlob` y `BinaryDocument`

`BinaryDocument` incluye `printedCount` (de la cabecera `X-Kronoqr-Printed-Count`) y `headers` (las cabeceras completas de la respuesta) de forma generica: cualquier SPA puede leer una cabecera de recuento propia (como hace `frontend-admin/src/features/reports/legalExport.api.ts` con `X-Kronoqr-Export-Shift-Rows`) sin que el paquete tenga que conocer el nombre de esa cabecera.

`requestBlob` devuelve `Promise<BinaryDocument | null>`: `null` en un `204`. Hoy eso solo ocurre en la impresion por lotes de credenciales (ADR-034, "no habia nada pendiente" no es un error). El endpoint de exportacion del portal (`GET /me/export`) no responde `204` en el contrato actual, asi que quien migre el portal puede desenvolver el resultado con un `if (documento === null) throw ...` igual que hace `legalExport.api.ts`, o confirmar contra el contrato que ese endpoint nunca lo hace y narrow-ear en consecuencia.

`requestBlob` **no** trae un `accept` por omision especifico de un formato (ni PDF ni CSV): cada llamada declara el que necesita (`accept: 'application/pdf, application/problem+json'`, `accept: 'text/csv, application/problem+json'`). Antes, `frontend-admin` tenia un valor por omision de PDF "porque las credenciales son lo que mas se descarga"; se retiro al mover el cliente aqui porque ese valor por omision era, precisamente, algo especifico de una aplicacion coandose en la base compartida.

### Componentes de interfaz: mismo comportamiento, sin bifurcar por SPA

Los cinco componentes movidos tenian pequeñas diferencias entre `frontend-admin` y la copia de `frontend-portal`. Se resolvieron asi, no fusionando "el que sea mas simple":

- **`EmptyState`**: se conserva el slot de accion que tenia el panel (el portal no lo usaba, y seguir sin usarlo no cambia nada).
- **`ErrorNotice`**: se conserva la lista de errores por campo que tenia el panel. El portal no la pintaba porque no la necesitaba, no porque el componente no debiera tenerla.
- **`FormField`**: el portal usaba una etiqueta mas grande (`text-lg`) por legibilidad en movil. Se añadio la prop `labelClass` con el valor que ya tenia el panel (`font-medium text-slate-900`) como valor por omision, para que migrar `frontend-admin` no cambie nada visualmente; quien migre el portal debe pasar `label-class="text-lg font-medium text-slate-900"` (o el valor que decida) para no perder esa legibilidad.
- **`NotFoundView`**: es el unico de los cinco que **no** se pudo mover tal cual, porque llevaba escrita la ruta de vuelta (`{ name: 'employees' }` en el panel, `{ name: 'my-records' }` en el portal) y el texto del enlace (`notFound.backToEmployees` / `notFound.backToRecords`). Ahora recibe `backToRouteName` y `backToLabelKey` por `props` de la ruta:

  ```ts
  { path: ':pathMatch(.*)*', name: 'not-found', component: NotFoundView,
    props: { backToRouteName: 'employees', backToLabelKey: 'notFound.backToEmployees' } }
  ```

  El titulo y la descripcion (`notFound.heading`, `notFound.description`) siguen siendo las mismas claves de i18n en las dos SPA, porque ya decian lo mismo antes de compartir el componente.

### Lo que sigue siendo de cada SPA

- Las claves de i18n que usan estos componentes (`errors.*`, `notFound.*`) las declara cada aplicacion en su propio `locales/{es,en}.json`. El paquete no trae su propio `i18n`: si mañana se decide centralizar tambien el catalogo de motivos de correccion (Anexo C, apuntado como candidato en ADR-036), sera un modulo nuevo aqui, no una dependencia de `vue-i18n` con mensajes empotrados.
- `vue`, `vue-i18n` y `vue-router` son `peerDependencies`: el paquete nunca trae su propia copia de Vue. Cada SPA los resuelve con la version que ya tiene instalada.

## Pruebas

`npm run test:unit` (dentro de `packages/web-kit`) ejercita `http.ts`, `datetime.ts`, `announcer.ts`, `downloadDocument.ts`, `dateRange.ts`, `workdayTotals.ts`, `contrast.ts` y, a traves de `theme.spec.ts`, el propio `theme.css` (contraste de cada pareja, exposicion de cada token a Tailwind, fuentes sin CDN). `base.spec.ts` comprueba `base.css` (sin hexadecimales sueltos, solo tokens `--kq-*`) y lee el `main.css` de las tres SPA para verificar que ninguna repite la capa base ni declara `:focus-visible` con un color literal. No hay pruebas de componente para los cinco `.vue`: los ejercitan las vistas de cada SPA que los consume, igual que pasaba dentro de `frontend-admin` antes de esta migracion. La cobertura de `vitest.config.ts` solo mide `src/**/*.ts` por el mismo motivo.

## Candidatos futuros (no migrados en esta tarea)

- **`frontend-kiosk`**: no se ha revisado si algo de su codigo es candidato a este paquete. El escaneo QR y la cola offline son, por diseño, especificos del quiosco (ADR-036) y no deberian entrar aqui; pero si en el futuro el quiosco necesita formatear un instante o mostrar un estado de carga con el mismo patron, este paquete es el sitio, no una tercera copia.
- **Catalogo de motivos de correccion (Anexo C)**: hoy traducido por separado en panel y portal. Serial el candidato inmediato siguiente si se decide centralizar tambien claves de i18n (ADR-036, consecuencias).
