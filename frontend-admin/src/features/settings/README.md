# settings

Configuracion de la instalacion, perfil de cumplimiento, **licencia** y marca
(RF-PD-01, RF-PD-04, RF-PD-05, RF-PD-07, RF-PD-08). Tareas 5.1, 5.2, 5.3 y 5.8.

Hoy viven aqui tres pantallas:

- **`ComplianceProfileView.vue`** — los umbrales **legales** del centro
  (tarea 5.2).
- **`LicenseView.vue`** — el estado de la licencia y la activacion de una clave
  (tarea 5.3), con su `license.store.ts` y su `license.api.ts`.
- **`BrandingView.vue`** — el nombre, el color de acento y la ruta del
  logotipo de la instalacion (tarea 5.8, ruta `/branding`, ambito
  `settings:*`). Lee y escribe por `settings.api.ts`, el mismo catalogo que ya
  usa el paso de organizacion del asistente (`onboarding/steps/OrganisationStep.vue`)
  para `BRANDING_APP_NAME` y las claves `LOCALE_*`: las dos pantallas
  comparten `stringValue()` para no divergir. Tras guardar, llama a
  `shared/branding/branding.store.ts` para que la cabecera del panel
  (`AppShellView`) y el acceso (`LoginView`) enseñen la marca nueva en el
  acto, sin esperar a una recarga. La previsualizacion usa `accentOverrides` y
  `contrastWarnings` de `@kronoqr/web-kit/branding` sobre estilos EN LINEA:
  nunca toca `:root` hasta que se guarda de verdad.

Y un componente que **no** es una pantalla: **`LicenseNotice.vue`**, el aviso
persistente que `AppShellView` pinta en **todas** las secciones del panel. Vive
aqui y no en `shared/ui` porque su contenido es de esta _feature_ —comparte
store, textos y destino con la pantalla de licencia—; lo unico que aporta el
marco es el sitio donde colgarlo.

La configuracion de la instalacion que **todavia no tiene pantalla propia**
son los umbrales operativos (`ATTENDANCE_*`): se guardan y se auditan desde la
5.1 por `settings.api.ts`, pero ninguna pantalla los edita todavia.

Los tres son recursos distintos a proposito: un umbral legal lo fija la
jurisdiccion, uno operativo lo fija el hotel (doc 01 §4) y la licencia dice **que
se contrato**, que no es un ajuste sino un hecho comercial — por eso tiene ambito
propio (`license:*`) y no `settings:*`.

> **Lo que ninguna de estas pantallas puede hacer:** apagar el registro horario.
> La de licencia lo dice en voz alta, arriba y en todos los estados, porque quien
> llega a ella suele llegar por un aviso y esa es la pregunta que trae
> (ADR-019, regla dura 15).

## Exportación íntegra de datos (RF-PD-14, RL-20, tarea 5.10)

**`DataExportPanel.vue`**, con su `dataExport.api.ts`, es una sección propia
dentro de `LicenseView.vue` («Tus datos son tuyos»), **fuera** del bloque que
depende de que `GET /api/v1/license` haya respondido: no se degrada ni deja de
enseñarse si la licencia está caducada, ausente o no se puede verificar
(ADR-019, regla dura 15). Vive en «Licencia» y no en una pantalla propia
porque es donde el cliente mira cuando teme quedarse sin producto, y RL-20 es
la respuesta a ese temor (ficha de la tarea 5.10, decisión 10).

**Por qué es asíncrona.** Un ZIP con cuatro años de fichajes de una plantilla
entera no cabe en los 60 s de una petición HTTP ni en la memoria de un
`fetch` que se corta a la mitad. `POST /api/v1/data-export` encola el trabajo
y responde con la fila (`pending`); el panel la sondea con `GET
/api/v1/data-export` cada 5 s **solo mientras algo sigue en curso**
(`refetchInterval` condicionado a que haya una fila `pending`/`running`, igual
que la flota de quioscos en `features/devices`) y ofrece la descarga en cuanto
pasa a `completed`. Si ya había una en curso, el servidor responde `409` con
esa misma fila en el cuerpo (`urn:kronoqr:problem:data-export-in-progress`):
`dataExport.api.ts` la lee y la devuelve como si fuera la petición normal, para
que la vista nunca tenga que distinguir los dos casos ni tratar el segundo
como un fallo.

**Por qué nunca se interpreta el ZIP.** Exactamente el mismo motivo que el
paquete de diagnóstico de `features/support`: el fichero puede llevar la
plantilla entera, los fichajes y la auditoría completa, así que el panel se
limita a pedirlo (`requestBlob`) y a soltarlo con `downloadDocument`, con el
nombre que trae `Content-Disposition`. Parsearlo aquí sería darle al panel un
motivo para tener ese contenido en memoria del navegador.

**Límite práctico del navegador.** El panel recibe el ZIP entero en memoria
antes de guardarlo, así que por encima de ~1 GB conviene generar la
exportación desde la consola (`php artisan product:export-all`) y sacar el
fichero del contenedor (`docker compose cp`, `docs/cliente/operacion.md`); esa
misma fila aparece igual en esta lista, con `requested_via: console` reflejado
en «Pedida por» como «Consola».

Solo `admin` con ámbito `settings:*` (doc 02 §7.3, nota 6): llevarse todos los
datos de la instalación es la potestad de quien responde de ella, y ningún
token de soporte lo lleva nunca (regla dura 16), aunque su alcance incluyera
ese ámbito. La autorización real es la policy del servidor (regla dura 18);
`canManage` en `DataExportPanel.vue` solo evita la frustración de un
formulario que el servidor rechazaría con `403`.

**Candidatos a `web-kit` que se quedaron locales.** `sizeLabel` (bytes a
KiB/MiB/GiB/TiB, divisor 1024, mismo redondeo que
`ProductExportAllCommand::humanBytes` y `DiskProbe` en la consola) y
`failureReasonLabel` (los cuatro códigos estables de `failure_reason` con su
texto de reserva) viven en `DataExportPanel.vue` y no en
`@kronoqr/web-kit`, que es donde tocaría si otra pantalla del panel o del
portal necesitara enseñar un tamaño de fichero o un motivo de fallo con la
misma forma. No se movieron porque extraerlas exige tocar el paquete
compartido y, en este entorno Windows, `npm install` rompe el
`package-lock` (nota del CLAUDE.md del proyecto): queda anotado aquí para
quien haga esa extracción con el entorno arreglado.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
