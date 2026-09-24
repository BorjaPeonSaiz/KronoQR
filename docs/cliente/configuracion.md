# Configuración de KronoQR — qué se puede cambiar y qué consecuencias tiene

> **Estado.** Redactado en la **tarea 5.1** con las **claves de configuración de
> la instalación** (`GET`/`PATCH /api/v1/settings`) y ampliado en la **tarea 5.2**
> con el **perfil de cumplimiento** (`GET`/`PATCH /api/v1/compliance-profile`,
> sección 2.4) y en la **5.3** con la **licencia** (sección 3 bis). La **5.8**
> añade la pantalla de marca del panel y la **5.11** integra esta guía con el
> resto; ninguna de las dos reescribe lo de aquí.

---

## 1. Los tres sitios donde vive la configuración, y por qué son tres

Antes de cambiar nada conviene saber dónde buscar. **Si te equivocas de sitio, el
cambio no se aplica** y no hay ningún aviso que te lo diga.

| Qué | Dónde | Cómo se cambia | ¿Hay que reiniciar? |
| --- | --- | --- | --- |
| **Marca, idiomas y umbrales operativos** | Tabla `installation_settings` | `PATCH /api/v1/settings` (panel, rol *administrador*) | No |
| **Umbrales legales**: descanso mínimo, jornada máxima, pausas, años de retención | Tabla `compliance_profiles` | `PATCH /api/v1/compliance-profile` (panel → «Perfil de cumplimiento», rol *administrador*) | No |
| **Todo lo del despliegue**: rutas, credenciales, puertos, claves | Fichero `.env` del servidor | Editar y reiniciar los contenedores | **Sí** |

La regla para no equivocarse: **si lo cambiarías sin avisar a nadie de
sistemas, es del panel; si tocarlo implica reiniciar el servicio, es del `.env`.**

### La variable de entorno no gana a la base de datos

Algunas propiedades tienen las dos caras: hay variables de marca en el `.env` y
hay claves de marca en la configuración de la instalación. **Manda siempre la base
de datos.** La variable de entorno es solo el valor con el que el instalador
siembra la primera fila la primera vez; a partir de ahí, lo que guardes en el
panel es lo que se aplica.

Ya no hay ninguna excepción: **las variables `BRANDING_NAME` y
`BRANDING_ACCENT_COLOR` se retiraron del `.env`**, porque tener dos sitios para
el mismo dato solo servía para que alguien cambiara el color en el panel, no
viera ningún efecto y no tuviera forma de saber por qué. Lo único de marca que
sigue en el `.env` es **dónde puede vivir el fichero del logotipo**
(`BRANDING_LOGO_ROOT` y `BRANDING_PATH`), que es del servidor y no del hotel.

---

## 2. Qué se puede configurar, una por una

Todas las claves tienen **un valor de serie**, y ese valor de serie **es el
producto**: una instalación recién puesta en marcha funciona sin tocar ninguna.
Se cambian solo las que hagan falta.

### 2.1 Fichaje

| Clave | De serie | Rango | Qué pasa si la cambias |
| --- | --- | --- | --- |
| `ATTENDANCE_BREAK_CLOCKING` | `disabled` | `enabled` o `disabled` | Enciende el **fichaje de pausa** en toda la instalación. Tres consecuencias, ni una más ni una menos — ver debajo de la tabla. |
| `ATTENDANCE_MAX_SHIFT_HOURS` | `12` | 1 – 24 | A partir de esa duración, un tramo cerrado se marca como **anómalo** y se abre una incidencia para revisión. **No cierra ningún turno por su cuenta.** |
| `ATTENDANCE_DEBOUNCE_SECONDS` | `60` | 0 – 3600 | Ventana de gracia: dos escaneos de la misma persona dentro de esa ventana cuentan como uno. **Esta clave cambia las horas registradas** — ver el aviso de abajo. `0` la desactiva. |
| `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` | `15` | 1 – 1440 | Desfase tolerado entre el reloj de la tablet y el del servidor antes de marcar el fichaje para revisión. **Nunca rechaza un fichaje**, solo lo señala. Es además el umbral con el que **la propia tablet avisa** de que su hora se ha ido. |
| `ATTENDANCE_MIN_TRANSIT_SECONDS` | `120` | 0 – 3600 | Tiempo mínimo creíble para ir de un quiosco a otro. Por debajo, se abre incidencia. Ponlo a `0` si tienes dos tablets en la misma puerta; súbelo si hay dos edificios. |
| `ATTENDANCE_PATTERN_WINDOW_SECONDS` | `10` | 0 – 300 | Segundos por debajo de los cuales dos fichajes de **dos personas distintas en el mismo quiosco** cuentan como una **coincidencia** (como mucho una por pareja y día). **No abre incidencia por sí sola**: hace falta que la misma pareja acumule los días de la clave siguiente. `0` desactiva este patrón. |
| `ATTENDANCE_PATTERN_MIN_REPEATS` | `3` | 1 – 30 | Días con coincidencia que tiene que acumular la misma pareja, dentro de los últimos 30 días, para que se abra la incidencia «Patrón anómalo de uso de la credencial» —**una a cada persona**—. Súbelo si en tu centro es normal entrar en grupo por la misma puerta; bájalo a `1` solo si quieres ver cada pareja de escaneos seguidos. **La incidencia no anula ningún fichaje ni califica a nadie**: la revisa el responsable ([`guia-rrhh.md`](guia-rrhh.md) §4.5). |
| `WEEKLY_SUMMARY_EMAIL` | `disabled` | `enabled` o `disabled` | Enciende el **resumen semanal por correo**: los lunes a las 06:00 UTC, cada responsable de departamento activo y con correo recibe la semana anterior **de su ámbito y de nadie más**. Exige salida de correo configurada (sección 6.21) y la funcionalidad `weekly_email_summary` en la licencia; sin cualquiera de las dos **el sistema funciona igual** y el envío se omite dejando constancia. Ver debajo de la tabla. |
| `KIOSK_UPDATE_WINDOW` | `03:00-05:00` | `HH:MM-HH:MM`, hora local del centro | Franja en la que las tablets **tienen permiso** para instalar una versión nueva de la app del quiosco. Fuera de ella no se actualizan nunca, aunque la versión lleve días esperando. Puede cruzar la medianoche (`23:30-01:30`). Ver debajo de la tabla. |
| `KIOSK_UPDATE_QUIET_MINUTES` | `10` | 0 – 120 | Minutos **sin ningún fichaje** que la tablet exige, además de estar dentro de la ventana y con la cola vacía, antes de actualizarse. Cubre el turno que empieza antes de lo previsto. `0` deja solo las otras dos condiciones. |
| `BASELINE_MANUAL_HOURS_PER_MONTH` | `0` | 0 – 10000 | Horas al mes que RRHH dedicaba a consolidar hojas de horas **antes** de instalar el sistema, declaradas por el hotel. **Solo alimenta el cuadro de impacto** ([`guia-rrhh.md`](guia-rrhh.md) §6.6), que la enseña junto al objetivo de reducirla un 80 %; el cuadro exige la funcionalidad `impact_dashboard` en la licencia. `0` significa «no declarada» y deja ese indicador vacío. **No cambia ningún cálculo**: ni horas, ni incidencias, ni informes. **El soporte del fabricante no puede tocarla** (403, como `WEEKLY_SUMMARY_EMAIL`): es el denominador declarado del objetivo comercial y describe tu proceso anterior a la instalación ([`operacion.md`](operacion.md) §12.4). |

> **Las tres claves de tránsito y de patrón ajustan un sistema de control sobre
> la plantilla, no un parámetro técnico.** La detección de patrones de uso de credencial forma
> parte de lo que hay que informar previamente a las personas trabajadoras y a
> su representación (art. 20.3 ET y arts. 87 a 91 LOPDGDD;
> [`obligaciones-legales.md`](obligaciones-legales.md) §3). Bajar
> `ATTENDANCE_PATTERN_MIN_REPEATS` o subir `ATTENDANCE_PATTERN_WINDOW_SECONDS`
> **endurece** ese control: hazlo como decisión documentada y comunicada, no
> para «ver más». Cada cambio queda en la auditoría con autor, valor anterior y
> nuevo (sección 4, «…necesito saber quién cambió un umbral y cuándo»).
>
> **El nombre de cada quiosco viaja en estas incidencias y en la auditoría.**
> Cuando des de alta una tablet ([`../runbooks/alta-nuevo-quiosco.md`](../runbooks/alta-nuevo-quiosco.md)
> §3.2), su rótulo nombra **un sitio** —«Recepción», «Entrada de personal»—,
> **nunca a una persona** («Tablet de María»): el rótulo se escribe en el
> contexto de cada incidencia y en los asientos de auditoría, que no admiten
> nombres.

**`ATTENDANCE_BREAK_CLOCKING` — qué cambia exactamente.** Se pone en
Panel → **Ajustes operativos** (`/settings`) → «Fichaje de pausa», donde las dos
opciones se llaman «Activado» y «Desactivado». Surte efecto en la petición
siguiente. Con `enabled`:

1. **Aparece el botón «Pausa» en la tablet.** Quien sale a descansar lo pulsa y
   pasa la tarjeta; **para volver solo pasa la tarjeta**, sin pulsar nada. Con
   `disabled` el botón no existe y nadie ve ninguna diferencia.
2. **La revisión de cada madrugada empieza a abrir la incidencia «Sin pausa
   registrada»** sobre los tramos continuos por encima del umbral del convenio.
   **No reprocesa el pasado**: empieza en la pasada siguiente y solo dentro de
   su ventana de revisión (7 días de serie).
3. **La pantalla «Cumplimiento» empieza a contar esa regla.** Mientras está en
   `disabled`, la tarjeta se ve con su umbral y marcada «No se evalúa», con el
   motivo escrito.

**Actívalo solo cuando la plantilla vaya a fichar la pausa de verdad, y avisa
antes.** Encenderlo en un hotel donde nadie la ficha abre una incidencia por
cada turno de más de seis horas, y ninguna de ellas distingue «no descansó» de
«descansó y no lo fichó»: en una semana la bandeja queda inservible. Por eso se
entrega desactivado.

**Volver a `disabled` es seguro**: la regla se suspende otra vez, dejan de
abrirse incidencias nuevas y **las que ya estaban abiertas se quedan como
están** — no se cierra ninguna sola, igual que con cualquier otro cambio de
criterio. Los fichajes de pausa ya registrados **no se tocan ni se
reinterpretan**: siguen siendo dos tramos con su pausa en medio.

> **El umbral de la pausa no está aquí.** «A partir de cuántas horas hay que
> haber descansado» es `break_required_after_hours` del perfil de cumplimiento
> (sección 2.4), 6 h en el perfil español de hostelería que se entrega de serie.
> Esta clave decide **si la regla se evalúa**; aquella, **con qué número**.
> Mientras esta esté en `disabled`, cambiar aquella no altera ninguna
> incidencia, y el registro de auditoría del cambio lo dice.
>
> **Y la cota de cuánto puede durar una pausa tampoco está aquí**: la vuelta solo
> continúa la jornada si llega antes de `min_rest_hours` (sección 2.4). Pasado
> ese tiempo, el fichaje abre jornada nueva.

> **El soporte del fabricante no puede tocar esta clave.** Un acceso de soporte
> con alcance `configuration` cambia el resto de ajustes operativos, pero **no
> este**: si lo intenta, recibe un 403. Es la misma excepción que el perfil de
> cumplimiento, y por el mismo motivo —decide **qué se considera incidencia** en
> tu registro horario, y esa decisión es del hotel—. Lo activas tú desde tu
> panel; el reparto completo está en
> [`operacion.md`](operacion.md) §12.4.

> **⚠️ `ATTENDANCE_DEBOUNCE_SECONDS` afecta al cálculo de horas.** Subirlo hace
> que fichajes reales muy seguidos se descarten, y el total de la jornada sale
> distinto. Es la única clave de esta lista que mueve minutos del registro legal.
> Cámbiala con criterio y déjalo dicho por escrito: el cambio queda auditado con
> tu nombre, la fecha y el valor anterior.
>
> **Una pausa mal pulsada no cae en esta ventana.** Si alguien pulsa «Pausa» sin
> querer y vuelve a pasar la tarjeta a los veinte segundos, el sistema reconoce
> que la segunda intención es la contraria de la primera y **no la descarta**:
> se pierden esos veinte segundos, no la tarde entera. Lo que la ventana sigue
> descartando es el doble escaneo accidental, que es para lo que existe.

**`WEEKLY_SUMMARY_EMAIL` — a quién llega y qué lleva.** Se pone en Panel →
**Ajustes operativos** (`/settings`) → «Resumen semanal por correo». Con
`enabled`, cada lunes a las 06:00 UTC el sistema envía **un correo por cada
responsable de departamento** con cuenta activa y dirección de correo, y cada
uno recibe **solo su ámbito**: el de Cocina no ve a nadie de Recepción. RRHH y
administración no lo reciben —tienen el panel entero, y un correo semanal con
toda la plantilla sería una copia periódica del registro fuera del sistema—.
Lleva la semana anterior, de lunes a domingo: una línea por persona con horas
trabajadas, contratadas y desviación, días con actividad, ausencias y festivos;
los totales; el número de incidencias abiertas; y dónde verlo entero («Panel →
Informes, del <inicio> al <fin>»: una indicación, no un enlace). Qué significa cada columna está en
[`guia-rrhh.md`](guia-rrhh.md) §6.5.

- **Hace falta correo saliente** (sección 6.21) **y la funcionalidad
  `weekly_email_summary` en el plan de la licencia** (sección 3 bis.4). Si falta
  cualquiera de las dos, nada falla: la pasada del lunes termina bien, no envía
  nada y lo deja anotado en el registro técnico con el motivo
  ([`operacion.md`](operacion.md) §6). El fichaje, el panel y los informes no
  dependen de este correo.
- **Ese correo lleva nombres de tu plantilla y sale de tu servidor**, como el
  aviso diario de incidencias: cada envío deja asiento en la auditoría con el
  destinatario, la semana y los identificadores de las personas incluidas
  —nunca sus nombres—. Vale lo mismo que para aquel: si tu relevo de correo es
  de un tercero, es un encargado del tratamiento
  ([`obligaciones-legales.md`](obligaciones-legales.md) §2).
- **Es por instalación, no por departamento**: se enciende para todos los
  responsables o para ninguno, y en esta versión no hay baja individual. El
  cambio queda auditado como cualquier otro ajuste.

**`KIOSK_UPDATE_WINDOW` y `KIOSK_UPDATE_QUIET_MINUTES` — cuándo cambia de versión
la tablet.** La app del quiosco comprueba cada hora si hay una versión nueva en
el servidor y, cuando la hay, **no la instala en el acto**: espera a que se
cumplan **tres condiciones a la vez** —la hora local del centro está dentro de
la ventana, la cola de fichajes sin enviar está vacía y no ha habido ningún
fichaje en los últimos `KIOSK_UPDATE_QUIET_MINUTES` minutos— y solo entonces se
recarga con la versión nueva, en unos segundos. Si la ventana se cierra antes de
que se cumplan, espera a la siguiente. Los dos valores llegan a las tablets en
el latido —en menos de un minuto, sin tocarlas— y cada tablet los guarda, así
que valen aunque en ese momento no haya red; una tablet que aún no ha recibido
ninguno usa los de serie. Pon la ventana en la franja más muerta de tu centro y
**nunca sobre un cambio de turno**: la tablet no adivina tu horario, y una
actualización con treinta personas en la puerta es exactamente lo que estas dos
claves evitan. El detalle —y qué enseña la pantalla de diagnóstico mientras hay
una versión esperando— está en [`operacion.md`](operacion.md) §11.1.

### 2.2 Marca

| Clave | De serie | Qué es |
| --- | --- | --- |
| `BRANDING_APP_NAME` | `KronoQR` | Nombre de la aplicación. Hasta 60 caracteres: es lo que cabe en la cabecera de la tarjeta impresa. |
| `BRANDING_LOGO_PATH` | *(vacío)* | Ruta **absoluta en el servidor** a un PNG o un SVG. Vacío significa «el logotipo del producto», no «sin logotipo». |
| `BRANDING_ACCENT_COLOR` | `#b8542a` | Color de acento, en notación `#rrggbb`. Cualquier otra forma se rechaza. |

**Dónde se ve.** En la cabecera y en el título de pestaña del panel, del portal y
del quiosco; en la pantalla de acceso de los tres; en la tarjeta de credencial
impresa; en la cabecera del informe de horas en PDF; y en la primera línea de la
exportación para la Inspección. Un cambio se aplica **en la petición siguiente**,
sin reiniciar nada. Las tarjetas ya impresas, naturalmente, no cambian.

**Lo que no cambia nunca**: los identificadores técnicos. El prefijo `FH1` de los
códigos QR, los nombres de las tablas, las rutas de la API y los comandos siguen
siendo los mismos — renombrarlos dejaría sin poder fichar a quien lleva una
tarjeta ya impresa en el bolsillo.

#### El logotipo: dónde ponerlo y qué se acepta

El logotipo es un **fichero en tu servidor**, no una subida por la web. Vive en el
directorio de marca, que el `docker-compose` monta **de solo lectura** dentro del
contenedor:

| | |
| --- | --- |
| Carpeta en tu servidor | La de `BRANDING_PATH` del `.env`. Si está vacío, `./branding` junto al `docker-compose.yml` |
| Ruta que se escribe en el panel | `/var/kronoqr/branding/<fichero>` |
| Formatos | **PNG o SVG**, comprobados por su contenido y no por la extensión |
| Tamaño máximo | **512 KiB** |
| Dimensiones máximas (solo PNG) | **2048 píxeles** de lado |

**Un solo fichero para dos fondos.** El mismo logotipo se enseña sobre fondo claro
(panel, portal, PDF) y sobre el **fondo oscuro de la tablet**. Un logotipo de trazo
oscuro sobre transparente se lee en el panel y desaparece en el quiosco; elige una
versión que funcione en los dos —o con su propio fondo— y compruébalo en la
pantalla de la tablet antes de darlo por bueno. Hoy no hay un segundo logotipo
para el quiosco.

```bash
# 1. Copiar el logotipo a la carpeta de marca del servidor.
sudo mkdir -p /opt/kronoqr/branding
sudo cp logo.png /opt/kronoqr/branding/logo.png
sudo chmod 0644 /opt/kronoqr/branding/logo.png

# 2. Si no lo estaba ya, apuntar BRANDING_PATH ahí en el .env y recrear el
#    contenedor de la aplicación (solo la primera vez: cambiar el FICHERO
#    después no exige reiniciar nada).
#    BRANDING_PATH=/opt/kronoqr/branding
sudo docker compose up -d app

# 3. Guardar la ruta DE DENTRO del contenedor desde el panel, o por API.
curl -sS -X PATCH https://TU-SERVIDOR/api/v1/settings \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"settings":{"BRANDING_LOGO_PATH":"/var/kronoqr/branding/logo.png"}}'
```

**Se comprueba al guardar, y por eso te enteras en el momento.** Si el fichero no
existe, está fuera del directorio de marca, no es un PNG ni un SVG de verdad o
pasa de los límites, la petición responde `422` y **no se guarda nada**: el
mensaje dice qué pasa y qué hacer. La lista completa está en la sección 4.

Que se compruebe la carpeta no es una molestia burocrática: el logotipo se sirve
por una dirección **pública** —las tablets lo piden antes de que nadie se
identifique—, y sin ese confinamiento cualquiera con permiso para guardar la
configuración podría publicar cualquier fichero del servidor.

**Después, es tolerante.** Si el fichero se borra o el volumen deja de estar
montado, los documentos salen sin logotipo y las aplicaciones enseñan el nombre en
texto. Nadie se queda sin fichar por una imagen que falta.

**Para volver al logotipo del producto**, guarda la clave con la cadena vacía:

```bash
curl -sS -X PATCH https://TU-SERVIDOR/api/v1/settings \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"settings":{"BRANDING_LOGO_PATH":""}}'
```

#### El aspecto propio es una funcionalidad del plan; el nombre no

Si tu licencia no incluye la marca blanca —o ha caducado—, se pierden **el color
de acento y el logotipo**: las aplicaciones y los documentos salen con los
colores de KronoQR y sin imagen.

**El nombre de tu instalación se sigue usando siempre.** No depende de la
licencia y no puede depender de ella: ese nombre encabeza la exportación para la
Inspección de Trabajo y el informe sellado, es decir, es la línea que dice **de
quién** es el registro horario que alguien tiene delante. Que cambiara según el
estado de una licencia —o según un fallo pasajero al verificarla— significaría
que dos exportaciones del mismo mes pueden salir con encabezados distintos sin
que haya cambiado ni un dato. Eso no pasa.

Lo que hayas configurado **no se borra ni se pierde**: se sigue viendo y se sigue
pudiendo editar en esta pantalla, y el aspecto **vuelve a aplicarse solo** en
cuanto la licencia lo cubra, sin que tengas que reconfigurar nada.

Y lo que **nunca** se degrada es el registro: se ficha, se consulta, se corrige,
se exporta para la Inspección y se hacen copias exactamente igual. Ver la
sección 3 bis.3.

### 2.3 Idiomas

| Clave | De serie | Qué es |
| --- | --- | --- |
| `LOCALE_AVAILABLE` | `["es","en"]` | Idiomas que la instalación ofrece. Solo se admiten los que el producto trae traducidos. |
| `LOCALE_DEFAULT` | `es` | Idioma con el que se sirven las aplicaciones y los documentos cuando el navegador no pide otro. |

**Estas dos claves son ahora las que mandan.** `APP_LOCALE` y
`APP_SUPPORTED_LOCALES` del `.env` quedan solo como respaldo: se aplican si la
base de datos no responde, para que una instalación con PostgreSQL caído siga
pudiendo decir qué le pasa en lugar de dar error en todas partes.

Un documento (el CSV de la Inspección, el PDF del informe) sale siempre en el
idioma **de la instalación**, aunque el navegador que lo descarga pida otro: el
idioma que importa ahí es el del programa que abrirá el fichero. Lo que sí sigue
al navegador son los textos de la pantalla.

**Los idiomas no dependen de la licencia**: una instalación que trabaja en inglés
no se queda sin su idioma porque venza un plan.

**El idioma por defecto tiene que estar entre los disponibles.** Si intentas
dejarlo fuera —por ejemplo, quitando `es` de la lista sin cambiar el idioma por
defecto—, la petición se rechaza entera y no se guarda nada.

---

### 2.4 Umbrales legales: el perfil de cumplimiento

Esto **no** está en la pantalla de configuración: tiene la suya, «Perfil de cumplimiento», y
también es de administrador. Están aparte porque son otra cosa. Un umbral
**operativo** lo decides tú según cómo funciona tu hotel; un umbral **legal** lo
fija la norma o el convenio, y equivocarse tiene consecuencias distintas.

Se entrega el perfil **`ES-hosteleria`**, con estos valores:

| Campo | De serie | Qué hace | De dónde sale |
| --- | --- | --- | --- |
| `min_rest_hours` | `12` | Se abre incidencia si entre el fin de un turno y el inicio del siguiente median **menos** de esas horas. **Acota además la pausa** — ver la nota de debajo de la tabla | Art. 34.3 ET |
| `max_daily_hours` | `9` | Se abre incidencia si la suma de los tramos de una jornada **supera** esas horas | Art. 34.3 ET |
| `break_required_after_hours` | `6` | Umbral del tramo continuo sin pausa registrada. **Solo abre incidencia si el fichaje de pausa está activado** (`ATTENDANCE_BREAK_CLOCKING`, sección 2.1; ver abajo) | Art. 34.4 ET |
| `updated_at` | vacío | Solo lectura: cuándo se ajustó por última vez. **Vacío significa «tal como se instaló»** | — |
| `max_weekly_hours` | `40` | Jornada semanal ordinaria. **Lo aplica la vista de cumplimiento** del panel: avisa de las semanas que lo superan, **sin abrir incidencia** (ver abajo) | Art. 34.1 ET |
| `week_starts_on` | `1` (lunes) | Día en que empieza la semana. **Define la semana que mide la vista de cumplimiento** | ISO 8601 |
| `holiday_calendar` | vacío | Festivos del centro, una fecha por línea. **Lo aplica el informe de horas por periodo**: esos días no cuentan como absentismo no justificado. **No abre ni cierra ninguna incidencia** | Lo cargas tú |
| `retention_years` | `4` | Años que hay que conservar el registro antes de poder purgarlo | Art. 34.9 ET |
| `name` | `ES-hosteleria` | Cómo se llama el convenio que el perfil describe | Lo pones tú |

**`min_rest_hours` hace además una segunda cosa: acota cuánto puede durar una
pausa.** La vuelta de un descanso continúa la jornada que estaba en curso solo si
llega **antes** de ese número de horas desde que empezó la pausa; con ese tiempo
o más, el siguiente fichaje **abre una jornada nueva**. Es lo que impide que
quien pulsó «Pausa» a las 15:00 y se fue a casa vea su entrada del día siguiente
pegada a la jornada anterior. Consecuencia de subir o bajar este número: mueve
las dos cosas a la vez —qué se considera descanso insuficiente y hasta cuándo una
vuelta sigue siendo una vuelta—, así que **no lo uses para ajustar solo una de
ellas**. Con el fichaje de pausa desactivado (sección 2.1) la cota no se nota,
porque nadie declara pausas.

**El calendario de festivos se entrega vacío a propósito.** Los festivos dependen
del municipio y del año: un calendario metido dentro del producto caducaría cada
31 de diciembre y sería incorrecto para la mitad de los clientes. Lo cargas tú,
una vez al año, pegando las fechas.

**Dónde se nota el calendario de festivos, exactamente.** Lo aplica el **informe
de horas por periodo**: los días que figuren en él **no se cuentan como
absentismo no justificado**, y salen en su propia columna. Ese es su único
efecto. **No afecta a ninguna incidencia** —ningún festivo abre ni cierra nada
en la bandeja— y no cambia ni una hora del registro. Como el informe se calcula
en el momento de pedirlo, cargar o quitar fechas cambia también lo que digan los
informes de periodos ya pasados, y el cambio queda auditado. Lo explica
[`guia-rrhh.md`](guia-rrhh.md) §5 bis.4.

**Los cuatro tipos de ausencia tampoco se configuran.** El catálogo es cerrado,
por la misma razón que el de motivos de corrección: unos tipos a medida de cada
hotel harían incomparables dos instalaciones y obligarían a tocar el producto
para vender al siguiente cliente.

| Tipo | Qué es |
| --- | --- |
| **Vacaciones** | Vacaciones ya concedidas |
| **Baja médica** | Incapacidad temporal, accidente, cualquier baja con parte |
| **Permiso** | Permisos retribuidos y no retribuidos |
| **Otro** | Ninguno de los anteriores. **Exige escribir una nota** |

No hay flujo de aprobación, ni saldo de vacaciones, ni parámetros que tocar aquí.
Cómo se registran, se corrigen, se anulan y se cargan desde un fichero, y qué ve
cada rol, está en [`guia-rrhh.md`](guia-rrhh.md) §5 bis.

**La jornada semanal y el día de inicio de semana sí se aplican ya**, en la
**vista de cumplimiento** del panel: señala las semanas que superan la jornada
ordinaria, con la semana empezando el día que tú digas. Ese aviso es
**informativo y no abre incidencia** —el art. 34.1 ET fija las cuarenta horas en
cómputo anual, así que una semana por encima no es por sí sola un
incumplimiento—, de modo que cambiar cualquiera de los dos **no altera ninguna
incidencia**, pero sí cambia lo que se ve en esa pantalla desde el momento en
que se guarda. Lo explica [`guia-rrhh.md`](guia-rrhh.md) §4 bis.

**`break_required_after_hours` solo abre incidencias si el fichaje de pausa está
activado.** Mientras `ATTENDANCE_BREAK_CLOCKING` esté en `disabled` —el valor de
serie, sección 2.1— el sistema no puede distinguir «no descansó» de «descansó y
no lo fichó», así que la regla se enseña con su umbral y no se evalúa: abrir
incidencias en esas condiciones llenaría la bandeja de falsos positivos y
taparía las que sí importan. El umbral se guarda igual y empieza a aplicarse en
la primera revisión nocturna posterior a activar el fichaje de pausa.

Consecuencia práctica, y conviene saberla antes de tocarlo: **con el fichaje de
pausa desactivado, cambiar ese umbral no altera ni una incidencia**. La pantalla
lo dice al lado del campo y el registro de auditoría lo deja escrito
(`detection_suspended`), para que dentro de dos años se pueda distinguir «esto
no movía alertas» de «las movía, pero entonces la regla estaba suspendida». Con
el fichaje de pausa activado, en cambio, endurecer el umbral **sí** puede abrir
incidencias de jornadas recientes, dentro de la ventana de revisión.

#### Cambiar un umbral rige desde el cambio, no hacia atrás

Es la decisión más importante de esta pantalla y conviene que la conozcas antes
de tocar nada:

- El valor nuevo se aplica **en la siguiente revisión diaria**, que mira los
  últimos siete días. Ojo con esto: **endurecer un umbral puede abrir incidencias
  de jornadas ya pasadas** que caigan dentro de esa ventana. No es un error, es la
  ventana haciendo su trabajo.
- **No se recalcula el histórico.** Una jornada de hace tres meses no se vuelve a
  evaluar.
- **No se cierra ninguna incidencia ya abierta** ni se reabre ninguna resuelta.
  Cerrarlas automáticamente borraría el rastro de una decisión que tomó una
  persona.
- **El cambio queda auditado** con el valor anterior, el nuevo, quién lo hizo y
  cuándo. Es lo que permite explicar dentro de dos años por qué una jornada de
  marzo no generó alerta y una de abril sí.

Consecuencia práctica: **si bajas un umbral, las incidencias que ya estaban
abiertas siguen ahí y hay que cerrarlas a mano** indicando el motivo. No es un
fallo: es la única forma de que el registro conserve lo que ocurrió.

#### `retention_years` es el único campo peligroso

Bajarlo amplía lo que la purga considera vencido, sobre datos que **estás
obligado a conservar cuatro años**. Nada se borra por cambiarlo: la purga se
ejecuta a mano, propone primero en simulación y exige una confirmación derivada
de ese informe. Aun así, es el único campo del perfil cuyo error se paga con
datos que no vuelven. Si tu asesoría te dice que tu plazo es otro, cámbialo; si
no, no lo toques.

### 2.5 Salida a nómina

**Para qué sirve.** La salida a nómina es un fichero con **horas por persona y
periodo** que se le entrega al programa de nómina del hotel. **Exporta horas, no
importes**: aquí no se calculan salarios, pluses, complementos ni cotizaciones.
Eso lo hace tu programa de nómina; esto le da los números de partida.

**Dónde se cambia.** Panel → **Ajustes operativos** (`/settings`), en el bloque
de la salida a nómina, con cuenta de **administrador de instalación**. Son seis
claves de base de datos, **no** variables del `.env`: se guardan desde el panel,
surten efecto
en la petición siguiente, no exigen reiniciar nada y quedan auditadas como
cualquier otro ajuste. Quien genera el fichero después —RRHH y administración—
no necesita tocarlas: se configuran una vez, al implantar.

| Clave | De serie | Valores | Qué pasa si la cambias |
| --- | --- | --- | --- |
| `PAYROLL_EXPORT_COLUMNS` | `employee_code`, `last_name`, `first_name`, `department`, `period_from`, `period_to`, `worked_hours`, `contracted_hours`, `overtime_hours`, `absence_days` | Una lista de identificadores del catálogo de abajo, en el orden en que quieres las columnas. Cada entrada es `id` o `id=Etiqueta` | Cambia **qué columnas lleva el fichero y en qué orden**. Un identificador que no esté en el catálogo se rechaza al guardar (código 422) diciendo cuál falla. No cambia ni una hora del registro |
| `PAYROLL_EXPORT_DELIMITER` | `semicolon` | `semicolon`, `comma`, `tab` | Separador de campos del CSV. `semicolon` de serie porque es lo que espera Excel en configuración regional española y lo que piden la mayoría de los programas de nómina de aquí |
| `PAYROLL_EXPORT_HOURS_FORMAT` | `hhmm` | `hhmm`, `decimal_dot`, `decimal_comma` | Cómo se escriben las horas: `168:30`, `168.50` o `168,50`. Lee el aviso de más abajo antes de tocarlo |
| `PAYROLL_EXPORT_DATE_FORMAT` | `iso` | `iso`, `dmy` | Cómo se escriben las fechas: `2026-09-30` o `30/09/2026` |
| `PAYROLL_EXPORT_ENCODING` | `utf8_bom` | `utf8_bom`, `utf8`, `latin1` | Codificación del fichero. `utf8_bom` es UTF-8 con marca de orden, que es lo que hace que Excel abra las tildes bien sin preguntar nada |
| `PAYROLL_EXPORT_HEADER_ROW` | `enabled` | `enabled`, `disabled` | Si la primera línea lleva los nombres de las columnas. Ponlo en `disabled` solo si tu programa de nómina importa por posición y se atraganta con la cabecera |

#### El catálogo de columnas, una por una

Estas son **todas** las columnas que se pueden pedir. No hay más: añadir una
nueva es cambiar el producto, no la configuración.

| Identificador | Rótulo de serie | Qué lleva | De dónde sale |
| --- | --- | --- | --- |
| `employee_code` | Código de empleado | El código de empleado: el mismo con el que esa persona entra al portal | La ficha de la persona |
| `employee_uuid` | Identificador | Identificador interno y estable de la persona, sin ningún dato personal dentro. Útil si tu programa de nómina cruza por un identificador que no cambia nunca | La ficha de la persona |
| `last_name` | Apellidos | Apellidos | La ficha de la persona |
| `first_name` | Nombre | Nombre | La ficha de la persona |
| `full_name` | Nombre completo | Nombre y apellidos en una sola celda | La ficha de la persona |
| `department` | Departamento | Departamento **actual** de la persona, no el que tuviera durante el periodo | La ficha de la persona |
| `period_from` | Desde | Primer día del tramo que describe la fila | El periodo que se pidió, partido por la granularidad elegida |
| `period_to` | Hasta | Último día del tramo que describe la fila | Íd. |
| `days_in_period` | Días del periodo | Días naturales que abarca la fila | Íd. |
| `days_with_activity` | Días con actividad | Días con al menos una jornada registrada | La proyección de jornadas |
| `shift_count` | Tramos | Número de tramos registrados en la fila | La proyección de jornadas |
| `worked_hours` | Horas trabajadas | Horas efectivamente trabajadas | **La proyección de jornadas**: los tramos vigentes ya cerrados, con las correcciones aplicadas. Es el mismo número que el informe de horas por periodo |
| `contracted_hours` | Horas contratadas | Horas que correspondían según contrato | **El contrato vigente cada día** del tramo. Los días sin contrato registrado no suman |
| `deviation_hours` | Desviación | Trabajadas menos contratadas. Puede ser negativa | Cálculo de las dos anteriores |
| `overtime_hours` | Exceso de jornada | Solo la parte positiva de la desviación | Íd. |
| `absence_days` | Días de ausencia | Días de ausencia registrada, del tipo que sea | **El registro de ausencias** |
| `holiday_days` | Festivos | Días del tramo que son festivo del centro | El calendario de festivos del perfil de cumplimiento (sección 2.4) |
| `unjustified_absence_days` | Absentismo no justificado | Días sin actividad, sin ausencia registrada y sin festivo | Los tres anteriores, restados |
| `days_without_contract` | Días sin contrato | Días del tramo sin contrato registrado. **Si esta cifra no es cero, la de contratadas y la de desviación de esa fila están incompletas** | El contrato vigente cada día |
| `time_zone` | Zona horaria | Zona horaria del centro con la que se han calculado las horas | El centro |

**El rótulo de serie va en el idioma de la instalación** (`LOCALE_DEFAULT`,
sección 2.3): en una instalación en inglés, la primera columna se rotula
`Employee code`. Si tu programa de nómina importa por nombre de columna, no
dejes eso al azar: ponle tú la etiqueta, como se explica justo debajo. Una
etiqueta propia **no se traduce nunca**, precisamente para que cambiar el idioma
del panel no rompa una importación que ya funcionaba.

#### Ponerle a una columna el nombre que tu programa de nómina espera

Basta con escribir la etiqueta detrás de un igual (hasta 60 caracteres). El
identificador sigue siendo el del catálogo; lo que cambia es lo que se imprime
en la fila de cabecera:

```text
worked_hours=Horas
```

Una lista completa con etiquetas propias se escribe así, una entrada por línea
en el editor del panel:

```text
employee_code=Código
last_name=Apellidos
first_name=Nombre
period_from=Desde
period_to=Hasta
worked_hours=Horas
contracted_hours=Horas contrato
overtime_hours=Extra
```

#### Dos ejemplos de fichero

**Con la plantilla de serie** —las diez columnas de arriba, punto y coma, horas
`HH:MM`, fechas ISO y cabecera con el rótulo de serie de cada columna, en una
instalación en español—:

```text
Código de empleado;Apellidos;Nombre;Departamento;Desde;Hasta;Horas trabajadas;Horas contratadas;Exceso de jornada;Días de ausencia
E-0142;García Ruiz;Marta;Recepción;2026-09-01;2026-09-30;168:30;160:00;8:30;2
E-0207;Novoa Prado;Iván;Cocina;2026-09-01;2026-09-30;152:15;160:00;0:00;5
```

**Con horas decimales y coma decimal**, etiquetas propias y fechas
`dd/mm/aaaa` —`PAYROLL_EXPORT_HOURS_FORMAT` en `decimal_comma`,
`PAYROLL_EXPORT_DATE_FORMAT` en `dmy` y el separador donde estaba—:

```text
Código;Apellidos;Nombre;Desde;Hasta;Horas;Horas contrato;Extra
E-0142;García Ruiz;Marta;01/09/2026;30/09/2026;168,50;160,00;8,50
E-0207;Novoa Prado;Iván;01/09/2026;30/09/2026;152,25;160,00;0,00
```

> **Coma decimal y coma como separador no se llevan.** Con las horas en
> `decimal_comma`, deja el separador en `semicolon` o en `tab`: con `comma`,
> cada hora partiría la fila en dos columnas. El sistema te deja guardar esa
> combinación —no puede saber qué espera tu programa de nómina— pero el fichero
> saldrá ilegible.

#### El formato decimal existe para la máquina, no para las personas

En todo lo demás, este producto escribe las duraciones en `HH:MM` y nunca en
decimal, porque `7,75` se lee mal y se discute peor delante de quien reclama sus
horas. La salida a nómina es la única excepción, y tiene un motivo concreto:
**este fichero lo lee un programa**, y muchos programas de nómina solo aceptan
horas decimales.

Consecuencia práctica: **pon `decimal_dot` o `decimal_comma` solo si tu programa
de nómina lo exige.** Para cualquier fichero que vaya a leer una persona
—informe de horas por periodo, exportación para la Inspección, portal del
empleado— las horas siguen siendo `HH:MM`, y eso no se configura. El formato
elegido queda anotado en la propia exportación y en la auditoría, así que dentro
de un año se puede saber con qué convenio de escritura salió un número.

#### Qué pasa con `latin1` y los caracteres que no existen en él

`latin1` (ISO-8859-1) está para los programas de nómina antiguos que no entienden
UTF-8. Las tildes y las eñes caben en él sin problema, pero no todo lo que puede
aparecer en un nombre: una **ř**, una **ł**, una **ș** o un guion tipográfico no
existen en `latin1`. **El fichero se genera igual, nunca falla y nunca se queda a
medias**, en dos escalones: primero se transcribe al carácter latino más parecido
(la **ř** sale como `r`) y, lo que no tiene transcripción posible, se sustituye
por `?`. Una nómina detenida por una letra es peor que un apellido con un
interrogante.

Consecuencia práctica: con plantilla internacional, `latin1` **deforma
apellidos**. Si tu programa de nómina cruza por nombre y no por código, eso se
va a notar; si cruza por `employee_code` —que es lo recomendable— no pasa nada.
Antes de dejarlo fijo, genera un fichero de prueba con un periodo corto y ábrelo.

---

## 3. Cómo se cambia

Desde el panel, con una cuenta de **administrador de instalación**. RRHH,
responsables y auditores no llegan a esta pantalla, y eso es deliberado: corregir
un fichaje deja traza sobre una jornada, mover el anti-rebote cambia el cálculo
de todas las siguientes.

Desde consola, cuando no hay panel a mano —por ejemplo, durante la puesta en
marcha—:

```bash
curl -sS -X PATCH https://TU-SERVIDOR/api/v1/settings \
  -H 'Authorization: Bearer TU-TOKEN' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"settings":{"ATTENDANCE_MIN_TRANSIT_SECONDS":300,"BRANDING_APP_NAME":"Hotel Marina"}}'
```

Y para ver lo que hay ahora mismo, con el origen de cada valor:

```bash
curl -sS https://TU-SERVIDOR/api/v1/settings \
  -H 'Authorization: Bearer TU-TOKEN' \
  -H 'Accept: application/json'
```

La respuesta trae **todas** las claves, no solo las que hayas cambiado. Cada una
lleva un campo `source`:

- `installation` — lo has configurado tú.
- `product_default` — nadie lo ha tocado y rige el valor de serie.

El perfil de cumplimiento tiene su propia dirección, con la misma cuenta:

```bash
curl -sS https://TU-SERVIDOR/api/v1/compliance-profile \
  -H 'Authorization: Bearer TU-TOKEN' \
  -H 'Accept: application/json'
```

```bash
curl -sS -X PATCH https://TU-SERVIDOR/api/v1/compliance-profile \
  -H 'Authorization: Bearer TU-TOKEN' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"max_daily_hours":8,"break_required_after_hours":5,"name":"Convenio de hostelería de Cantabria"}'
```

Manda solo los campos que quieras cambiar: los que no viajen se quedan como
están. Los números van **sin comillas** (`8`, no `"8"`).

---

## 3 bis. La licencia

> **Lo primero, porque es lo que más se pregunta: la licencia NO puede impedir
> fichar.** Con la licencia caducada, ausente o ilegible, tu instalación sigue
> registrando fichajes, sigue dejando consultar el registro de cualquier persona,
> sigue exportando para la Inspección de Trabajo, sigue sirviendo el portal del
> empleado, sigue permitiendo corregir jornadas y sigue haciendo copias de
> seguridad. **Eso no es una casualidad de esta versión: es una promesa del
> producto**, y está escrita en su documentación de diseño.
>
> Lo único que una licencia gobierna son las **funcionalidades accesorias**, y
> están enumeradas más abajo.

### 3 bis.1 Qué es la clave de licencia

Una cadena de texto que te entrega tu proveedor. Tiene esta forma:

```text
KQL1.eyJsaWNlbnNlX2lkIjoiOWYyYzRhMWI3ZTBk....Zm9vYmFyYmF6cXV1eA
```

Dentro lleva, **firmados**, el nombre de tu empresa, tu plan, los límites
contratados, las funcionalidades incluidas y las fechas de vigencia. La firma es
lo que impide que se modifique: si alguien cambia un solo carácter, la clave deja
de valer.

**Se verifica en tu propio servidor y sin conexión a internet.** El sistema no
llama a ningún servidor del fabricante, ni al activarla ni después. Es
deliberado: tu instalación tiene que poder funcionar en una red aislada, y una
comprobación en línea convertiría la conectividad de otra empresa en un punto de
fallo de tu registro horario.

### 3 bis.2 Cómo se activa

**Desde el panel** (lo normal): entra como *administrador*, ve a **Licencia**,
pega la clave en el recuadro y pulsa «Activar una clave». Puedes pegarla con
espacios o saltos de línea: se limpian solos.

**Desde la consola del servidor**, si prefieres:

```bash
docker compose exec app php artisan license:activate "KQL1...."
```

Y para ver cómo está en cualquier momento:

```bash
docker compose exec app php artisan license:show
```

Ese comando imprime, en este orden: el estado, tu plan frente a lo que estás
usando de verdad, qué está degradado, **qué sigue funcionando pase lo que pase**
y qué hacer. Es el que te pedirá soporte si llamas.

> **La clave completa no aparece nunca** en la salida del comando ni en el panel:
> se enseña su *huella*, doce caracteres, que es lo que sirve para confirmar por
> teléfono que la clave activada es la que te enviaron.

### 3 bis.3 Qué pasa cuando caduca

**Treinta días antes** aparece un aviso permanente en el panel, para los roles de
administración, diciendo cuándo caduca y qué se degradará. **Durante esos treinta
días no se pierde nada**: la licencia sigue vigente.

**El día que caduca**, el aviso cambia de tono y de texto, y estas
funcionalidades dejan de estar disponibles:

| Deja de funcionar | Sigue funcionando en su lugar |
| --- | --- |
| **Informes por periodo** y su comparativa con las horas contratadas | La consulta del registro de cada persona, la exportación para la Inspección y el portal del empleado |
| **La salida a nómina** (sección 2.5), tanto la descarga inmediata como la generación en segundo plano | La consulta del registro de cada persona y la exportación para la Inspección, que no se degradan nunca |
| **Presencia en tiempo real**: pasa a **actualizarse por sondeo**, no se apaga. La pantalla sigue enseñando quién está dentro, con unos segundos de retraso, y lo dice | — |

Y estas **nunca** se ven afectadas, con la licencia como esté:

- Fichaje por QR y por PIN de respaldo.
- Sincronización de la cola del quiosco cuando recupera la red.
- Consulta de jornadas y tramos de cualquier persona.
- Portal del empleado.
- Exportación normalizada para la Inspección de Trabajo.
- Correcciones de jornada con su motivo.
- Registro de auditoría.
- Copias de seguridad y su restauración.
- Sondas de salud.

El aviso **no se puede cerrar** mientras la situación siga siendo cierta. Es a
propósito: un aviso que se descarta el primer día deja de avisar justo el día que
importa.

### 3 bis.4 Los límites del plan

Tu clave lleva dos cifras: **cuántas personas** y **cuántos quioscos** has
contratado. `license:show` y la pantalla de licencia enseñan las dos frente a lo
que estás usando de verdad.

> **Superarlas no bloquea nada, y no lo hará nunca.** Puedes dar de alta a la
> persona número 81 con un plan de 80, y puede fichar desde el primer día.
> Puedes emparejar un quiosco de sustitución aunque el averiado siga contando.
>
> El motivo es simple: si el producto te impidiera dar de alta a un camarero en
> plena temporada, esa persona trabajaría **sin registro horario**, y la
> infracción del art. 34.9 ET sería tuya por una decisión comercial que no
> controlas. Y si te impidiera emparejar un quiosco, te quedarías sin punto de
> fichaje justo el día que se rompe uno.

Lo que sí ocurre al superar una cifra:

1. Aparece un aviso permanente en el panel con lo contratado, lo real y desde
   cuándo.
2. Queda una entrada en el **registro de auditoría** con la fecha exacta. Es el
   apunte con el que tu proveedor te planteará ampliar el plan, y también el que
   te permite comprobar tú mismo desde cuándo estás por encima.
3. Las cifras salen en `license:show`.

**Las personas dadas de baja no cuentan**, aunque su registro se conserve los
cuatro años obligatorios. Un quiosco revocado libera su plaza en el acto.

### 3 bis.5 Consultarla por API

```bash
curl -sS https://TU-SERVIDOR/api/v1/license \
  -H "Authorization: Bearer $TOKEN" | jq
```

```bash
curl -sS -X POST https://TU-SERVIDOR/api/v1/license/activate \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"signed_key":"KQL1...."}' | jq
```

Las dos exigen el rol *administrador*. **Ninguna de las dos se cierra por una
licencia caducada**: son justamente la pantalla desde la que se arregla el
problema.


## 3 ter. La carga de plantilla desde un fichero

Es el paso del asistente que evita teclear a mano la plantilla entera, y también
la vía para incorporar un grupo grande más adelante. Funciona con **CSV y con
XLSX**.

### 3 ter.1 Cómo se hace: dos pasos, y el segundo no ocurre sin que lo confirmes

1. **Subes el fichero en modo comprobación.** No se escribe nada. Recibes un
   informe **línea a línea**: qué se daría de alta, qué se actualizaría, qué se
   rechaza y **por qué**.
2. **Revisas el informe y confirmas.** Solo entonces se escribe, y se escribe
   **exactamente lo que revisaste**: el sistema comprueba que el fichero es el
   mismo antes de tocar nada.

> **Por qué hay que volver a subir el fichero al confirmar.** Porque el sistema
> **no lo guarda** entre los dos pasos. Ese fichero lleva nombres y documentos de
> identidad de toda tu plantilla, y dejarlo en el disco del servidor esperando a
> que alguien confirme sería un montón de datos personales sin dueño. Son unos
> pocos kilobytes: se vuelve a subir y ya está.

Desde consola, si prefieres:

```bash
# 1. Comprobar (no escribe nada). Guarda el sha256 que devuelve.
curl -sS -X POST https://TU-SERVIDOR/api/v1/employees/import \
  -H "Authorization: Bearer TU-TOKEN" \
  -F "mode=validate" \
  -F "file=@plantilla.csv"

# 2. Aplicar, confirmando con esa huella.
curl -sS -X POST https://TU-SERVIDOR/api/v1/employees/import \
  -H "Authorization: Bearer TU-TOKEN" \
  -F "mode=apply" \
  -F "confirm_checksum=EL-SHA256-DEL-PASO-1" \
  -F "file=@plantilla.csv"
```

### 3 ter.2 Qué columnas tiene que traer el fichero

La primera fila son los **nombres de las columnas**. El orden da igual y sobrar
columnas no molesta: las que no se usan se avisan y se ignoran.

| Campo | Obligatorio | Nombres que se reconocen de serie |
| --- | --- | --- |
| Nombre | **Sí** | `nombre`, `first_name`, `firstname`, `given_name` |
| Apellidos | **Sí** | `apellidos`, `apellido`, `last_name`, `lastname`, `surname`, `family_name` |
| Fecha de alta | **Sí** | `fecha_alta`, `fecha_de_alta`, `alta`, `hired_at`, `start_date`, `hire_date` |
| Documento de identidad | **Uno de los dos** | `dni`, `nif`, `nie`, `documento`, `national_id`, `id_number` |
| Correo | **Uno de los dos** | `email`, `correo`, `correo_electronico`, `e_mail` |
| Departamento | No | `departamento`, `department`, `seccion`, `section` |
| Idioma | No | `idioma`, `locale`, `language` |

**Los nombres se comparan sin distinguir mayúsculas, tildes ni separadores**:
`Fecha de alta`, `FECHA_ALTA` y `fecha alta` son la misma columna.

> **Documento o correo, al menos uno.** No es un capricho: es lo que permite
> **volver a importar el mismo fichero sin duplicar a nadie**. Si una línea no
> trae ninguno de los dos, el sistema no tendría forma de reconocer a esa persona
> la segunda vez y acabarías con dos fichas suyas. El **correo sigue siendo
> opcional** —el fichero puede no traer esa columna y funciona igual—; lo que no
> puede faltar son **los dos a la vez**.

> **El código de empleado NO se lee del fichero**, aunque lo incluyas. Lo genera
> el sistema y es un código opaco a propósito: va impreso en la tarjeta y en el
> QR, así que no puede ser el número de nómina ni nada que tenga significado.

### 3 ter.3 El formato del fichero: no hay nada que configurar

- **El separador se detecta.** Un Excel en español exporta con `;` y uno en
  inglés con `,`. Los dos funcionan, y el tabulador también.
- **La codificación se detecta.** «Guardar como CSV» en un Windows en español
  produce `Windows-1252`, no UTF-8. Los dos funcionan, y las **ñ** y las tildes
  llegan bien.
- **Las fechas** se aceptan como `2026-03-15` o como `15/03/2026`. **No se acepta
  mes/día/año**: `03/04/2026` se lee siempre como **3 de abril**, que es lo que
  quiso escribir quien lo escribió. Una fecha de alta leída con un mes de
  diferencia es un mes de jornadas que no deberían existir.

### 3 ter.4 Volver a importar el mismo fichero es seguro

Es lo que pasa siempre: se corrige una línea y se vuelve a subir el fichero
entero. El sistema reconoce a cada persona por su documento (y si no lo hay, por
su correo) y:

- **no la duplica**;
- **actualiza** nombre, apellidos, correo, departamento e idioma si cambiaron;
- **no toca su fecha de alta**, aunque el fichero traiga otra. Lo dice como aviso
  en la línea. Cambiar la fecha de alta de alguien mueve el punto desde el que
  cuenta la conservación legal de su registro y desde el que se le pueden imputar
  jornadas: eso se cambia en su ficha, a conciencia, no de pasada en una
  importación de cuarenta líneas.
- **no da de alta ni de baja a nadie por cambiar de estado**. La baja tiene su
  propio procedimiento, con fecha de cese y revocación de la tarjeta.

### 3 ter.5 Después de importar quedan las tarjetas

**Importar cuarenta personas no emite ni una tarjeta.** La credencial es una
tarjeta física impresa: hay que emitirla, imprimirla y entregarla, y eso lleva
días. **Nadie recibe nada por correo electrónico**, ni hace falta que tenga
correo.

Lo que hay que hacer después está en el **panel de estado de credenciales**, y
desde consola:

```bash
docker compose exec app php artisan credentials:status --pending
```

> **Hazlo con días de antelación al primer día de trabajo.** El panel de estado
> de credenciales existe precisamente para que nadie descubra el problema delante
> de la tablet a las 06:00.

### 3 ter.6 Si tu fichero usa otros nombres de columna

No hace falta tocar el programa: se añaden alias en el `.env` del servidor, en
formato `campo=cabecera`, separados por `;`.

```bash
# .env — la exportación de tu sistema anterior llama "documento_id" al DNI
# y "seccion_hotel" al departamento.
WORKFORCE_IMPORT_COLUMN_ALIASES="national_id=documento_id;department=seccion_hotel"
```

**Se suman a los de serie, no los sustituyen**: el fichero de la semana que viene
puede venir del otro sistema y seguirá funcionando. Los campos que admiten alias
son `first_name`, `last_name`, `email`, `national_id`, `department`, `hired_at` y
`locale`. Una entrada mal escrita se ignora y su columna sale como «no
reconocida» en el informe; no rompe la importación.

Requiere **reiniciar los contenedores**, como todo lo del `.env`.

### 3 ter.7 Los otros dos parámetros del `.env`

| Variable | De serie | Para qué |
| --- | --- | --- |
| `WORKFORCE_IMPORT_MAX_ROWS` | `500` | Líneas de datos como máximo por fichero. Es la plantilla más grande que soporta una instalación. Si te pasas, el informe lo dice y **no se importa nada**: se parte el fichero en dos y se importan uno detrás de otro. |
| `WORKFORCE_IMPORT_MAX_FILE_KILOBYTES` | `4096` | Tamaño máximo del fichero. Un XLSX de 500 personas ronda los 60 KB, así que sobra de largo. |

**Por qué 500 y no más.** Cada alta calcula la huella criptográfica del PIN de esa
persona, que cuesta del orden de 0,16 s. Con 500 personas son unos 80 s de
cálculo, que el sistema hace **antes** de tocar la base de datos precisamente para
que no bloquee los fichajes del quiosco mientras tanto. Por encima de esa cifra la
petición se acercaría al minuto de límite del servidor web y se cortaría a medias.
Puedes subirlo si tu plantilla es mayor, pero **parte el fichero antes de
hacerlo**: es más rápido y no depende de ningún límite.

---

## 3 quater. Diagnóstico y soporte

Lo que hay que saber para abrir una incidencia con soporte —la revisión de
salud `product:doctor`, el paquete de diagnóstico anonimizado y los accesos
temporales al fabricante— está en [`operacion.md`](operacion.md) §12, porque
es operación, no configuración. Aquí solo van sus **parámetros**, que viven en
el `.env` y **no** se editan desde el panel a propósito: si el máximo de horas
de un acceso fuera una clave del panel, quien concede el acceso podría subirlo
antes de concederlo y el límite dejaría de serlo.

| Variable | De serie | Qué gobierna |
| --- | --- | --- |
| `PRODUCT_DIAGNOSTICS_MAX_BYTES` | `8388608` | Tamaño máximo del paquete de diagnóstico (8 MiB). Es un límite de canal —correo, portal de tickets—, no de memoria |
| `PRODUCT_DIAGNOSTICS_RATE_LIMIT` | `3` | Paquetes por minuto y por cuenta desde el panel |
| `PRODUCT_DIAGNOSTICS_PERSONAL_DATA_MAX_PERIOD_DAYS` | `31` | Máximo de días de fichajes que caben en un paquete **con datos personales**. Subirlo es una decisión legal, no de rendimiento |
| `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` | `7` | Días que un paquete generado por consola se conserva en el servidor antes de que el siguiente `product:diagnostics` lo borre |
| `PRODUCT_SUPPORT_GRANT_DEFAULT_HOURS` | `24` | Duración de un acceso de soporte si no se indica |
| `PRODUCT_SUPPORT_GRANT_MAX_HOURS` | `72` | Duración máxima admitida |
| `PRODUCT_SUPPORT_USE_AUDIT_WINDOW_SECONDS` | `900` | Cada cuánto, como máximo, se anota un nuevo uso de un acceso de soporte en la auditoría |
| `PRODUCT_DATA_EXPORT_PATH` | `storage/app/exports` (en el contenedor) | Dónde deja el ZIP la exportación íntegra ([`operacion.md`](operacion.md) §13). Fuera de `BACKUP_PATH` a propósito: es material que caduca |
| `PRODUCT_DATA_EXPORT_RETENTION_DAYS` | `7` | Días que el ZIP de una exportación íntegra se puede descargar antes de que se purgue. La anotación de que existió se conserva siempre |
| `PRODUCT_DATA_EXPORT_RATE_LIMIT` | `30` | Peticiones por minuto **por cuenta** a la lista y a la descarga de exportaciones (por dirección IP, cuatro veces más); el panel sondea cada 5 s mientras hay una en curso |
| `PRODUCT_DATA_EXPORT_STALE_AFTER` | `3600` | Segundos tras los que una exportación interrumpida a medias se da por fallida (`stale`) y deja pedir otra. Nunca por debajo de lo que tarda tu exportación más grande |

Lo que **sí** decides cada vez, y no en el `.env`: si el paquete lleva datos
personales (nunca por defecto), y el motivo, el alcance y las horas de cada
acceso.

---

## 3 quinquies. Telemetría

### Qué es, y por qué probablemente no te haga falta

La telemetría es un documento JSON con datos **técnicos y agregados** de tu
instalación —qué versión corre, cómo está la licencia, de qué tamaño es la
plantilla por tramos, unos contadores de uso y el resultado de la revisión de
salud— que tu servidor envía al fabricante una vez por semana.

**Viene apagada, y así se queda si no haces nada.** El producto funciona
**exactamente igual** con ella apagada: no se degrada ninguna función, no
aparece ningún aviso, no hay banner y nadie te lo va a recordar. Si esta sección
te parece innecesaria, no hagas nada: no estás perdiéndote nada.

Sirve para una sola cosa: que quien mantiene el producto sepa qué versiones
están en uso de verdad y con qué tamaños de instalación, para no romperlas en
una actualización. No sirve para facturarte, ni para vigilar a nadie, ni para
saber quién ficha.

### Hacen falta tres cosas a la vez

Con que falte una, no se construye ni se envía nada —ni siquiera se lee un
contador—, y `product:telemetry` te dice cuál falta:

1. `TELEMETRY_ENABLED=true` en tu `.env`. De serie está en `false`.
2. `TELEMETRY_ENDPOINT` con una dirección **que empiece por `https://`**. De
   serie está **vacío**: el producto no trae ningún destino escrito, ni siquiera
   uno del fabricante. Con esto vacío, activar lo anterior no hace absolutamente
   nada. Un destino en `http://` —o sin `https://` delante— **se rechaza como si
   no hubiera ninguno**: no se construye ni se envía nada, y `product:telemetry`
   te dice que la dirección tiene que empezar por `https://`.
3. Que tu licencia incluya `telemetry`. Es una función accesoria: si tu licencia
   caduca, se apaga sola y no pasa nada más.

### Cada cuánto, y a dónde

Los **lunes a las 05:40 UTC**, un `POST` con `Content-Type: application/json`
sobre HTTPS con el certificado verificado, sin seguir redirecciones, con 3
segundos para conectar y 10 en total. Si falla, se reintenta **una vez** a los 5
segundos y se deja para la semana siguiente: no hay reintentos en bucle, no
aparece ningún error en pantalla y en el registro técnico queda como aviso, no
como fallo. **Nunca ocurre durante un fichaje**: solo lo dispara la tarea
programada.

El destino lo eliges tú. Si no quieres enviar nada al fabricante pero sí quieres
llevar el dato a tu propio sistema de supervisión, apunta `TELEMETRY_ENDPOINT` a
donde quieras: al producto le da igual quién esté al otro lado.

**Y ahí está el reparto de responsabilidades**, dicho sin rodeos: el producto
garantiza el **canal** —TLS verificado, sin seguir redirecciones, tiempos de
espera acotados— y el **contenido**, que es exactamente el de la tabla de abajo.
De **quién está al otro lado del destino respondes tú**, porque la dirección la
escribes tú: si apuntas a un servidor que no controlas, el documento llega a
quien tenga ese servidor. Está anotado como riesgo aceptado en el documento de
seguridad del producto (doc 07 §6).

### Míralo antes de decidir

Este comando **no envía nada**. Imprime el documento exacto que se enviaría,
con los valores reales de tu instalación:

```bash
docker compose exec app php artisan product:telemetry
```

Con `--send` lo envía, si se cumplen las tres condiciones. Con `--json` sale en
un formato que puede leer otro programa.

**Mirar no deja rastro.** Sin `--send`, el comando no envía nada y tampoco
escribe nada en el disco: el identificador que enseña es provisional y lo dice.
El definitivo se acuña en el primer envío.

### Variables

| Variable | De serie | Qué gobierna |
| --- | --- | --- |
| `TELEMETRY_ENABLED` | `false` | Si la telemetría está activada |
| `TELEMETRY_ENDPOINT` | *(vacío)* | A dónde se envía. Tiene que empezar por `https://`; vacío, o sin `https://`, significa que no se envía |
| `TELEMETRY_STATE_PATH` | `storage/app/telemetry/state.json` | Dónde vive el identificador de la instalación y el historial de envíos |
| `TELEMETRY_RETRY_DELAY_SECONDS` | `5` | Segundos entre el intento y su único reintento |

### Todo lo que se envía, campo a campo

Esta es la lista **completa y cerrada**. Lo que no está en esta tabla no sale de
tu instalación, y una prueba automática compara esta tabla con el código: si
alguien añadiera un campo sin escribirlo aquí, la comprobación falla y el cambio
no entra.

| Campo | Ejemplo | Qué es |
| --- | --- | --- |
| `schema_version` | `1` | Versión del formato del documento. Sube si algún día cambia la lista de campos |
| `installation_id` | `9f2c7b41-0f6a-4a1e-9d54-6b0f3a2c81de` | Identificador **aleatorio** que genera tu propia instalación la primera vez. No sale de tu licencia, ni de tu nombre, ni de tu dirección. Si borras el fichero de estado, se estrena otro |
| `sent_at` | `2026-09-08T05:40:12.004311Z` | Momento del envío, en UTC |
| `product.version` | `2.1.0` | Versión de KronoQR que corre en tu servidor |
| `product.php_version` | `8.4.24` | Versión de PHP |
| `product.database_version` | `17.11` | Versión de PostgreSQL, solo el número. Sin la distribución, sin el compilador y sin ninguna ruta de tu servidor |
| `license.state` | `active` | Estado de la licencia: `active`, `expiring`, `expired`, `absent`, `not_yet_valid` o `unverifiable` |
| `license.plan` | `estandar` | Nombre del plan contratado |
| `license.features` | `["advanced_reports","telemetry"]` | Funciones accesorias incluidas en el plan |
| `license.days_until_expiry` | `114` | Días que faltan para que caduque |
| `scale.employees_active` | `101-250` | Tamaño de la plantilla activa **por tramos**: `0`, `1-25`, `26-100`, `101-250`, `251-500` o `501+`. **Nunca la cifra exacta** |
| `scale.devices_active` | `3` | Cuántas tablets hay dadas de alta |
| `scale.departments` | `6` | Cuántos departamentos hay creados |
| `usage_7d.scans_accepted` | `4812` | Fichajes aceptados desde el envío anterior (normalmente, una semana). Solo el número: sin quién, sin cuándo y sin en qué tablet |
| `usage_7d.scans_rejected` | `37` | Escaneos rechazados en ese mismo periodo. Solo el número, y **sin el motivo del rechazo** |
| `usage_7d.batches_synced` | `1204` | Lotes que las tablets sincronizaron al recuperar la red, en ese periodo |
| `usage_7d.incidents_open` | `2` | Incidencias abiertas **ahora mismo**. Es la única cifra de este bloque que no es del periodo, porque es un nivel y no un recuento |
| `usage_7d.reports_generated` | `9` | Informes exportados en el periodo |
| `doctor` | `{"database.connection":"ok","license.state":"warning"}` | El veredicto de cada comprobación de `product:doctor`: `ok`, `warning` o `failure`. **Solo el veredicto**: sin el texto, sin la explicación y sin los detalles, que sí pueden llevar rutas de tu servidor y van únicamente en el paquete de diagnóstico que tú decides enviar |

En el primer envío, y en cualquiera que siga a una semana fallida sin
comparación posible, los cuatro contadores del bloque `usage_7d` van vacíos
(`null`). Es a propósito: un `0` afirmaría que no hubo ni un fichaje en toda la
semana, y eso sería falso.

### Lo que NUNCA se envía

Ni activando la telemetría, ni con ninguna combinación de opciones:

- Nombres, apellidos ni ningún dato de una persona de la plantilla.
- Direcciones de correo, códigos de empleado, PIN, DNI ni sus huellas.
- Horas de fichaje, jornadas, tramos, totales diarios ni correcciones.
- El identificador (`uuid`) de ninguna persona, tablet, tramo ni usuario.
- La razón social de tu licencia, su `license_id` ni la huella de tu clave.
- La URL de tu instalación (`APP_URL`), el nombre de tu hotel, el de tus
  centros, el de tus departamentos ni el de tus tablets.
- Rutas de tu servidor, nombres de fichero, contraseñas ni ninguna clave.
- Cualquier cosa que no esté en la tabla de arriba.

### Cómo apagarla, y cómo estrenar identidad

Para apagarla, `TELEMETRY_ENABLED=false` y reiniciar la aplicación. No hace
falta nada más y no hay que avisar a nadie.

Si quieres que el fabricante deje de poder relacionar los envíos anteriores con
los siguientes, borra el fichero de estado:

```bash
docker compose exec app rm -f storage/app/telemetry/state.json
```

El siguiente envío estrena un identificador nuevo y sin relación con el
anterior, y los contadores vuelven a empezar.

---

## 4. Qué hacer si…

### …guardo un cambio y responde «no válido» (código 422)

La respuesta dice **qué clave** falla y por qué, en el campo `errors`. Los casos
que se dan de verdad:

| Mensaje | Qué ha pasado | Qué hacer |
| --- | --- | --- |
| «La clave … no existe en esta instalación» | Un nombre mal escrito, o una clave de una versión distinta | Compara con la tabla de la sección 2. El catálogo es cerrado a propósito: una clave que se guardara y no la leyera nadie sería peor que un error |
| «… admite de X a Y» | El valor está fuera de rango | Usa un valor del rango. Los límites están en la tabla |
| «… debe ser un número entero, sin comillas» | Has enviado `"12"` en lugar de `12` | Quita las comillas. Con ellas, el umbral aplicado no sería el que crees |
| «El idioma por defecto … no está entre los idiomas disponibles» | Has quitado de la lista el idioma que está por defecto | Envía las dos claves en la misma petición |

### …guardo `BRANDING_LOGO_PATH` y responde 422

El fichero se comprueba **contra el disco al guardar**, así que el error te lo
encuentras en el momento y no cuando alguien imprime una tarjeta. Cada mensaje
dice qué hacer:

| Mensaje | Qué ha pasado | Qué hacer |
| --- | --- | --- |
| «La ruta del logotipo tiene que ser absoluta y empezar por `/`» | Has escrito una ruta relativa | Escribe la ruta completa: `/var/kronoqr/branding/logo.png` |
| «La ruta del logotipo no puede contener `..`» | La ruta sube de directorio | Escríbela sin saltos, tal cual queda dentro del directorio de marca |
| «El logotipo tiene que estar dentro del directorio de marca» | El fichero está en otro sitio del servidor | Cópialo a la carpeta de `BRANDING_PATH` y guarda la ruta **de dentro del contenedor** |
| «No hay ningún fichero en esa ruta» | Casi siempre, el volumen no está montado | `docker compose exec app ls -l /var/kronoqr/branding`. Si sale vacío, revisa `BRANDING_PATH` y `docker compose up -d app` |
| «El fichero existe pero la aplicación no puede leerlo» | Permisos | `sudo chmod 0644 <fichero>` en el servidor |
| «El logotipo ocupa más de 512 KiB» | Imagen demasiado pesada | Expórtala con menos resolución, o en SVG |
| «El fichero no es un PNG ni un SVG» | Se mira el **contenido**, no la extensión | Renombrar no sirve. Vuelve a exportarlo desde tu herramienta de diseño |
| «El SVG contiene un `<script`» | El SVG lleva código dentro | Expórtalo sin guiones ni interactividad. Un logotipo no necesita código |
| «El PNG supera los 2048 píxeles de lado» | Imagen enorme | Redúcela antes de volver a guardarla |

Mientras el `422` esté ahí **no se ha guardado nada**: la clave conserva el valor
que tenía.

### …he cambiado la marca y el quiosco sigue con la anterior

1. Comprueba qué está sirviendo el servidor, que es lo que ven las tres
   aplicaciones. No hace falta token:

   ```bash
   curl -sS https://TU-SERVIDOR/api/v1/branding
   ```

   Si ahí ya sale tu nombre, el servidor está bien y el problema es la caché del
   navegador de la tablet.

2. Si el nombre es el tuyo pero han desaparecido tu color y tu logotipo, mira la
   licencia: el aspecto propio es funcionalidad del plan (sección 2.2). El nombre
   nunca se degrada. Lo guardado sigue ahí y el aspecto vuelve solo al renovar.

3. El **quiosco guarda la marca para poder pintarla sin red** (así la tablet
   arranca con tu logotipo aunque el wifi tarde). Se actualiza sola al recuperar
   la conexión; para forzarlo, recarga la pantalla del quiosco.

4. El logotipo se cachea **para siempre a propósito**, pero su dirección cambia
   cuando cambia el fichero, así que sustituirlo se ve enseguida. Si has
   reemplazado el fichero y `curl` devuelve el mismo `logo_url` de antes, es que
   el contenido es idéntico.

### …cambio un valor y no se aplica

No debería pasar: el cambio surte efecto en la petición siguiente, sin reiniciar
nada. Si aun así ves el valor viejo:

1. Vuelve a pedir `GET /api/v1/settings` y mira el campo `source` de esa clave.
   Si dice `product_default`, el cambio **no se guardó**.
2. Comprueba que estás mirando la instalación correcta.
3. Si `source` dice `installation` con el valor nuevo y aun así se aplica el
   viejo, guarda la salida de `GET /api/v1/settings` y avisa a soporte: es un
   fallo del producto, no de tu configuración.

### …la respuesta trae `meta.unknown_keys` con algo dentro

Son filas guardadas cuyo nombre esta versión no reconoce. **No hacen nada** —no
las lee nadie— pero no deberían estar ahí: normalmente significan una
actualización que se quedó a medias o una edición manual de la base de datos.
Anótalo y avísalo en el próximo contacto con soporte. No hay prisa y no afecta al
fichaje.

### …la respuesta trae `meta.invalid_keys` con algo dentro

**Esto sí corre prisa.** Son filas cuya clave existe y cuyo valor guardado el
sistema no puede aplicar. Se han descartado y rige el valor de serie, así que
**lo que se está aplicando no es lo que hay escrito en la base de datos**.

Cada entrada trae tres campos: la clave, el motivo en tu idioma y
`affects_worked_hours`.

1. Si alguna entrada tiene `affects_worked_hours: true`, el cálculo de horas
   lleva aplicándose con el valor de serie desde que la fila se corrompió. Anota
   la fecha aproximada y revisa los informes de ese periodo.
2. Vuelve a guardar esa clave desde el panel con el valor correcto. Eso reemplaza
   la fila y la entrada desaparece.
3. Si no sabes cuál era el valor correcto, el registro de auditoría tiene el
   histórico de cambios de esa clave.

Solo pueden aparecer por una edición manual de la base de datos o por una
actualización entre versiones con catálogos distintos: la API **no deja guardar**
un valor que su clave no admita. El servidor deja además un aviso
(`product.settings_anomaly`) en su registro técnico.

**Ninguna de las dos listas impide fichar.** La lectura de la configuración es
deliberadamente tolerante: un color mal escrito no puede dejar a la plantilla sin
poder pasar la tarjeta.

### …quiero volver al valor de serie

Vuelve a escribirlo explícitamente. Los valores de serie están en la sección 2.
Guardar una cadena vacía **no** es volver al valor de serie: en la mayoría de las
claves se rechaza, y en `BRANDING_LOGO_PATH` significa exactamente «usa el
logotipo del producto», que es lo que quieres.

### …cambio un umbral legal y la bandeja de incidencias no cambia

Es lo esperado durante las primeras horas. La revisión que abre incidencias corre
**una vez al día, de madrugada**: hasta la siguiente pasada, la bandeja sigue
mostrando lo que se detectó con el umbral anterior. Y aunque pase la revisión,
**las incidencias ya abiertas no se cierran solas**: si has subido el umbral y
alguna dejó de ser un incumplimiento, hay que cerrarla a mano indicando el
motivo.

Si quieres comprobar el efecto sin esperar a la madrugada, alguien con acceso al
servidor puede lanzar la revisión a mano:

```bash
docker compose exec -T app php artisan attendance:detect-incidents
```

Imprime cuántas jornadas ha revisado y cuántos hallazgos de cada tipo ha abierto.
Repetirlo es seguro: no duplica nada y no cierra ningún turno.

### …he bajado los años de conservación por error

**No se ha borrado nada.** Cambiar `retention_years` no purga: la purga es un
comando aparte que se lanza a mano, propone primero en simulación y exige una
confirmación derivada de ese informe. Vuelve a poner el valor correcto en la
pantalla y comprueba la simulación antes de ejecutar ninguna purga:

```bash
docker compose exec -T app php artisan compliance:apply-retention --dry-run
```

La primera línea del informe dice el corte que se aplicaría y de qué perfil sale.

### …necesito saber quién cambió un umbral y cuándo

Cada clave modificada deja una entrada propia en el registro de auditoría, con la
cuenta que lo hizo, el momento, el valor anterior, el nuevo y si esa clave afecta
al cálculo de horas. Es información que se conserva cuatro años y que se puede
enseñar a la Inspección. La pide una cuenta con permiso de auditoría.

Lo mismo vale para el perfil de cumplimiento: **un apunte por cada umbral
cambiado**, con el valor anterior, el nuevo, si ese cambio mueve la detección de
incidencias y si mueve el plazo de conservación. Es lo que permite contestar a
«¿por qué esta jornada de marzo no generó alerta?».

### …la clave de licencia no se activa

**Nada se ha roto y la licencia anterior sigue intacta.** El mensaje te dice cuál
de los cuatro motivos es, porque lo que hay que hacer es distinto en cada uno:

| Lo que dice | Qué ha pasado | Qué hacer |
| --- | --- | --- |
| «La clave está incompleta o cortada» | Es, con diferencia, el caso más frecuente: la clave se copió a medias de un correo, o se partió en dos líneas | Cópiala entera. Empieza por `KQL1.` y no lleva espacios. Puedes pegarla con saltos de línea: se limpian solos |
| «Esta clave no la emitió el fabricante de esta versión» | La clave se modificó por el camino, o es de otro emisor | Pide una clave nueva a tu proveedor |
| «La clave está firmada pero le falta información» | Es un **fallo de emisión**, no tuyo | Avisa a tu proveedor con la huella que sale en la pantalla y pide otra clave. No pierdas tiempo revisando tu copiado |
| «Esta instalación no lleva la clave pública del fabricante» | Es un problema **del despliegue**, no de tu clave: falta un dato en la imagen instalada | Avisa a tu proveedor indicando la versión que devuelve `GET /api/v1/health`. Mientras tanto se sigue fichando con normalidad |

### …veo un aviso de licencia y quiero saber qué he perdido exactamente

```bash
docker compose exec app php artisan license:show
```

La sección «Funcionalidades accesorias» lista lo que está degradado **con la
fecha desde la que lo está**, y la sección siguiente, «Lo que NUNCA depende de la
licencia», lista lo que sigue funcionando. La misma información está en el panel,
en **Licencia**.

Si lo que necesitas hoy son las horas de tus empleados y el informe por periodo
está degradado, tienes dos vías que **no** dependen de la licencia: el registro
de cada persona (ficha del empleado → «Registro horario») y la **exportación para
la Inspección de Trabajo**, que trae el registro diario de toda la plantilla en un
fichero.

### …la presencia en vivo dice que no está en tiempo real

Mira el motivo que aparece en la propia pantalla:

- Si dice que es **por la licencia**, la vista está sondeando cada pocos segundos
  y no ha perdido información: sigue enseñando quién está dentro. Se recupera al
  renovar.
- Si **no** dice nada de la licencia, lo que falta es la configuración del
  servicio de tiempo real (`REVERB_*` en el `.env`) o el proxy no permite
  WebSocket. Eso lo arregla quien administra el servidor, no una renovación.

### …quiero comprobar que la licencia no está bloqueando nada

Ficha con una tarjeta y descarga la exportación legal con la licencia como esté.
Las dos tienen que funcionar. Si alguna no lo hace, **no es la licencia**: es una
avería, y conviene avisar a soporte con la salida de:

```bash
docker compose exec app php artisan license:show
curl -sS https://TU-SERVIDOR/api/v1/health
```

### …la importación rechaza líneas por «no trae documento ni correo»

La línea no tiene con qué identificarse. Añade al fichero la columna del
documento (`dni`, `nif`, `nie` o `documento`) **o** la del correo, y vuelve a
comprobarlo. El correo sigue siendo opcional: lo que no puede faltar son los dos
a la vez.

**Por qué se rechaza en lugar de importarla igual:** sin uno de los dos, el
sistema no podría reconocer a esa persona si vuelves a subir el fichero, y
acabarías con dos fichas suyas.

### …la importación no encuentra una columna que sí está en el fichero

Míralo en el informe: las columnas que no reconoce salen como aviso con su nombre
exacto. Las causas habituales, por frecuencia:

1. **El nombre no está en la lista.** Consulta la tabla de la sección 3 ter.2. Si
   tu sistema la llama de otra forma, añade el alias en el `.env` (sección
   3 ter.6) — no hace falta tocar el programa.
2. **Un carácter invisible.** Un espacio de más o un guion en lugar de un guion
   bajo **no** son problema: la comparación los ignora. Un carácter raro pegado
   al principio de la primera columna sí: es la marca de orden de bytes que
   añaden algunos editores. Vuelve a exportar el fichero desde el programa
   original.

### …importé y las tildes o las eñes salen mal

No debería pasar: la codificación se detecta sola. Si ocurre, el fichero
probablemente no es ni UTF-8 ni Windows-1252 —los dos que se reconocen— sino algo
más raro. Ábrelo en tu hoja de cálculo y vuelve a guardarlo como **CSV UTF-8**.
Corrige después los nombres en las fichas: la importación no borra nada, así que
puedes volver a importar el fichero corregido y se actualizarán solos.

### …importé el fichero equivocado

**Nada se borra.** Las personas importadas por error se dan de baja una a una
desde su ficha, con su fecha de cese; el registro que hubieran generado se
conserva, porque la ley obliga a conservarlo cuatro años.

Si aún **no habías confirmado** —solo hiciste la comprobación— no se escribió
nada: sube el fichero correcto y vuelve a empezar.

### …dice que el fichero no es el que se validó

Has cambiado el fichero entre la comprobación y la confirmación, aunque sea un
espacio. Es deliberado: así lo que se escribe es exactamente lo que revisaste.
Vuelve a hacer la comprobación con el fichero nuevo y confirma con la huella que
te devuelva.

### …dice que el fichero tiene demasiadas líneas

No se ha importado nada. Pártelo en varios ficheros más pequeños e impórtalos uno
a uno; el sistema reconoce a cada persona, así que el orden no importa y las
repeticiones entre ficheros no duplican a nadie.

Si tu plantilla es realmente mayor que el límite, súbelo con
`WORKFORCE_IMPORT_MAX_ROWS` en el `.env` y reinicia.

### …he importado a toda la plantilla y nadie puede fichar

Es lo esperado, y es el error más caro de esta guía si se descubre tarde:
**importar no emite ninguna tarjeta**. Comprueba cuántas faltan y empieza ya, que
imprimir y entregar lleva días:

```bash
docker compose exec app php artisan credentials:status --pending
```

### …el asistente de puesta en marcha ya no aparece

Es lo esperado: **es de un solo uso**. Todo lo que configuró se cambia después
desde el panel —el centro, los departamentos, el perfil de convenio, la
licencia—, y cada cambio queda registrado con su autor y su fecha, que es
precisamente lo que un asistente reabrible no podría garantizar.

### …el programa de nómina no lee el fichero de la salida a nómina

Casi siempre es una de estas cinco, y se arreglan todas desde el panel sin
tocar el servidor (sección 2.5). Abre el fichero con un editor de texto plano
—no con Excel, que te esconde justo lo que necesitas ver— y compara:

| Lo que ves | Qué cambiar |
| --- | --- |
| Todo en una sola columna, o los campos partidos donde no toca | `PAYROLL_EXPORT_DELIMITER`. Si además tienes las horas en `decimal_comma`, el separador no puede ser `comma` |
| Las tildes y las eñes salen como símbolos raros | `PAYROLL_EXPORT_ENCODING`. Prueba `utf8_bom` primero; si tu programa de nómina es antiguo, `latin1` |
| El programa de nómina dice que las horas no son un número | `PAYROLL_EXPORT_HOURS_FORMAT`. Muchos no aceptan `168:30` y sí `168.50` o `168,50` |
| El programa de nómina se queja de la primera línea, o importa la cabecera como si fuera una persona | `PAYROLL_EXPORT_HEADER_ROW` a `disabled`, o pon las etiquetas que espera con `id=Etiqueta` |
| Faltan columnas, sobran, o están en otro orden | `PAYROLL_EXPORT_COLUMNS`. El orden de la lista es el orden del fichero |

**Lo que NO vas a encontrar dentro del fichero son los criterios de cálculo**, y
es a propósito: una línea de comentario rompería la importación. Los criterios se
ven en la pantalla, junto al botón de descarga
([`guia-rrhh.md`](guia-rrhh.md) §6.4).

Si después de esto sigue sin entrar, pídele a tu proveedor de nómina un fichero
de ejemplo de los que sí importa y compáralo línea a línea con el tuyo: las seis
claves cubren todas las diferencias de forma que este producto sabe producir.

### …no puedo entrar y el asistente dice que ya hay una cuenta

Te pasó lo más común: creaste el primer administrador y se cerró la pantalla
antes de escanear el código QR del autenticador. **La cuenta existe.** Entra con
tu correo y tu contraseña por la pantalla de acceso normal: como todavía no
tienes segundo factor, la propia respuesta te ofrecerá darlo de alta y te
enseñará el QR otra vez.

Si además has perdido la contraseña, se restablece desde el servidor:

```bash
docker compose exec app php artisan identity:create-user   # crea otra cuenta de gestión
docker compose exec app php artisan identity:reset-password # contraseña nueva, mostrada una vez
docker compose exec app php artisan identity:deactivate-user # da de baja una cuenta
docker compose exec app php artisan identity:2fa-reset     # retira un segundo factor
```

---

## 5. Lo que NO se configura aquí, y dónde está

- **Los umbrales legales** —descanso mínimo entre jornadas, jornada ordinaria
  máxima, pausa obligatoria, años de retención— son del **perfil de
  cumplimiento** (sección 2.4), que tiene su propia pantalla y su propia
  dirección. Un umbral legal lo fija la norma o el convenio; uno operativo lo
  fijas tú.
- **Las funcionalidades activas** las decide **la licencia**, no una casilla del
  panel: si pudieras encenderlas desde aquí, la licencia no limitaría nada. Una
  licencia caducada recorta funcionalidades accesorias y muestra avisos, pero
  **nunca impide fichar ni consultar el registro**. Todo lo que hay que saber
  sobre ella está en la **sección 3 bis**.
- **Rutas, credenciales, puertos, redes y claves de firma** son del `.env` del
  servidor y exigen reiniciar los contenedores. **Están todas en la sección 6**,
  una por una, con lo que hace cada una y cuándo conviene tocarla; los
  parámetros de red y el certificado se explican además con detalle en
  [`instalacion.md`](instalacion.md) §6.

---

## 6. Referencia completa del `.env`, variable a variable

Esta es la lista **completa** de lo que se puede escribir en el `.env` del
servidor: **todas las variables que declara `.env.example`**, una por una. Está
aquí para que no tengas que leer el fichero entero cuando buscas una sola cosa,
y para que sepas de un vistazo si tocarla mueve horas de trabajo o no.

**Antes de cambiar nada, tres cosas:**

1. **Todo lo del `.env` exige reiniciar.** Se edita el fichero y se recrean los
   contenedores. Lo del panel, no (sección 1).
2. **Mira la marca.** Es la misma que lleva `.env.example` en la línea de
   encima de cada variable:

   | Marca | Qué significa |
   | --- | --- |
   | `[CLIENTE]` | La rellenas tú antes de instalar. `install.sh` comprueba en su fase 1 que están y **se niega a instalar** si falta alguna |
   | `[INSTALADOR]` | La genera `install.sh` **en tu servidor** y no la transmite a nadie. Déjala vacía: si escribes algo, el instalador lo sustituye. El fabricante no conoce estos valores y **no puede recuperarlos** |
   | `[FIJO]` | No se toca. Cambiarla rompe algo que no se parece a esta variable |
   | `—` | Tiene un valor por defecto pensado y **la mayoría de las instalaciones no lo cambia nunca**. Si dudas, déjalo como está |

3. **La columna «¿Afecta al cálculo de horas?»** dice `Sí` cuando cambiarla
   mueve minutos del registro legal o abre y cierra incidencias. Son unas
   pocas, y son las únicas que conviene documentar por escrito cuando las
   toques: el cambio no queda auditado como los del panel, porque el `.env` es
   un fichero de tu servidor.

Para aplicar un cambio:

```bash
sudo nano /opt/kronoqr-<version>/.env
cd /opt/kronoqr-<version>
sudo docker compose up -d
sudo docker compose exec app php artisan product:doctor
```

> **Nunca pegues aquí un valor de otra instalación**, y muy especialmente
> ninguno de los `[INSTALADOR]`. Cada servidor genera los suyos; compartirlos
> significa que quien tenga uno puede leer las copias, firmar tarjetas o abrir
> los PIN sellados del otro.

### 6.0 Las veintitrés claves que NO son variables de entorno

Veintitrés propiedades de la instalación no viven en el `.env` sino en la tabla
`installation_settings`, se editan **desde el panel** y surten efecto en la
petición siguiente sin reiniciar nada:

| Clave | Dónde se edita | Dónde se explica |
| --- | --- | --- |
| `ATTENDANCE_BREAK_CLOCKING` | Panel → **Ajustes operativos** (`/settings`) → «Fichaje de pausa» | Sección 2.1 |
| `ATTENDANCE_MAX_SHIFT_HOURS` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `ATTENDANCE_DEBOUNCE_SECONDS` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `ATTENDANCE_MIN_TRANSIT_SECONDS` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `ATTENDANCE_PATTERN_WINDOW_SECONDS` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `ATTENDANCE_PATTERN_MIN_REPEATS` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `WEEKLY_SUMMARY_EMAIL` | Panel → **Ajustes operativos** (`/settings`) → «Resumen semanal por correo» | Sección 2.1 |
| `KIOSK_UPDATE_WINDOW` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `KIOSK_UPDATE_QUIET_MINUTES` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `BASELINE_MANUAL_HOURS_PER_MONTH` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `BRANDING_APP_NAME` | Panel → **Marca** (`/branding`) | Sección 2.2 |
| `BRANDING_LOGO_PATH` | Panel → **Marca** (`/branding`) | Sección 2.2 |
| `BRANDING_ACCENT_COLOR` | Panel → **Marca** (`/branding`) | Sección 2.2 |
| `LOCALE_DEFAULT` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.3 |
| `LOCALE_AVAILABLE` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.3 |
| `KIOSK_SERVICE_CODE` | Panel → **Ajustes operativos** (`/settings`) | Sección 6.0, aquí mismo |
| `PAYROLL_EXPORT_COLUMNS` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.5 |
| `PAYROLL_EXPORT_DELIMITER` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.5 |
| `PAYROLL_EXPORT_HOURS_FORMAT` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.5 |
| `PAYROLL_EXPORT_DATE_FORMAT` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.5 |
| `PAYROLL_EXPORT_ENCODING` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.5 |
| `PAYROLL_EXPORT_HEADER_ROW` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.5 |

**`KIOSK_SERVICE_CODE` — el código de servicio de la tablet.** Es el código
numérico, de 8 a 12 cifras, con el que se abre la **pantalla de diagnóstico** de
un quiosco (pulsación larga sobre el reloj). Esa pantalla muestra el estado de la
cámara, de la red, de la cola de fichajes sin enviar y la versión instalada; no
muestra ningún dato de empleados ni la clave de la tablet. Sirve para que quien
atiende una avería no tenga que llamar a nadie.

- **Vacío de serie**, y vacío significa *la pantalla se abre sin pedir código*.
  No hay un código de fábrica: uno igual para todos los hoteles no protegería
  nada.
- **Las tablets lo reciben solas en menos de un minuto** después de guardarlo, y
  **nunca reciben el código**: reciben una huella con la que comprobarlo sin
  salir a la red, para que la pantalla siga funcionando cuando el problema es
  justamente que no hay red.
- **No se puede recuperar leyéndolo en ningún registro.** Queda constancia en la
  auditoría de quién lo cambió y cuándo, pero no de cuál es, y tampoco viaja en
  el paquete de diagnóstico que se envía a soporte. Si lo olvidas, escribe uno
  nuevo.
- Apúntalo donde lo tenga quien mantiene los quioscos. **No lo pegues en la
  propia tablet.**
- `php artisan product:doctor` avisa —solo avisa, nunca falla— mientras no haya
  ninguno configurado.

Las dos pantallas piden cuenta de **administrador de instalación** y las dos
guardan con el mismo botón: el cambio surte efecto en la petición siguiente y
queda auditado con tu nombre, la fecha y el valor anterior. Si no ves esas
entradas en el menú, no es que falten: es que tu cuenta no es de
administrador.

**Manda la base de datos** (sección 1). Dieciséis de las veintitrés —las de
marca, las de idioma, el código de servicio, las seis de la salida a nómina, el
resumen semanal, las dos de la ventana de actualización del quiosco y la línea
base de horas en hojas— no existen como variable de entorno: las de marca y las de idioma se retiraron para que no
hubiera dos sitios donde escribir el mismo dato; el código de servicio nunca la
tuvo, porque un secreto en el `.env` es un secreto que acaba en una copia de
seguridad sin cifrar; las seis de la salida a nómina tampoco, porque el formato
que pide un programa de nómina se ajusta a prueba y error el día de la
implantación y no puede exigir reiniciar los contenedores en cada intento; y las
cuatro últimas nacieron ya en el panel, porque son decisiones de operación del
hotel —si sale un correo con nombres, a qué hora puede reiniciarse una tablet y
cuántas horas se iban antes en hojas de horas— y no de despliegue.

**Las cinco `ATTENDANCE_*` sí siguen apareciendo en `.env.example`, y conviene
saber exactamente qué son:** una copia del valor de serie, escrita ahí para que
quien lea el fichero sepa con qué números trabaja el sistema. **La aplicación no
las lee.** Los cinco ajustes salen siempre de `installation_settings`, que la
migración sembró con esos mismos valores (12, 60, 15, 120 y `disabled`).
Consecuencia práctica, y es la causa de la mitad de los *«pues yo lo tengo puesto
a otra cosa»*:

> **Editar `ATTENDANCE_DEBOUNCE_SECONDS` en el `.env` no cambia nada.** Ni
> reiniciando. Se cambia en el panel, sección 2.1. Lo mismo vale para
> `ATTENDANCE_BREAK_CLOCKING`: ponerlo a `enabled` en el fichero no enciende el
> botón «Pausa» de ninguna tablet.

`product:doctor` lo detecta: si el `.env` y la base de datos dicen cosas
distintas en una de esas cinco claves, la comprobación
`settings.env_differs_from_db` sale en **aviso** y te dice cuál. No es un fallo
—no hay nada roto— pero significa que el fichero está engañando a quien lo lea.
Lo correcto es dejar el `.env` con el mismo valor que el panel, o borrar esas
cinco líneas.

### 6.1 Aplicación

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `APP_NAME` | — | Nombre **técnico** del proceso. No es la marca que se ve: esa es `BRANDING_APP_NAME` (sección 2.2) | `KronoQR` | Nunca. Se usa para derivar los prefijos de la caché y de las sesiones en Redis; cambiarlo cierra todas las sesiones abiertas | No |
| `APP_ENV` | `[CLIENTE]` | Entorno de ejecución | `local` en la plantilla; **`production` en tu servidor** | La pone el instalador. Si la ves en `local` en un hotel, es un error de instalación: corrígela y reinicia | No |
| `APP_DEBUG` | `[CLIENTE]` | Muestra la traza y la configuración completa ante cualquier error | `false` | **Nunca en producción.** Con `true`, cualquiera que provoque un error ve las claves incluidas. La aplicación **se niega a arrancar** con `APP_ENV=production` y esto en `true`, y dice cómo corregirlo | No |
| `APP_KEY` | `[INSTALADOR]` | Cifra sesiones y los datos cifrados en base de datos | (vacía; la genera `install.sh`) | Nunca a mano. Cambiarla deja ilegibles las sesiones y los datos ya cifrados | No |
| `APP_URL` | `[CLIENTE]` | La URL `https` por la que llegan los quioscos, el panel y el portal | `https://localhost` en la plantilla | Al instalar, y si cambias el nombre del servidor. **Tiene que coincidir con el nombre del certificado TLS.** Ejemplo: `https://fichaje.tuhotel.local` | No |
| `APP_TIMEZONE` | `[FIJO]` | Zona horaria del proceso | `UTC` | **Nunca.** Todo instante se almacena en UTC y la conversión a hora local ocurre al presentarlo. La zona horaria **se configura por centro** en el panel. Cambiar esto invalida el cálculo de jornada y deja el registro horario sin valor legal; el instalador ni siquiera ofrece tocarlo | **Sí** — cambiarla invalida el registro |
| `APP_LOCALE` | — | Idioma de respaldo de la API y de los documentos si la base de datos no responde | `es` | Casi nunca: manda `LOCALE_DEFAULT` del panel (sección 2.3) | No |
| `APP_FALLBACK_LOCALE` | — | Idioma al que se recurre si falta una traducción | `es` | Casi nunca | No |
| `APP_SUPPORTED_LOCALES` | — | Idiomas que la API acepta negociar, como respaldo | `es,en` | Casi nunca: manda `LOCALE_AVAILABLE` del panel (sección 2.3) | No |

### 6.2 Base de datos

Son **tres roles distintos de PostgreSQL**, y no es burocracia: el rol de la
aplicación no puede modificar ni borrar el registro de auditoría, y solo el de
mantenimiento puede soltar una partición vencida.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `DB_CONNECTION` | — | Motor de base de datos | `pgsql` | Nunca. El producto es PostgreSQL 17 | No |
| `DB_HOST` | — | Nombre del contenedor de PostgreSQL | `postgres` | Solo si mueves la base de datos a un servidor aparte | No |
| `DB_PORT` | — | Puerto | `5432` | Íd. | No |
| `DB_DATABASE` | — | Nombre de la base de datos | `fichaje` | Nunca después de instalar | No |
| `DB_USERNAME` | — | Rol de ejecución. **Sin DDL y sin `UPDATE` ni `DELETE` sobre la auditoría** | `fichaje_app` | Nunca | No |
| `DB_PASSWORD` | `[INSTALADOR]` | Contraseña de ese rol | (vacía; la genera `install.sh`) | Solo en una rotación de secretos; hay runbook | No |
| `DB_MIGRATION_USERNAME` | — | Rol propietario, el único con DDL. Ejecuta las migraciones | `fichaje_migrator` | Nunca | No |
| `DB_MIGRATION_PASSWORD` | `[INSTALADOR]` | Contraseña de ese rol | (vacía; la genera `install.sh`) | Íd. que la anterior | No |
| `DB_MAINTENANCE_USERNAME` | — | Rol de la purga por retención, el único que suelta particiones vencidas | `fichaje_maintenance` | Nunca | No |
| `DB_MAINTENANCE_PASSWORD` | — | Contraseña de ese rol | **Vacía a propósito** | **Nunca se escribe aquí.** Se aporta en el momento de ejecutar la purga anual: ver [`operacion.md`](operacion.md) §6 y §9 | No |
| `BACKUP_DB_USERNAME` | — | Usuario con el que se hacen las copias. Es el de migración porque copiar y restaurar exigen atributos que el de la aplicación no tiene | `fichaje_migrator` | Nunca | No |
| `BACKUP_DB_PASSWORD` | `[INSTALADOR]` | Su contraseña, la misma que la de migración | (vacía; la genera `install.sh`) | Nunca por separado: con otro valor, la copia diaria falla desde el primer día | No |

### 6.3 Redis, colas, caché y sesiones

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `REDIS_HOST` | — | Nombre del contenedor de Redis | `redis` | Solo si mueves Redis a un servidor aparte | No |
| `REDIS_PORT` | — | Puerto | `6379` | Íd. | No |
| `REDIS_PASSWORD` | — | Contraseña de Redis | *(vacía)* | Vacía es lo correcto en la instalación estándar: Redis **no publica ningún puerto** y solo es alcanzable desde la red interna de Docker. Rellénala solo si sacas Redis a otra máquina, y configúralo también en él | No |
| `QUEUE_CONNECTION` | — | Dónde viven los trabajos en segundo plano | `redis` | Nunca. Si Redis cae, esos trabajos esperan a que vuelva; **el fichaje no depende de ellos** | No |
| `CACHE_STORE` | — | Dónde vive la caché | `redis` | Nunca | No |
| `SESSION_DRIVER` | — | Dónde viven las sesiones | `redis` | Nunca | No |

### 6.4 Presencia en tiempo real

La presencia en vivo es una **funcionalidad accesoria**. Si esto está mal
configurado, la pantalla pasa a actualizarse por sondeo y lo dice. Nadie se
queda sin fichar.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `BROADCAST_CONNECTION` | — | Motor de difusión de eventos | `reverb` | Nunca | No |
| `REVERB_APP_ID` | `[INSTALADOR]` | Identificador de la aplicación en el servicio de tiempo real | (lo genera `install.sh`) | Nunca a mano | No |
| `REVERB_APP_KEY` | `[INSTALADOR]` | Clave **pública** que identifica la aplicación en el saludo del WebSocket. No autoriza nada por sí sola | (la genera `install.sh`) | Nunca a mano | No |
| `REVERB_APP_SECRET` | `[INSTALADOR]` | **El secreto.** Firma la autorización de cada canal privado | (vacía; la genera `install.sh`) | Nunca a mano. Sin él el servicio no arranca, que es el fallo ruidoso que se prefiere a uno silencioso con una clave conocida | No |
| `REVERB_HOST` | — | Cómo llega el servidor al servicio por la red interna. **El navegador no usa este valor**: entra por el mismo origen del panel | `reverb` | Nunca | No |
| `REVERB_PORT` | — | Puerto interno | `8080` | Nunca | No |
| `REVERB_SCHEME` | — | Esquema interno, dentro de la red de Docker | `http` | Nunca. El tráfico del navegador va cifrado por el borde | No |
| `REVERB_ALLOWED_ORIGINS` | — | Orígenes autorizados a abrir el WebSocket | *(vacía: todos)* | Solo si quieres cerrarlo a tu dominio, por ejemplo `fichaje.tuhotel.local`. Abierto de serie porque el dominio lo pone cada cliente, y la defensa real es que todos los canales son privados y el servidor firma cada suscripción | No |
| `REALTIME_ENABLED` | — | Si la vista de presencia usa WebSocket | `true` | Ponla a `false` si el proxy corporativo del hotel rompe los WebSockets. **No apaga la vista**: la deja en sondeo, con aviso en pantalla | No |
| `REALTIME_POLL_INTERVAL_SECONDS` | — | Cada cuántos segundos sondea la vista cuando no hay WebSocket | `15` | Bájalo si quieres la presencia más fresca a costa de más peticiones | No |
| `REALTIME_PATH` | — | Ruta del WebSocket en el origen del panel | `/app` | Nunca, salvo que un proxy tuyo ya use esa ruta | No |
| `REALTIME_AUTH_ENDPOINT` | — | Dirección que autoriza la suscripción a un canal privado | `/api/v1/broadcasting/auth` | Nunca | No |
| `REALTIME_EVENT` | — | Nombre del evento de presencia que escucha el panel | `presence.updated` | Nunca | No |

### 6.5 Credencial QR y ubicación del logotipo

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `QR_SIGNING_KEY_CURRENT_ID` | `[INSTALADOR]` | Identificador de dos caracteres de la clave con la que se firman las tarjetas nuevas | (lo genera `install.sh`) | Solo al rotar la clave, siguiendo el runbook `rotacion-clave-qr.md` | No |
| `QR_SIGNING_KEY_CURRENT` | `[INSTALADOR]` | La clave de firma activa | (vacía; la genera `install.sh`) | Íd. **Si se pierde, hay que reimprimir todas las tarjetas** | No |
| `QR_SIGNING_KEY_PREVIOUS_ID` | — | Identificador de la clave **saliente** durante una rotación | *(vacía)* | Solo durante una rotación. Vacía es el estado normal | No |
| `QR_SIGNING_KEY_PREVIOUS` | — | La clave saliente. Ya no firma, pero sigue **verificando** las tarjetas impresas con ella, y por eso la reimpresión se reparte en semanas | *(vacía)* | Íd. Un identificador sin su clave no crea ningún solape: o van las dos, o ninguna | No |
| `QR_ERROR_CORRECTION` | — | Cuánto desgaste aguanta una tarjeta antes de dejar de leerse | `Q` | Nunca. Bajarlo produce tarjetas que fallan a los meses de estar en un bolsillo | No |
| `QR_SIZE_MM` | — | Lado del QR impreso, en milímetros | `26` | Solo si cambias de formato de tarjeta. Es el tamaño mínimo con el que se garantiza la lectura | No |
| `IDENTITY_CREDENTIAL_REJECTION_FLOOR_MS` | — | Suelo de tiempo que consume **todo** rechazo de credencial, para que desde fuera no se distinga «no existe» de «revocada» ni de «mala firma» | `25` | Casi nunca. Subirlo endurece el control y añade latencia **solo al rechazo**; a `0` se desactiva y no debe hacerse en producción | No |
| `BRANDING_LOGO_ROOT` | — | Directorio **dentro del contenedor** en el que tiene que estar el logotipo. Es lo que impide que la dirección pública del logotipo se convierta en una lectura de cualquier fichero del servidor | `/var/kronoqr/branding` | Nunca, salvo que cambies también el montaje del `docker-compose`. Ver **sección 2.2** | No |
| `BRANDING_PATH` | — | Carpeta **de tu servidor** que se monta ahí, de solo lectura. Es donde dejas el PNG o el SVG | *(vacía: `./branding` junto al `docker-compose.yml`)* | Al colocar el logotipo del hotel. Ver **sección 2.2** | No |

### 6.6 Generación de PDF

Las tres son rutas **de dentro de la imagen del producto**: el navegador que
dibuja los PDF viaja incluido, no se descarga nada al arrancar y no hace falta
salida a internet.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `LARAVEL_PDF_CHROME_PATH` | — | Ruta del navegador que dibuja los PDF | `/usr/bin/chromium-browser` | Nunca | No |
| `LARAVEL_PDF_NODE_MODULES_PATH` | — | Ruta de las librerías que lo controlan | `/usr/local/lib/node_modules` | Nunca | No |
| `LARAVEL_PDF_NO_SANDBOX` | — | Desactiva el aislamiento propio del navegador, que el contenedor no puede concederle | `true` | Nunca. El contenedor ya corre sin privilegios y el HTML que se le entrega lo genera la propia aplicación, sin ninguna URL remota | No |

### 6.7 Reglas de fichaje

**Las siete se cambian en el panel, no aquí** (sección 6.0). La línea del
`.env` es una copia del valor de serie y **editarla no hace nada**.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `ATTENDANCE_BREAK_CLOCKING` | — | Fichaje de pausa: botón «Pausa» en la tablet y evaluación de la regla «Sin pausa registrada». Ver **sección 2.1** | `disabled` | En el panel. Aquí, nunca | No mueve minutos; **sí abre incidencias** |
| `ATTENDANCE_DEBOUNCE_SECONDS` | — | Ventana anti-rebote entre dos escaneos de la misma persona. Ver **sección 2.1** | `60` | En el panel. Aquí, nunca | **Sí** |
| `ATTENDANCE_MAX_SHIFT_HOURS` | — | Duración a partir de la cual un tramo cerrado es anómalo. Ver **sección 2.1** | `12` | En el panel. Aquí, nunca | **Sí** (abre incidencias) |
| `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` | — | Desfase tolerado entre el reloj de la tablet y el del servidor. **Genera incidencia, nunca rechaza el fichaje** (RF-AT-10). Ver **sección 2.1** | `15` | En el panel. Aquí, nunca | **Sí** (abre incidencias) |
| `ATTENDANCE_MIN_TRANSIT_SECONDS` | — | Tránsito mínimo creíble entre dos quioscos. Ver **sección 2.1** | `120` | En el panel. Aquí, nunca | **Sí** (abre incidencias) |
| `ATTENDANCE_PATTERN_WINDOW_SECONDS` | — | Segundos por debajo de los cuales dos fichajes de dos personas distintas en el mismo quiosco cuentan como una coincidencia. Ver **sección 2.1** | `10` | En el panel. Aquí, nunca | No mueve minutos; **sí abre incidencias** (junto con la siguiente) |
| `ATTENDANCE_PATTERN_MIN_REPEATS` | — | Días con coincidencia de la misma pareja antes de abrir la incidencia «Patrón anómalo de uso de la credencial». Ver **sección 2.1** | `3` | En el panel. Aquí, nunca | No mueve minutos; **sí abre incidencias** |

### 6.8 Acceso al panel de gestión

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `IDENTITY_LOGIN_MAX_ATTEMPTS` | — | Contraseñas falladas **por cuenta** antes de bloquearla | `5` | Súbelo si tu gente se queja de bloqueos; bájalo si tu política es más dura | No |
| `IDENTITY_LOGIN_LOCKOUT_SECONDS` | — | Cuánto dura ese bloqueo | `900` (15 min) | Íd. | No |
| `IDENTITY_SESSION_TOKEN_HOURS` | — | Vida de la sesión del panel | `12` | Bájalo si los ordenadores de gestión son compartidos. **No afecta al token del quiosco**, que dura 90 días | No |
| `IDENTITY_PASSWORD_MIN_LENGTH` | — | Longitud mínima de la contraseña de gestión | `12` | Súbelo si tu política lo pide. **Hay un suelo de 8 en el código**: por debajo no se puede bajar | No |
| `IDENTITY_MANAGEMENT_RATE_LIMIT` | — | Peticiones por minuto, por cuenta y por origen, de las rutas de gestión que leen o corrigen datos de terceros | `120` | Solo si un hotel grande ve errores `429` con uso normal | No |

### 6.9 Segundo factor de las cuentas de gestión

**Solo cuentas de gestión.** El empleado no tiene ni puede tener segundo factor:
su credencial es una tarjeta física y su acceso al portal es código y PIN.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `IDENTITY_2FA_REQUIRED_ROLES` | — | Roles obligados a llevar segundo factor | `admin,rrhh,auditor` | Añade `responsable_departamento` si tu política es más dura. Quitar un rol de la lista **no desactiva el segundo factor de quien ya lo activó** | No |
| `IDENTITY_2FA_CHALLENGE_MINUTES` | — | Minutos que vive la media autenticación entre la contraseña y el código | `10` | Casi nunca. En minutos y no en horas a propósito | No |
| `IDENTITY_2FA_MAX_ATTEMPTS` | — | Códigos fallados antes de bloquear | `5` | Casi nunca | No |
| `IDENTITY_2FA_LOCKOUT_SECONDS` | — | Cuánto dura ese bloqueo | `900` (15 min) | Casi nunca | No |
| `IDENTITY_2FA_WINDOW` | — | Tolerancia al desvío del reloj del teléfono, en franjas de 30 s a cada lado | `1` (un código vale unos 90 s) | Ponla a `0` si tus relojes están sincronizados por NTP y quieres apretar | No |
| `IDENTITY_2FA_SECRET_LENGTH` | — | Longitud del secreto del autenticador | `32` (160 bits) | Nunca | No |
| `IDENTITY_2FA_ISSUER` | — | Nombre que aparece en la aplicación de autenticación junto al correo de la cuenta | `KronoQR` | Cámbialo por el de tu hotel si prefieres verlo así en el móvil | No |
| `IDENTITY_2FA_RATE_LIMIT` | — | Peticiones por minuto a las rutas del segundo factor, por cuenta | `5` | Casi nunca. **Tiene que ser mayor o igual que `IDENTITY_2FA_MAX_ATTEMPTS`**, para que bloquee el contador de la cuenta y no el limitador | No |

### 6.10 PIN del empleado y token del quiosco

El PIN es de **seis dígitos** y esa longitud **no es configurable**: la fija el
contrato de la API. Lo que sí se ajusta es qué PIN nunca se emiten y cómo se
frena a quien los prueba. Los contadores son **por empleado y por origen**: el
del quiosco y el del portal son distintos, para que sondear una puerta no deje
a nadie sin poder fichar por la otra.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `IDENTITY_PIN_FORBIDDEN` | — | PIN que el generador nunca emite, separados por comas | *(la lista de serie: repetidos y secuencias)* | Casi nunca. **Escribirla sustituye a la lista de serie entera**; dejarla vacía significa «no excluir ninguno», que es mala idea | No |
| `IDENTITY_PIN_MAX_ATTEMPTS` | — | Fallos del primer escalón de bloqueo | `3` | Casi nunca | No |
| `IDENTITY_PIN_LOCKOUT_SECONDS` | — | Duración del primer bloqueo | `300` (5 min) | Casi nunca | No |
| `IDENTITY_PIN_LOCKOUT_TIER2_ATTEMPTS` | — | Fallos del segundo escalón | `5` | Casi nunca | No |
| `IDENTITY_PIN_LOCKOUT_TIER2_SECONDS` | — | Duración del segundo bloqueo | `900` (15 min) | Casi nunca | No |
| `IDENTITY_PIN_LOCKOUT_TIER3_ATTEMPTS` | — | Fallos del tercer escalón | `10` | Casi nunca | No |
| `IDENTITY_PIN_LOCKOUT_TIER3_SECONDS` | — | Duración del tercer bloqueo | `3600` (60 min) | Casi nunca | No |
| `IDENTITY_PIN_LOCKOUT_RESET_HOURS` | — | Sin fallos durante estas horas, el contador vuelve a cero | `24` | Casi nunca. Restablecer el PIN de alguien también limpia su contador en el acto | No |
| `IDENTITY_PIN_SEALING_SECRET_KEY` | `[INSTALADOR]` | Clave privada con la que el servidor abre los PIN que la tablet sella. Es lo que permite fichar por PIN **sin red** sin dejar el PIN en claro en la tablet | (vacía; la genera `install.sh`) | Nunca la copies de otro servidor. **Vacía es un caso legítimo**: significa que esta instalación no ofrece fichaje por PIN y el quiosco oculta el teclado numérico | No |
| `IDENTITY_DEVICE_TOKEN_DAYS` | — | Días que vive el token de una tablet emparejada | `90` | Casi nunca | No |
| `IDENTITY_DEVICE_TOKEN_ROTATION_THRESHOLD` | — | Fracción de esa vida a partir de la cual el token se renueva solo | `0.8` | Casi nunca. Renovarlo el último día dejaría sin fichar a una tablet que hubiera pasado una semana desconectada | No |

### 6.11 Asistente de puesta en marcha y marca pública

Las dos rutas que gobiernan son **públicas**, porque hacen falta antes de que
nadie se haya identificado.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `PRODUCT_SETUP_RATE_LIMIT` | — | Peticiones por minuto y por origen de las dos rutas públicas del asistente de puesta en marcha | `10` | Súbelo si el panel comparte una sola IP con media oficina por NAT. Una puesta en marcha la hace una persona, una vez | No |
| `PRODUCT_BRANDING_RATE_LIMIT` | — | Peticiones por minuto y por origen de la marca y del logotipo, que piden las tres aplicaciones al arrancar | `120` | Súbelo si un hotel grande ve errores `429` al arrancar por la mañana. **Subirlo mucho tiene coste en disco**: cada petición lee el fichero del logotipo. Si necesitaras varios miles, lo correcto es poner una caché delante, no subir este número | No |

### 6.12 Importación de plantilla desde un fichero

Todo el procedimiento está en la **sección 3 ter**. El delimitador y la
codificación **no se configuran**: se detectan.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `WORKFORCE_IMPORT_MAX_ROWS` | — | Líneas de datos como máximo por fichero. Ver **sección 3 ter.7** | `500` | Solo si tu plantilla es mayor, y antes prueba a partir el fichero: es más rápido | No |
| `WORKFORCE_IMPORT_MAX_FILE_KILOBYTES` | — | Tamaño máximo del fichero. Ver **sección 3 ter.7** | `4096` | Casi nunca: un fichero de 500 personas ronda los 60 KB | No |
| `WORKFORCE_IMPORT_COLUMN_ALIASES` | — | Nombres de columna adicionales, en formato `campo=cabecera` separados por `;`. Ver **sección 3 ter.6** | *(vacía)* | Cuando la exportación de tu sistema anterior llama a las columnas de otra forma. **Se suman a los de serie, no los sustituyen** | No |

### 6.13 Portal del empleado

Desde dónde se puede entrar al portal **no se decide aquí**: es
`PORTAL_INTERNAL_CIDR`, en la sección 6.15.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `IDENTITY_PORTAL_SESSION_HOURS` | — | Vida de la sesión del portal | `2` | Casi nunca. Más corta que la del panel a propósito: el portal se abre desde un móvil personal. Restablecer el PIN de alguien **invalida sus sesiones en el acto**, así que este número no es lo que responde a un móvil perdido | No |
| `IDENTITY_PORTAL_RATE_LIMIT` | — | Peticiones por minuto del portal, por IP **y** por código de empleado a la vez | `10` | Casi nunca. Se aplica por los dos ejes porque en un hotel toda la plantilla sale por la misma línea | No |

### 6.14 Límites del camino del quiosco

Son los límites de la **aplicación**, y no sustituyen a los del servidor web
(sección 6.15): aquellos limitan por origen y estos **por tablet**, que es lo
que impide que una tablet averiada consuma la cuota de las demás cuando todas
salen por la misma IP.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `KIOSK_SCAN_RATE_PER_DEVICE` | — | Fichajes por minuto y por tablet | `120` | Casi nunca | No |
| `KIOSK_BATCH_RATE_PER_DEVICE` | — | Envíos de lote por minuto y por tablet, que es como la tablet vacía su cola al recuperar la red | `60` | Casi nunca | No |
| `KIOSK_TELEMETRY_RATE_PER_DEVICE` | — | Peticiones por minuto y por tablet del padrón y del latido | `60` | Casi nunca | No |
| `KIOSK_RATE_PER_IP` | — | Todo el camino del quiosco, por origen | `600` | Casi nunca. Se fija al mismo valor que la zona interna del servidor web: más bajo, el techo real lo pondría la aplicación | No |
| `KIOSK_PIN_SCAN_RATE_PER_DEVICE` | — | Fichajes por PIN por minuto y por tablet | `10` | Casi nunca. Dos órdenes de magnitud por debajo del resto a propósito: ahí no se frena un ritmo de fichaje, se frena la fuerza bruta sobre seis dígitos | No |
| `KIOSK_PIN_SCAN_RATE_PER_IP` | — | Fichajes por PIN por minuto y por origen | `60` | Casi nunca, y por el mismo motivo | No |
| `KIOSK_BATCH_MAX_SIZE` | — | Escaneos como máximo en un lote de sincronización | `50` | Nunca: también está en el contrato de la API, así que cambiarlo aquí no lo cambia en la tablet. **Bajarlo por debajo de 50 hace que el servidor rechace todos los lotes de las tablets (422) y su cola sin red no se vacíe nunca**: no cambia minutos, pierde fichajes enteros | No |
| `KIOSK_HEALTH_FRESH_WITHIN_SECONDS` | — | Segundos de margen antes de que `php artisan kiosk:health` deje de dar por «al día» el último contacto de un quiosco | `120` | Casi nunca. El latido va cada 60 s, así que dos minutos son dos latidos perdidos: uno suelto puede ser un wifi que parpadea | No |
| `KIOSK_HEALTH_SILENT_AFTER_SECONDS` | — | Segundos a partir de los cuales `php artisan kiosk:health` da un quiosco por callado y sale con código 2 | `600` | Casi nunca. **Es el mismo umbral que la alerta `QuioscoSinLatido`** (`infra/observability/prometheus/rules/kiosk.yml`, [`operacion.md`](operacion.md) §10.4): si cambias uno, cambia el otro a la vez, o la consola y la alerta dirán cosas distintas del mismo quiosco | No |
| `KIOSK_HEALTH_BATTERY_LOW_PERCENT` | — | Nivel de batería por debajo del cual un quiosco **que no está cargando** sale en aviso, en el panel y en `php artisan kiosk:health` | `15` | Si tus tablets están siempre enchufadas puedes bajarlo; si las rotas a mano, súbelo. Con el cargador puesto no avisa nunca, y una tablet cuyo navegador no informa de la batería tampoco: solo Chrome en Android la informa | No |

### 6.15 Red, TLS y borde

Los tres rangos están explicados con detalle, con síntomas y comprobaciones, en
[`instalacion.md`](instalacion.md) **§6**.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `KIOSK_VLAN_CIDR` | `[CLIENTE]` | Rango de la VLAN de quioscos, al que se le eleva el límite de fichaje. Ver [`instalacion.md`](instalacion.md) §6 | `10.0.20.0/24` | **Al instalar, siempre.** Si los quioscos quedan fuera, el fallo es silencioso y se manifiesta como «el quiosco va lento a las 06:00» | No |
| `PORTAL_INTERNAL_CIDR` | `[CLIENTE]` | Red desde la que se permite el portal del empleado. Fuera de ella se responde `403` antes de llegar a la aplicación. Ver [`instalacion.md`](instalacion.md) §6 | `172.28.0.0/16` (una red de desarrollo) | **Al instalar, siempre**, por la LAN real del hotel o la VPN. Exponerlo a internet es una decisión explícita que se toma poniendo `0.0.0.0/0`, nunca dejando el valor de serie; documéntala en el acta de entrega | No |
| `METRICS_ALLOW_CIDR` | `[CLIENTE]` | Único origen autorizado a leer las métricas. Todo lo demás recibe `403`, incluido el propio servidor. Ver [`instalacion.md`](instalacion.md) §6 | `172.29.0.20/32` | Al instalar, si mueves el recolector de métricas. Es una `/32` a propósito, y **un solo rango**: el borde (Nginx) no admite más de uno aunque la aplicación acepte varios separados por comas | No |
| `TRUSTED_PROXIES` | — | En quién confía la aplicación para fijar la IP del cliente (`X-Forwarded-For`), lista de IP/CIDR separadas por comas | *(vacía: no se confía en ningún proxy)* | **Solo si pones otro proxy delante del borde de KronoQR** ([`endurecimiento.md`](endurecimiento.md) §1.6): entonces lleva la IP de ese proxy, no la de Nginx. Laravel trae de fábrica una heurística que confía en `X-Forwarded-For` cuando el `Host` termina en `.on-forge.com` —y el `Host` lo manda el propio cliente—; el producto la desactiva del todo y exige esta lista explícita | No |
| `NGINX_CLIENT_MAX_BODY_SIZE` | — | Tamaño máximo de cuerpo que acepta el servidor web | `8m` | Casi nunca. Súbelo solo si subes también `WORKFORCE_IMPORT_MAX_FILE_KILOBYTES` por encima de eso | No |
| `TLS_ALLOW_SELF_SIGNED` | `[CLIENTE]` | Permite arrancar con un certificado autofirmado | `true` en la plantilla | **A `false` en producción.** Con `false` y sin certificado, el servidor web no arranca y dice que hay que colocarlo. Es intencionado. Ver [`instalacion.md`](instalacion.md) §6 | No |
| `TLS_CERT_FILE` | — | Ruta del certificado **dentro del contenedor** | `/etc/nginx/certs/tls.crt` | Nunca. Lo que se cambia es la carpeta del servidor, `TLS_CERT_DIR` | No |
| `TLS_KEY_FILE` | — | Ruta de la clave privada dentro del contenedor | `/etc/nginx/certs/tls.key` | Íd. | No |

### 6.16 Cumplimiento y retención

> **Los años de conservación del registro horario NO están aquí.** Son un
> umbral legal y salen del perfil de cumplimiento del centro
> (`retention_years`, cuatro años en el perfil que se entrega): **sección 2.4**
> de este documento y [`operacion.md`](operacion.md) §6 y §9.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `COMPLIANCE_PROFILE` | `[CLIENTE]` | Nombre del perfil de cumplimiento con el que el instalador marca el perfil por defecto | `ES-hosteleria` | Al instalar, si tu convenio es otro. **No se lee en ejecución**: los umbrales salen de la fila del perfil, que se edita en el panel (sección 2.4). Cambiar esto sin cambiar la fila no hace nada | No |
| `ERROR_HISTORY_RETENTION_DAYS` | — | Días que se conserva el histórico de errores. Ver [`operacion.md`](operacion.md) §6 y §15.4 | `90` | Casi nunca | No |
| `TECHNICAL_LOG_RETENTION_DAYS` | — | Días que se conserva el registro técnico, en Loki y en Tempo (un almacén distinto del anterior). Ver [`operacion.md`](operacion.md) §10 | `90` | Casi nunca, y **nunca sola**: ni Loki ni Tempo releen esta variable —su plazo está fijado en horas dentro de `infra/observability/loki/loki.yaml` y de `infra/observability/tempo/tempo.yaml`—, así que cambiarla exige editar también esos dos ficheros | No |
| `COMPLIANCE_RETENTION_BATCH_SIZE` | — | Filas por sentencia de borrado en la purga. Ver [`operacion.md`](operacion.md) §6 | `1000` | Solo si la purga anual tarda demasiado | No |
| `COMPLIANCE_RETENTION_REPORT_PATH` | — | Dónde queda el informe de cada propuesta y de cada purga. **No se limpia solo**: es la constancia de que la purga fue regular. Ver [`operacion.md`](operacion.md) §6 | `storage/app/retention-reports` (en el contenedor) | Casi nunca | No |
| `COMPLIANCE_LEGAL_EXPORT_TEMP_RETENTION_HOURS` | — | Horas que puede vivir un temporal huérfano de la descarga de la exportación legal antes de que se borre solo. **No afecta** a la copia deliberada que genera el comando de exportación: esa la custodia quien la generó | `6` | Casi nunca | No |
| `COMPLIANCE_AUTHZ_DENIAL_WINDOW_SECONDS` | — | Ventana en la que las denegaciones repetidas de un mismo actor se agrupan en un solo asiento de auditoría. Protege la cadena de auditoría de una enumeración | `60` | Ponla a `0` si estás investigando un incidente y quieres un asiento por denegación | No |
| `COMPLIANCE_INCIDENT_LOOKBACK_DAYS` | — | Días hacia atrás que revisa la detección diaria de incidencias. Los tramos **todavía abiertos** se revisan siempre, sea cual sea su fecha | `7` | Casi nunca. **Subirlo puede abrir incidencias de jornadas ya entregadas a la plantilla o a la Inspección**, que es justo lo que la ventana evita | **Sí** (abre incidencias) |
| `COMPLIANCE_PATTERN_LOOKBACK_DAYS` | — | Días hacia atrás que revisa la detección nocturna de patrones anómalos de uso de credencial (04:35 UTC). Es más larga que la anterior porque «sistemático» necesita más de una semana. Ver [`operacion.md`](operacion.md) §6 | `30` | Casi nunca. Subirlo alarga la consulta nocturna y puede abrir incidencias sobre semanas ya revisadas; bajarlo por debajo de lo que tarda una pareja en acumular `ATTENDANCE_PATTERN_MIN_REPEATS` días deja el patrón sin poder detectarse | No mueve minutos; **sí abre incidencias** |
| `REPORTING_COMPLIANCE_MAX_RANGE_DAYS` | — | Días como máximo que puede abarcar una consulta de la vista de cumplimiento ([`guia-rrhh.md`](guia-rrhh.md) §4 bis). Por encima, la pantalla lo dice y no consulta | `92` | Casi nunca. Subirlo alarga la consulta y acerca el límite de la variable siguiente; si necesitas un periodo mayor, pide dos | No |
| `REPORTING_COMPLIANCE_TIMEOUT_SECONDS` | — | Segundos que se le conceden a esa consulta dentro de PostgreSQL antes de abandonarla. Protege al resto del sistema: nada se bloquea y la pantalla pide un periodo más corto | `10` | Solo si tu servidor es lento y la pantalla falla con periodos legítimos. Si tienes que subirlo mucho, el problema es la base de datos, no este número | No |

### 6.17 Licencia

Todo lo demás sobre la licencia está en la **sección 3 bis**. Y lo primero,
porque es lo que más se pregunta: **dejar `LICENSE_KEY` vacía no impide fichar**.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `LICENSE_KEY` | `[CLIENTE]` | La clave firmada que te entrega tu proveedor. Ver **sección 3 bis** | *(vacía)* | Al instalar, si la tienes a mano. **No se lee en ejecución**: solo la mira el instalador la primera vez. A partir de ahí manda lo activado desde el panel | No |
| `LICENSE_PUBLIC_KEY` | — | Clave pública del fabricante con la que se verifica la firma. **No la toca un cliente**: va compilada en el producto y es la misma en todas las instalaciones | *(vacía; la trae el producto)* | Nunca, salvo que soporte te lo pida por una rotación de urgencia | No |
| `LICENSE_EXPIRY_WARNING_DAYS` | — | Con cuánta antelación avisa el panel de que la licencia caduca. **Durante esos días no se degrada nada** | `30` | Súbelo si tu proceso de compras es lento. `0` deja el aviso para el último día, que no es lo recomendable | No |
| `LICENSE_HEALTH_PROBE_TTL_SECONDS` | — | Segundos que vive la copia del estado de licencia que lee la sonda de salud. **No es una caché de la licencia**: una clave recién activada surte efecto en el acto | `600` | Casi nunca. Existe para que la sonda de vida no consulte PostgreSQL, porque entonces una caída de la base de datos reiniciaría el contenedor de la aplicación | No |

### 6.18 Diagnóstico, soporte y exportación íntegra

Están explicadas una a una en la **sección 3 quater**; el procedimiento está en
[`operacion.md`](operacion.md) §12 y §13. **El paquete de diagnóstico va
anonimizado por defecto y no hay ninguna variable que lo cambie.**

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `PRODUCT_DIAGNOSTICS_MAX_BYTES` | — | Tamaño máximo del paquete de diagnóstico. Ver **sección 3 quater** | `8388608` (8 MiB) | Si tu canal de soporte corta antes | No |
| `PRODUCT_DIAGNOSTICS_RATE_LIMIT` | — | Paquetes por minuto y por cuenta. Ver **sección 3 quater** | `3` | Casi nunca | No |
| `PRODUCT_DIAGNOSTICS_PERSONAL_DATA_MAX_PERIOD_DAYS` | — | Días de fichajes como máximo en un paquete **con datos personales**. Ver **sección 3 quater** | `31` | **Subirlo es una decisión legal, no de rendimiento** | No |
| `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` | — | Días que un paquete se queda en el disco antes de que el comando lo borre al generar el siguiente. Ver **sección 3 quater** | `7` | Casi nunca | No |
| `PRODUCT_DIAGNOSTICS_PATH` | — | Dónde se escribe el paquete. Directorio a `0700` y fichero a `0600` | `storage/app/diagnostics` (en el contenedor) | Casi nunca. **No lo pongas dentro de `BACKUP_PATH`**: un paquete es material desechable que además puede llevar datos personales | No |
| `PRODUCT_DATA_EXPORT_PATH` | — | Dónde se escribe el ZIP de la exportación íntegra. Ver **sección 3 quater** | `storage/app/exports` (en el contenedor) | Casi nunca, y **nunca dentro de `BACKUP_PATH`** | No |
| `PRODUCT_DATA_EXPORT_RETENTION_DAYS` | — | Días que se puede descargar ese ZIP antes de purgarse. La anotación de que existió se conserva siempre. Ver **sección 3 quater** | `7` | Si lo necesitas más tiempo, mejor sácalo del servidor | No |
| `PRODUCT_DATA_EXPORT_RATE_LIMIT` | — | Peticiones por minuto y por cuenta a la lista y a la descarga de exportaciones. Ver **sección 3 quater** | `30` | No lo bajes: el panel sondea cada cinco segundos mientras hay una en curso | No |
| `PRODUCT_DATA_EXPORT_STALE_AFTER` | — | Segundos tras los que una exportación interrumpida se da por fallida y deja pedir otra. Ver **sección 3 quater** | `3600` | **Nunca por debajo de lo que tarda tu exportación más grande**: darías por muerta una que sigue escribiendo | No |
| `PRODUCT_SUPPORT_GRANT_DEFAULT_HOURS` | — | Duración de un acceso de soporte si no se indica otra. Ver **sección 3 quater** | `24` | Si tu política es más estricta | No |
| `PRODUCT_SUPPORT_GRANT_MAX_HOURS` | — | Duración máxima que se puede pedir. Más se rechaza. Ver **sección 3 quater** | `72` | Bájalo si tu política es más estricta; el panel y la API se ajustan solos. **No está en el panel a propósito**: si estuviera, quien concede el acceso podría subirlo antes de concederlo | No |
| `PRODUCT_SUPPORT_USE_AUDIT_WINDOW_SECONDS` | — | Cada cuánto se anota en auditoría un nuevo uso del mismo acceso de soporte. La fecha de «último uso» del panel se actualiza en cada petición igualmente. Ver **sección 3 quater** | `900` | Ponlo a `0` si estás investigando un incidente y quieres un asiento por petición | No |
| `PRODUCT_CLIENT_ERRORS_RATE_LIMIT` | — | Peticiones por minuto y por sesión del panel o del portal para reportar errores; por IP, cuatro veces más. Ver [`operacion.md`](operacion.md) §15 | `12` | Casi nunca | No |
| `PRODUCT_ERRORS_MAX_OPEN_GROUPS_PER_SOURCE` | — | Techo de grupos **abiertos** por origen en el histórico de errores; por encima, la siguiente ocurrencia que no encaja en un grupo existente va a un grupo de desbordamiento de ese origen en vez de crear fila nueva. Ver [`operacion.md`](operacion.md) §15.5 | `500` | Casi nunca | No |

### 6.19 Telemetría

Viene apagada y así se queda si no haces nada. Está explicada entera en la
**sección 3 quinquies**, incluida la lista cerrada de lo que se envía.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `TELEMETRY_ENABLED` | — | Si la telemetría está activada. Ver **sección 3 quinquies** | `false` | Solo si decides activarla. Hacen falta las tres condiciones de esa sección | No |
| `TELEMETRY_ENDPOINT` | — | A dónde se envía. Ver **sección 3 quinquies** | *(vacía)* | Íd. **Tiene que empezar por `https://`**; vacía, o sin eso, no se envía nada | No |
| `TELEMETRY_STATE_PATH` | — | Dónde viven el identificador aleatorio de la instalación y el historial de envíos. Ver **sección 3 quinquies** | `storage/app/telemetry/state.json` | Casi nunca | No |
| `TELEMETRY_RETRY_DELAY_SECONDS` | — | Segundos entre el intento y su único reintento. Ver **sección 3 quinquies** | `5` | Casi nunca | No |

### 6.20 Observabilidad

Qué se pierde exactamente si apagas los servicios de observabilidad, y qué
añaden Tempo y blackbox-exporter, está en [`operacion.md`](operacion.md) §10.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `LOG_CHANNEL` | — | A dónde escribe la aplicación su registro técnico. `stack` reparte a los canales de `LOG_STACK` | `stack` | Nunca | No |
| `LOG_STACK` | — | Canales del registro técnico, separados por comas | `stderr` | Casi nunca. **No decide si se envía a Loki**: eso lo decide `LOKI_URL`, nombres o no `loki` aquí | No |
| `LOG_LEVEL` | — | Cuánto detalle escribe | `debug` en la plantilla | **A `info` o `warning` en producción.** Con `debug` el registro crece mucho y se llena el disco antes de que nadie lo mire | No |
| `LOKI_URL` | — | Dirección del almacén de registros al que se envía el registro técnico | *(vacía: desactivado)* | **Es la que enciende o apaga el envío**, igual que `OTEL_EXPORTER_OTLP_ENDPOINT` de la fila siguiente: con el perfil `observability` encendido, `http://loki:3100` hace que cada petición y cada trabajo de cola terminen con un envío a Loki. Vacía, el canal `loki` no se registra, aunque `LOG_STACK` lo nombre. Encender el perfil no envía nada por sí solo: son dos decisiones distintas | No |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | A dónde se exportan las trazas | *(vacía: desactivado)* | Con el perfil `observability` encendido, `http://tempo:4318` envía las trazas a Tempo. Vacía, el rastreador ni arranca | No |
| `OTEL_SERVICE_NAME` | — | Nombre con el que aparece el servicio en esas trazas | `kronoqr-api` | Solo si el anterior está configurado y necesitas distinguir instalaciones | No |
| `OTEL_TRACES_SAMPLER_ARG` | — | Proporción de peticiones de las que se guarda traza | `1.0` (todas) | Casi nunca: un hotel no genera el volumen que justificaría muestrear menos | No |
| `OTEL_EXPORTER_OTLP_TIMEOUT` | — | Segundos de margen antes de dar por perdido el envío de una traza | `2` | Casi nunca. Un valor alto podría notarse en la latencia si Tempo no responde | No |
| `GRAFANA_ADMIN_USER` | — | Cuenta de administración del cuadro de mandos | `admin` | Cámbiala si tu política lo pide | No |
| `GRAFANA_ADMIN_PASSWORD` | `[INSTALADOR]` | Su contraseña | (vacía; la genera `install.sh`) | Se rota desde el propio cuadro de mandos. **Nunca se expone sin autenticación** | No |
| `ALERT_EMAIL_IT` | `[CLIENTE]` | A quién avisa Alertmanager de las alertas de destinatario IT. Ver [`operacion.md`](operacion.md) §10.4 | *(vacía)* | **Al instalar, si dejas el perfil `observability` encendido** — que es el valor de serie. Vacía, esas alertas no llegan a nadie | No |
| `ALERT_EMAIL_RRHH` | `[CLIENTE]` | A quién avisa de las alertas de destinatario RRHH (turnos abiertos, descanso insuficiente). Ver [`operacion.md`](operacion.md) §10.4 | *(vacía)* | Íd | No |
| `ALERT_EMAIL_SEGURIDAD` | `[CLIENTE]` | A quién avisa de las alertas de destinatario seguridad (rotura de cadena de auditoría, ataques de fuerza bruta). Ver [`operacion.md`](operacion.md) §10.4 | *(vacía)* | Íd. Son incidentes, no averías: revísalo con quien tenga ese papel en tu organización | No |
| `ALERT_WEBHOOK_IT` | `[CLIENTE]` | Webhook adicional para las alertas de IT, si usas uno (Slack, un sistema de guardias…). Ver [`operacion.md`](operacion.md) §10.4 | *(vacía)* | Opcional. Un valor vacío no genera ese envío, igual que con el correo | No |
| `ALERT_WEBHOOK_RRHH` | `[CLIENTE]` | Íd. para las de RRHH | *(vacía)* | Opcional | No |
| `ALERT_WEBHOOK_SEGURIDAD` | `[CLIENTE]` | Íd. para las de seguridad | *(vacía)* | Opcional | No |
| `ALERT_MAINTENANCE_WEEKDAY` | `[CLIENTE]` | Día de la semana de la ventana de mantenimiento que silencia alertas de quiosco, API, certificado y disco. **Valor en inglés y minúsculas** (`monday`…`sunday`): `render-config.sh` rechaza cualquier otro y no arranca. Ver [`operacion.md`](operacion.md) §10.4 | `sunday` | Si tu ventana tranquila es otro día. **Nunca el día del cambio de turno de las 06:00** | No |
| `ALERT_MAINTENANCE_START` | `[CLIENTE]` | Hora de inicio de esa ventana, en la zona horaria del **servidor**, no la del centro | `02:00` | Íd | No |
| `ALERT_MAINTENANCE_END` | `[CLIENTE]` | Hora de fin | `04:00` | Íd | No |

### 6.21 Correo

> **Por este canal salen nombres de la plantilla, a diario.** El resumen
> nocturno de incidencias va al responsable de cada departamento con la fecha,
> el nombre y el tipo de cada hallazgo. Es el único camino por el que datos
> personales salen del servidor sin que nadie pulse nada, y por eso deja
> asiento en el registro de auditoría. **Si tu relevo de correo es de un
> tercero, ese tercero es un encargado del tratamiento y tienes que tenerlo
> contratado**: ver [`obligaciones-legales.md`](obligaciones-legales.md).
>
> Un fallo de envío **no rompe nada del registro**: la incidencia sigue abierta
> en la bandeja y entra en el resumen de la noche siguiente.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `MAIL_MAILER` | `[CLIENTE]` | Cómo se envía el correo | `smtp` | Al instalar, si usas otro método | No |
| `MAIL_HOST` | `[CLIENTE]` | Servidor de correo saliente | `mailpit` (el de desarrollo) | **Al instalar, siempre**, por el de tu hotel | No |
| `MAIL_PORT` | `[CLIENTE]` | Su puerto | `1025` (el de desarrollo) | **Al instalar, siempre.** Con TLS implícito suele ser el 465 | No |
| `MAIL_USERNAME` | `[CLIENTE]` | Usuario de esa cuenta | *(vacía)* | Al instalar, si tu servidor lo exige | No |
| `MAIL_PASSWORD` | `[CLIENTE]` | Su contraseña | *(vacía)* | Íd. Es un secreto tuyo: no sale en ningún registro ni en el paquete de diagnóstico | No |
| `MAIL_SCHEME` | — | Cifrado del transporte | *(vacía: TLS oportunista)* | **Ponla en `smtps` en producción.** Vacía, si el servidor no anuncia cifrado la sesión sigue en claro y el correo, con nombres dentro, viaja legible. Con `smtps` el envío **falla** en vez de degradarse en silencio | No |
| `MAIL_FROM_ADDRESS` | `[CLIENTE]` | Dirección desde la que se envía | `no-reply@kronoqr.local` | Al instalar, por una de tu dominio: `no-reply@tuhotel.local` | No |
| `MAIL_FROM_NAME` | — | Nombre que se ve como remitente | `KronoQR` | Cámbialo por el de tu hotel si prefieres verlo así | No |

### 6.22 Copias de seguridad

La copia se queda en **tu** infraestructura: el fabricante no la recibe ni la
custodia. El procedimiento y la restauración están en
[`operacion.md`](operacion.md) §6 y §9, y el destino, en
[`instalacion.md`](instalacion.md) §6.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `BACKUP_PATH` | `[CLIENTE]` | Destino de las copias, montado en la misma ruta dentro de los contenedores. Ver [`instalacion.md`](instalacion.md) §6 | `/var/backups/fichaje` | **Al instalar, siempre**, por un destino que no esté en el mismo disco que la base de datos. Si es un recurso de red, **tiene que estar montado antes** de levantar los servicios | No |
| `BACKUP_ENCRYPTION_KEY` | `[INSTALADOR]` | Cifra las copias. **Sin ella no hay copia**: el script se niega a empezar | (vacía; la genera `install.sh`) | Nunca a mano. **Es la única que hay que custodiar fuera del servidor**: sin ella no se restaura nada. Ver [`operacion.md`](operacion.md) §9 | No |
| `BACKUP_RETENTION_DAYS` | — | Días que se conservan las copias diarias. Ver [`operacion.md`](operacion.md) §6 | `30` | Si tu política de copias es otra. **Ojo con el espacio en disco** antes de subirlo | No |
| `BACKUP_MIN_COPIES` | — | Copias que nunca se borran, aunque hayan caducado todas | `3` | Casi nunca. Es la red de seguridad que evita quedarse sin ninguna copia | No |
| `BACKUP_WAL_RETENTION_DAYS` | — | Días de registro de transacciones archivado que se conservan, que es lo que permite restaurar a un punto en el tiempo | `8` | **Tiene que ser mayor que el intervalo entre copias completas** (semanal de serie): sin la copia completa anterior, ese archivo no reconstruye nada | No |
| `BACKUP_DAILY_AT` | — | Hora de la copia diaria, **en UTC** | `03:15` | Si choca con otra tarea tuya. Nunca cerca de un cambio de turno. Recuerda que es UTC, no la hora del hotel | No |
| `BACKUP_WEEKLY_AT` | — | Hora de la copia semanal completa, **en UTC** | `02:15` | Íd. | No |
| `BACKUP_WEEKLY_ON` | — | Día de la semana de esa copia completa (`0` es domingo) | `0` | Si prefieres otro día tranquilo | No |

### 6.23 Solo producción

Las lee el `docker-compose` de producción. En desarrollo se ignoran.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `IMAGE_REGISTRY` | `[CLIENTE]` | Registro del que se descargan las imágenes | `ghcr.io/kronoqr` | Si tienes un registro interno propio, o si instalas sin salida a internet. Ver [`instalacion.md`](instalacion.md) §7 | No |
| `IMAGE_TAG` | `[INSTALADOR]` | La versión desplegada: la etiqueta de las imágenes y lo que publica la sonda de salud | (la escribe `install.sh` desde el fichero `VERSION` del paquete) | Nunca a mano. **`latest` está prohibido en producción** y no hay valor por defecto: si esto está vacío, Compose se para antes de crear nada y dice qué poner. Una instalación que no sabe decir qué versión corre hace imposible la vuelta atrás de `update.sh` | No |
| `COMPOSE_PROFILES` | — | Enciende los siete servicios de observabilidad (Prometheus, node-exporter, Alertmanager, Grafana, Loki, Tempo y blackbox-exporter) | `observability` | Déjalo puesto. Son los que avisan de que la copia de anoche falló o de que el archivado de transacciones se ha parado, los dos fallos que convierten una instalación sana en una pérdida de datos sin que nadie lo note. **Dejarlo vacío los apaga**, es una configuración soportada que libera unos 850 MiB, y entonces verificar la copia pasa a ser una tarea manual semanal tuya | No |
| `HTTP_PORT` | `[CLIENTE]` | Puerto en el que el servidor escucha peticiones sin cifrar, para redirigirlas | `80` | Solo si ese puerto ya está ocupado en la máquina | No |
| `HTTPS_PORT` | `[CLIENTE]` | Puerto cifrado por el que entran el panel, el portal y las tablets | `443` | Íd. Si lo cambias, tiene que aparecer también en `APP_URL` | No |
| `TLS_CERT_DIR` | `[CLIENTE]` | Carpeta **de tu servidor** con el certificado y su clave privada, montada de solo lectura. Ver [`instalacion.md`](instalacion.md) §6 | `./certs` | Al instalar, si guardas los certificados en otro sitio del servidor | No |

### 6.24 Rendimiento del servidor

Los tres se entregan con un valor que funciona y **casi nadie tendrá que
cambiarlos**. El apartado que explica cuándo sí, cómo medirlo antes y después, y
qué síntoma corresponde a cada uno es [`operacion.md`](operacion.md) **§17**.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `PHP_FPM_MAX_CHILDREN` | — | Cuántas peticiones atiende la aplicación a la vez (tamaño del pool de PHP-FPM). Los demás valores del pool se derivan de este | `20`, que es el pool del servidor mínimo publicado: 2 núcleos y 4 GB | Con el servidor recomendado —4 núcleos y 8 GB— súbelo a `40`. Solo tiene sentido si **sobra CPU** y las peticiones esperan turno: cada trabajador ocupa unos **60 MB**, así que el techo lo pone la RAM, y más trabajadores sobre una CPU saturada empeoran la latencia. Mídelo con [`operacion.md`](operacion.md) §17 antes y después: en las medidas del fabricante, doblar el pool sobre una CPU ya saturada no movió la cifra (§17.3) | No |
| `DB_LOCK_TIMEOUT` | — | Cuánto espera una consulta a que se libere un candado de la base de datos antes de rendirse. **Solo en el servicio que atiende peticiones** (`app`): fichaje, panel, portal y migraciones | `5s` | Casi nunca. Sin este tope, un fichaje espera **indefinidamente** detrás de una transacción colgada y el cambio de turno entero se para. Cuando salta, esa petición falla, **el quiosco la encola y la reenvía**, y el empleado no se entera | No |
| `DB_IDLE_IN_TRANSACTION_TIMEOUT` | — | Cuánto se tolera una transacción abierta que no hace nada antes de cerrar esa sesión. **Solo en el servicio que atiende peticiones**, igual que el anterior | `60s` | Casi nunca. Corta a la sesión que **tiene** el candado, que es la causa, y no a las que lo esperan. Súbelo solo si una tarea tuya de mantenimiento legítima necesita más tiempo dentro de una transacción | No |

**Ni la copia de seguridad ni las tareas nocturnas llevan estos dos topes.** Los
trabajos en segundo plano, el planificador y la presencia en vivo corren sin
ellos a propósito: un `pg_dump` de una base grande tarda legítimamente mucho más
de un minuto, y abortarlo por un tope pensado para que nadie espere delante de
un quiosco convertiría una copia lenta en una copia que no existe. Ver
[`operacion.md`](operacion.md) §17.4.

### 6.25 Informes generados en segundo plano

Un informe de periodo o una salida a nómina que no cabe en el acto se genera en
cola y se descarga después con un enlace de un solo uso. Quién lo usa y cómo se
ve desde el panel está en [`guia-rrhh.md`](guia-rrhh.md) §6.3; la purga diaria y
qué mirar cuando uno se queda a medias, en [`operacion.md`](operacion.md) §6
y §13. **No confundir con la exportación íntegra** (sección 3 quater): aquella es
un ZIP con toda la instalación y solo la genera el administrador.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `REPORTING_EXPORT_PATH` | — | Dónde se escriben los ficheros generados en segundo plano. **Fuera de la carpeta pública a propósito**: al fichero solo se llega con su enlace de un solo uso | `storage/app/reports` (en el contenedor) | Casi nunca, y **nunca dentro de `BACKUP_PATH`**: son ficheros que caducan y no deben entrar en la copia | No |
| `REPORTING_EXPORT_RETENTION_DAYS` | — | Días que el fichero se puede descargar antes de que la purga diaria lo borre. La anotación de que existió se conserva siempre | `7` | Si tu gente necesita más margen para bajarlo. Subirlo deja más tiempo en disco ficheros con datos de la plantilla | No |
| `REPORTING_EXPORT_LINK_TTL_MINUTES` | — | Minutos que vale el enlace de descarga. El enlace es además **de un solo uso**: al usarlo se consume, y volver a pedir el estado emite otro | `15` | Casi nunca. Es el único secreto que abre ese fichero y viaja sin sesión: cuanto más corto, mejor | No |
| `REPORTING_EXPORT_TIMEOUT_SECONDS` | — | Tope de tiempo que la base de datos le da a la consulta del informe en diferido. Es mucho mayor que el del informe que se calcula mientras esperas, que es justo el motivo de que exista el diferido | `600` | Súbelo si una exportación grande falla por tiempo y el servidor tiene margen | No |
| `REPORTING_EXPORT_STALE_AFTER` | — | Segundos tras los que una generación interrumpida —paraste los contenedores, se reinició el trabajador de cola— se da por fallida y deja pedir otra | `3600` | **Nunca por debajo de lo que tarda tu informe más grande**: darías por muerta una generación que sigue escribiendo | No |
| `REPORTING_EXPORT_DOWNLOAD_RATE_LIMIT` | — | Descargas por minuto y por dirección IP en la ruta de descarga. Va aparte del resto porque esa ruta se abre **sin sesión**, con el enlace de un solo uso | `30` | Casi nunca | No |

---

← [Instalación](instalacion.md) · [Operación](operacion.md) · [Obligaciones legales](obligaciones-legales.md)
