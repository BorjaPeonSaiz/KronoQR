# ADR-041 — Los ficheros generados en diferido se descargan con un enlace de un solo uso y caducidad, sin sesión

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 22 de septiembre de 2026 |
| **Decide** | `backend-laravel` (forma) · `seguridad-cumplimiento` (revisión) |
| **Afecta a** | `Reporting/Domain/Model/ReportExport` y su migración · `Reporting/Application/UseCase` (petición, estado, descarga, purga) · `Reporting/Http` · `docs/api/openapi.yaml` · `frontend-admin/src/features/reports` · `docs/01` §5.5 y Anexo B · `docs/cliente/configuracion.md`, `guia-rrhh.md` y `operacion.md` |
| **Requisitos** | RF-IN-06, RF-IN-07, RS-05, RL-15, RF-ID-03 |
| **Matiza** | [ADR-012](ADR-012-api-versionada-en-la-ruta.md), [ADR-020](ADR-020-soporte-con-paquete-de-diagnostico.md), [ADR-037](ADR-037-que-lecturas-de-datos-personales-dejan-asiento.md) |

## Contexto

RF-IN-06 exige que un informe que no cabe en una petición síncrona se genere en cola y se avise «con
un **enlace de descarga** cuando esté listo» (doc 05 §5.4, compromiso comercial literal). El fichero
que hay al otro lado de ese enlace **contiene datos personales de la plantilla por su finalidad**: horas
por persona y periodo, y en la salida a nómina también apellidos y departamento. No es un recurso
público ni un recurso anónimo; es el registro horario de un hotel entero en un CSV.

La palabra «enlace» trae consigo una propiedad incómoda: **un enlace se abre con un clic**, desde el
navegador o desde un cliente de correo, y en ese clic **no viaja ninguna cabecera `Authorization`**. La
sesión del panel es un token *Bearer* que guarda la SPA (tarea 2.1): no se adjunta a una navegación
del navegador, y no puede adjuntarse sin convertirlo en una cookie, que es una decisión de
autenticación distinta y con sus propias consecuencias (CSRF, `SameSite`, caducidad).

El producto ya tiene el patrón **casi** resuelto en la exportación íntegra de la tarea 5.10
(`data_exports`), pero allí la descarga **sí** la hace la SPA con su sesión: es una acción del
administrador dentro del panel y no se anuncia por correo. Aquí hay dos diferencias que cambian el
problema:

1. **Se avisa a quien lo pidió**, y ese aviso puede llegar por correo cuando la instalación tiene
   salida de SMTP. Todo lo que ponga un enlace en un correo hay que decidirlo explícitamente.
2. **Puede haber muchas a la vez y son de personas distintas** —una por solicitante—, así que el
   control de acceso no es «eres administrador», es «eres quien la pidió».

Además, la decisión no puede debilitar tres cosas que ya están escritas: el asiento de cada divulgación
(RS-05, ADR-037), la posibilidad de acotar una brecha desde el *trail* (RL-15) y el hecho de que el
fabricante nunca accede a los datos del cliente (ADR-020).

## Decisión

**El fichero generado en diferido se descarga con un token de un solo uso, de vida corta, ligado a la
fila de esa exportación, que se emite al consultar el estado, rota en cada consulta y se consume en la
descarga. La ruta de descarga no lleva sesión y el token nunca viaja por correo.**

En concreto:

1. **El token nace al consultar el estado, no al pedir la exportación.** `GET /api/v1/reports/exports/{uuid}`
   —autenticada, con la sesión del panel— devuelve, si la exportación está `completed` y el fichero no
   ha caducado, un bloque `download` con `{url, expires_at}`. **Cada consulta emite un token nuevo y
   deja inválido el anterior.** Así el enlace existe solo durante la ventana en que alguien lo está
   usando, y no desde el momento en que se encoló el trabajo.
2. **Son 32 bytes aleatorios y en la fila solo vive su `sha256`.** El token en claro se devuelve una vez
   y no se almacena nunca, exactamente como `devices.token_hash`, `credentials.secret_hash` y el
   código de emparejamiento del quiosco. La comparación va con `hash_equals`. Un volcado de la base
   de datos no contiene ninguna credencial de descarga.

   **Cuánta entropía hay que adivinar, dicho sin inflar la cifra:** no son «122 bits de `uuid`». Son
   **~74 bits aleatorios del `uuid` v7** —los otros 48 son marca de tiempo, y quien sabe cuándo se
   pidió el informe los conoce— **más los 256 bits del token**, y además de un solo uso. Lo que
   sostiene esta decisión es el token y su consumo, no el identificador.
3. **Caduca en minutos**, `REPORTING_EXPORT_LINK_TTL_MINUTES` (15 de serie), y **se consume en la
   descarga**: se sella `downloaded_at`, se incrementa `download_count` y se borra el hash. Volver a
   pedir el estado emite otro mientras el fichero exista.

   La semántica de los rechazos es deliberadamente avara, y solo hay **dos casos** en los que la
   respuesta dice algo:

   - **`410 urn:kronoqr:problem:report-export-link-used`** — la exportación **ya se descargó**
     (`downloaded_at` sellado) y se presenta una URL que ya no vale. Se dice porque quien llega aquí
     ya acertó el `uuid`, el fichero sigue existiendo y lo que necesita saber es que pida otro enlace
     desde la pantalla. Es además la señal que ve el legítimo cuando alguien se le adelantó.
   - **`410 …link-expired`** — el token presentado **es el vigente** pero se le pasó el plazo. Misma
     salida: volver a la pantalla.

   **Todo lo demás es `404` sin detalle**: sin `token`, con un token ajeno, con un enlace que nunca se
   emitió o que una consulta posterior rotó, sobre una fila `purged` y sobre la exportación de otra
   persona. Enumerar el motivo no le cambia la acción a quien lo recibe y confirmaría la existencia de
   una exportación ajena.
4. **La ruta de descarga va sin cabecera `Authorization`** y tiene **zona de límite de tasa propia y
   estrecha** (`throttle:report-download`, 30 r/m por IP, la misma cifra que la descarga de la
   exportación íntegra), precisamente porque no la protege una sesión.
5. **El correo de aviso no lleva el enlace de descarga**: lleva el enlace a la pantalla del panel. El
   correo es un canal que el producto no controla —servidor del cliente, buzón compartido de RRHH,
   reenvíos— y un fichero con el registro horario de la plantilla no se deja detrás de una URL que
   sobreviva en un buzón.
6. **El fichero caduca y la fila no.** A los `REPORTING_EXPORT_RETENTION_DAYS` (7 de serie) una tarea
   diaria borra el fichero y deja la fila en `purged` (regla dura 5); la descarga de una fila `purged`
   responde `404` y la exportación sigue apareciendo en la lista con lo que se pidió y cuándo.
7. **Cada descarga deja asiento** `report_export.downloaded` (RS-05, ADR-037) con el contador y la
   huella del fichero, nunca un nombre ni la ruta absoluta (regla dura 21).

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **URL firmada con `APP_KEY`** (`URL::temporarySignedRoute`) | Es la opción que Laravel regala y resuelve la caducidad, pero **no el consumo**: la misma URL vale tantas veces como se abra hasta que expire, así que reenviarla equivale a compartir el fichero. Y el secreto pasa a ser `APP_KEY`, una clave de instalación cuya rotación invalidaría sesiones y datos cifrados: ningún enlace de descarga debería depender de ella, y una fuga por el lado del enlace tendría un alcance desproporcionado. El token por fila se revoca borrando una columna |
| **Descargar con la sesión del panel**, como hace la exportación íntegra | Funciona dentro de la SPA y es lo que ya se hace en `data_exports`. Aquí no basta: RF-IN-06 promete un **enlace**, y un enlace que solo abre la SPA obliga a que el fichero pase por memoria del navegador para volver a guardarse, complica la descarga de ficheros grandes y no permite el caso real de «me llega el aviso, pincho y guardo». Además no reduce nada la superficie: seguiría haciendo falta autorizar por solicitante |
| **Poner el enlace de descarga en el correo** | Es lo que la mayoría de los productos hace, y por eso conviene decir por qué aquí no. El correo sale del servidor del cliente hacia un buzón que el producto no controla —muchas veces un buzón compartido de RRHH—, se reenvía y se archiva durante años. Un enlace ahí es un fichero con el registro horario de la plantilla accesible a quien tenga acceso al buzón, sin que quede constancia de quién lo abrió. El enlace a la pantalla del panel cumple el aviso y **exige sesión** para llegar al fichero |
| **Enlace permanente mientras el fichero exista** (sin consumo, sin caducidad corta) | Convierte la URL en una credencial de larga vida sin dueño: siete días en un historial de navegador, en un chat o en un registro del proxy. El coste de la alternativa elegida es un clic más cuando la descarga se corta; el coste de esta es una divulgación sin asiento |
| **Que `admin` pueda descargar las exportaciones de los demás** | Sería cómodo para soporte interno y abriría una vía de acceso al registro horario de terceros sin el asiento que ADR-037 exige por conjunto. Una exportación es de quien la pidió, con el alcance que tenía al pedirla (RF-ID-03); quien necesite esos datos los pide con su cuenta y deja su propio rastro |
| **Servir el fichero desde `public/` con un nombre imposible de adivinar** | Seguridad por oscuridad sobre datos personales, sin caducidad, sin asiento de descarga y sin control de acceso: el servidor web lo entregaría antes de que la aplicación se enterase |

## Consecuencias

- **`report_exports` gana tres columnas** —`download_token_hash`, `download_token_expires_at`,
  `download_count`— y un `downloaded_at`. La tabla se describe en el doc 01 §5.5.
- **El contrato crece en dos rutas aditivas** (Anexo B): `GET /reports/exports` y
  `GET /reports/exports/{uuid}/download`. La segunda es **la única ruta del producto que autoriza con
  un secreto en la URL en lugar de con `auth:sanctum`**: en el contrato lleva su propio esquema
  `reportDownloadToken` (`apiKey` en `query`) y **no** `bearerAuth`, y por eso se documenta como
  excepción con motivo, igual que la exención de `POST /auth/logout` en la lista de zonas de límite de
  tasa.
- **El panel no guarda enlaces.** La pantalla pide el estado y abre el enlace que recibe en ese
  momento; no cachea ninguno, porque cachearlo sería guardar un secreto que ya se consumió.
- **Un usuario que pulsa «Descargar» dos veces seguidas puede ver un `410`.** Se acepta a propósito: la
  pantalla lo trata pidiendo el estado otra vez, y el mensaje de error dice qué hacer. La alternativa
  —tolerar el segundo uso— es exactamente lo que esta decisión evita.
- **La descarga necesita su propia zona de límite de tasa**, que la prueba que enumera el router
  (`RouteRateLimitZonesTest`) vigila junto a las demás.
- **Vale para cualquier fichero diferido futuro.** Si algún día la exportación para la Inspección o la
  exportación íntegra se generan en diferido, este es el mecanismo, y no habrá que inventar otro.

## Verificación

- **Feature:** el enlace emitido caduca a los `REPORTING_EXPORT_LINK_TTL_MINUTES` (`410 …link-expired`)
  y, usado una vez, responde `410 …link-used` la segunda.
- **Feature:** pedir el estado dos veces emite dos tokens y **el primero deja de valer**.
- **Feature:** la descarga sin `token`, con un token de otra exportación, con un enlace que una
  consulta posterior rotó y sobre una fila `purged` responde `404`, sin distinguir entre «no existe»
  y «no es tuya»; el `410 …link-used` solo aparece cuando la exportación ya se descargó.
- **Autorización negativa:** una cuenta distinta de la solicitante recibe `404` en el estado y en la
  descarga, incluida una cuenta `admin`.
- **Integración:** cada descarga escribe `report_export.downloaded` con el contador, y el asiento no
  contiene nombres ni la ruta absoluta del fichero.
- **Contrato:** las dos rutas nuevas están en `openapi.yaml`, y la de descarga declarada con su propio
  esquema `reportDownloadToken` (`apiKey` en `query`) y **sin `bearerAuth`**.
