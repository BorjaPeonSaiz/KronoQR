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
| **Umbrales legales**: descanso mínimo, jornada máxima, pausas, años de retención | Tabla `compliance_profiles` | `PATCH /api/v1/compliance-profile` (panel → «Cumplimiento», rol *administrador*) | No |
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
| `ATTENDANCE_MAX_SHIFT_HOURS` | `12` | 1 – 24 | A partir de esa duración, un tramo cerrado se marca como **anómalo** y se abre una incidencia para revisión. **No cierra ningún turno por su cuenta.** |
| `ATTENDANCE_DEBOUNCE_SECONDS` | `60` | 0 – 3600 | Ventana de gracia: dos escaneos de la misma persona dentro de esa ventana cuentan como uno. **Esta clave cambia las horas registradas** — ver el aviso de abajo. `0` la desactiva. |
| `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` | `15` | 1 – 1440 | Desfase tolerado entre el reloj de la tablet y el del servidor antes de marcar el fichaje para revisión. **Nunca rechaza un fichaje**, solo lo señala. |
| `ATTENDANCE_MIN_TRANSIT_SECONDS` | `120` | 0 – 3600 | Tiempo mínimo creíble para ir de un quiosco a otro. Por debajo, se abre incidencia. Ponlo a `0` si tienes dos tablets en la misma puerta; súbelo si hay dos edificios. |

> **⚠️ `ATTENDANCE_DEBOUNCE_SECONDS` afecta al cálculo de horas.** Subirlo hace
> que fichajes reales muy seguidos se descarten, y el total de la jornada sale
> distinto. Es la única clave de esta lista que mueve minutos del registro legal.
> Cámbiala con criterio y déjalo dicho por escrito: el cambio queda auditado con
> tu nombre, la fecha y el valor anterior.

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

Esto **no** está en la pantalla de configuración: tiene la suya, «Cumplimiento», y
también es de administrador. Están aparte porque son otra cosa. Un umbral
**operativo** lo decides tú según cómo funciona tu hotel; un umbral **legal** lo
fija la norma o el convenio, y equivocarse tiene consecuencias distintas.

Se entrega el perfil **`ES-hosteleria`**, con estos valores:

| Campo | De serie | Qué hace | De dónde sale |
| --- | --- | --- | --- |
| `min_rest_hours` | `12` | Se abre incidencia si entre el fin de un turno y el inicio del siguiente median **menos** de esas horas | Art. 34.3 ET |
| `max_daily_hours` | `9` | Se abre incidencia si la suma de los tramos de una jornada **supera** esas horas | Art. 34.3 ET |
| `break_required_after_hours` | `6` | Umbral del tramo continuo sin pausa registrada. **Hoy la regla se evalúa pero no abre incidencia** (ver abajo) | Art. 34.4 ET |
| `updated_at` | vacío | Solo lectura: cuándo se ajustó por última vez. **Vacío significa «tal como se instaló»** | — |
| `max_weekly_hours` | `40` | Jornada semanal ordinaria. **Todavía no lo aplica ninguna regla** | Art. 34.1 ET |
| `week_starts_on` | `1` (lunes) | Día en que empieza la semana. **Todavía no lo aplica ninguna regla** | ISO 8601 |
| `holiday_calendar` | vacío | Festivos del centro, una fecha por línea. **Todavía no lo aplica ninguna regla** | Lo cargas tú |
| `retention_years` | `4` | Años que hay que conservar el registro antes de poder purgarlo | Art. 34.9 ET |
| `name` | `ES-hosteleria` | Cómo se llama el convenio que el perfil describe | Lo pones tú |

**El calendario de festivos se entrega vacío a propósito.** Los festivos dependen
del municipio y del año: un calendario metido dentro del producto caducaría cada
31 de diciembre y sería incorrecto para la mitad de los clientes. Lo cargas tú,
una vez al año, pegando las fechas.

**Tres campos se guardan y todavía no se aplican** —jornada semanal, día de
inicio de semana y festivos—. La pantalla lo dice al lado de los campos. Puedes
dejarlos ya ajustados a tu convenio: los estrena la vista de cumplimiento de una
versión posterior, y los cambios quedan auditados desde hoy.

**`break_required_after_hours` está enunciado pero no abre incidencias todavía.**
El sistema no puede distinguir «no descansó» de «descansó y no lo fichó» hasta que
el quiosco registre la pausa como tal; abrir incidencias mientras tanto llenaría
la bandeja de falsos positivos y taparía las que sí importan. El umbral se guarda
y se aplicará cuando la detección se reactive.

Consecuencia práctica, y conviene saberla antes de tocarlo: **cambiar ese umbral
hoy no altera ni una incidencia**. La pantalla lo dice al lado del campo y el
registro de auditoría lo deja escrito (`detection_suspended`), para que dentro
de dos años se pueda distinguir «esto no movía alertas» de «las movía, pero
entonces la regla estaba suspendida».

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

### …no puedo entrar y el asistente dice que ya hay una cuenta

Te pasó lo más común: creaste el primer administrador y se cerró la pantalla
antes de escanear el código QR del autenticador. **La cuenta existe.** Entra con
tu correo y tu contraseña por la pantalla de acceso normal: como todavía no
tienes segundo factor, la propia respuesta te ofrecerá darlo de alta y te
enseñará el QR otra vez.

Si además has perdido la contraseña, se restablece desde el servidor:

```bash
docker compose exec app php artisan identity:create-user   # crea otra cuenta de gestión
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
- **Rutas, credenciales, puertos y claves de firma** son del `.env` del servidor
  y exigen reiniciar. Están documentados en la guía de instalación.

---

← [Instalación](instalacion.md) · [Operación](operacion.md) · [Obligaciones legales](obligaciones-legales.md)
