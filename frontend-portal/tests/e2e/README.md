# Pruebas E2E del portal del empleado

Playwright sobre el build (`vite preview`), sin backend: las llamadas a `/api/v1/*` se
interceptan en `support/portal.ts` con las formas del contrato. `make e2e` las ejecuta junto
a las del panel y las del quiosco.

```bash
npm run test:e2e                        # todo
npx playwright test --grep @RL-05       # por etiqueta de requisito (§9.6)
npx playwright test --ui                # para depurar
```

## Qué hay aquí

| Fichero                 | Cubre                                                                                                                                                                                                                                                                                                                             |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `login.spec.ts`         | `@RL-05`, `@RF-ID-06`, `@RF-ID-07` — acceso con código de empleado y PIN, guarda de rutas con `redirect`, PIN incorrecto con el mismo aviso genérico que el bloqueo por intentos (`429`, RS-03), el PIN nunca queda escrito ni en la URL, salida                                                                                  |
| `my-records.spec.ts`    | `@RL-05`, `@RF-ID-05`, `@RN-13`, `@RL-04`, `@RF-ID-07` — jornadas con el total en horas y minutos, tramo corregido con motivo y valor anterior, un turno abierto marcado como tal, descuadre de totales sin elegir ninguno (RN-06), filtro de periodo, y el caso negativo (manipular la URL con el identificador de otra persona) |
| `my-export.spec.ts`     | `@RL-05`, `@RF-ID-05` — descarga del CSV propio con `waitForEvent('download')`, nombre de fichero sin datos de nadie, periodo elegido, periodo inválido sin petición al servidor                                                                                                                                                  |
| `branding.spec.ts`      | `@RF-PD-08` — nombre, color de acento y logotipo del cliente en el acceso y en el marco autenticado; vuelta a la marca del producto sin red o con una respuesta que no cuadra con el contrato                                                                                                                                     |
| `accessibility.spec.ts` | con `@axe-core/playwright`, 0 violaciones críticas/graves en el acceso, el registro, la exportación y la página de «no encontrado»                                                                                                                                                                                                |
| `client-errors.spec.ts` | `@RF-PD-15` — un error real se manda a `POST /api/v1/client-errors` al pasar a autenticado; un fallo del servidor al reportarlo no bloquea el portal ni reintenta en bucle                                                                                                                                                        |

### El caso negativo: nunca datos de un tercero (`my-records.spec.ts`)

`GET /api/v1/me/workdays` no lleva ningún identificador de empleado, ni en la ruta ni en la
consulta (regla dura 18): el empleado se resuelve del token de portal (`self:read`), que es
lo único que este cliente no puede falsificar. La prueba «manipular la URL con el
identificador de otra persona…» añade parámetros de consulta con un `uuid` ajeno y comprueba
que la petición real al servidor nunca los lleva y que lo que se ve sigue siendo el registro
propio; otra prueba visita una ruta con forma de gestión (`/employees/{uuid}/workdays`, que
es del panel) y comprueba que el portal no tiene esa ruta y cae en el rescate de «página no
encontrada». La autorización real —que el servidor rechace un token de portal fuera de
`self:read`— se prueba en el backend (`AuthorizationNegativeTest` y compañía).

### El bloqueo por intentos (`login.spec.ts`, RS-03, RS-12)

El contrato (`POST /api/v1/me/login`) no distingue código inexistente, PIN incorrecto ni
bloqueo por intentos: los tres devuelven el mismo `401` genérico. El bloqueo en sí lo aplica
un límite de tráfico aparte (`429`, `TooManyRequests`) que tampoco confirma nada sobre la
cuenta — «demasiados intentos seguidos», no «esta cuenta está bloqueada». `stubPortalApi`
gana `loginOutcome: 'rateLimited'` para simular ese `429` con su `Retry-After`; el contador
real, sus umbrales crecientes (3 fallos → 5 min, 5 → 15 min, 10 → 60 min) y que sea distinto
del contador del quiosco son del backend y allí se prueban (§7.5, RS-12).

## Proyecto único: móvil

`playwright.config.ts` tiene un solo proyecto, `mobile` (412×915, `Pixel 7`), a propósito: el
portal es de prioridad móvil (doc 02 §11) — quien lo consulta lo abre casi siempre desde su
teléfono personal, en su tiempo libre, no desde el ordenador del centro. A diferencia del
panel (un solo proyecto de escritorio), aquí no hay un segundo proyecto de escritorio: las
diferencias de maquetación por tamaño de pantalla las cubren las pruebas unitarias y las
capturas (`tests/screenshots/`), no este recorrido funcional.

## Se prueba el BUILD, no `vite dev`

`playwright.config.ts` construye y sirve `dist/` con `vite preview`, que es exactamente lo
que se despliega. El build se hace en el propio `webServer` para que el E2E nunca corra sobre
un `dist/` viejo. Puerto **4175**: distinto del 4173 del quiosco, el 4174 del panel y el
4176/4177/4178 de las capturas de panel, quiosco y portal (ver `HANDOFF.md` → «Trampas del
entorno»).

## El navegador está en otra zona horaria a propósito

`timezoneId: 'Atlantic/Canary'`, con los datos de ejemplo en `Europe/Madrid`. Las horas que
muestra el portal vienen resueltas en la zona del centro (regla dura 3) y ya llegan
convertidas en la respuesta (`clocked_in_at_local`, `performed_at_local`): si alguien
reconvirtiera con la zona del navegador, una noche de cambio de hora dejaría de cuadrar, y
por eso el navegador nunca comparte zona con los datos.

## `@axe-core/playwright` sin declarar

No está en `package.json` de este paquete: llega **hoisted** a la raíz del workspace de npm
porque `frontend-admin` ya lo declara (mismo árbol de dependencias, ADR-036). Añadirlo aquí
también exigiría un `npm install`, que en Windows con `node_modules/` presente rompe los
binarios nativos de `@tailwindcss/oxide` — se importa tal cual, sin declararlo, y el `npm ci`
de la CI (que instala desde la raíz) lo deja en el mismo sitio.

## El backend no participa

Lo que se prueba aquí es el recorrido de la persona empleada por el portal. Lo que el
servidor autoriza o deniega —el ámbito `self:read`, el bloqueo real por intentos, el saneado
de un error de cliente antes de guardarlo— se prueba en el backend (regla dura 18). Una ruta
que el doble no prevé responde `404 problem+json` para que una pantalla nueva falle aquí y no
se quede esperando.

## Lo que falta

- Los cuatro recorridos por una persona ajena a la implementación siguiendo solo
  `docs/cliente/guia-portal-empleado.md` (decisión 11 de la ficha 5.11b), no sustituidos por
  ningún E2E.
- Ejecución en la CI: la etapa ⑦ (`.github/workflows/e2e.yml`) sigue siendo el marcador de la
  tarea 3.7, también para el panel y el quiosco.
