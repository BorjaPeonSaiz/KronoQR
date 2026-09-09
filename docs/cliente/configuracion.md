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
- **Rutas, credenciales, puertos, redes y claves de firma** son del `.env` del
  servidor y exigen reiniciar los contenedores. **Están todas en la sección 6**,
  una por una, con lo que hace cada una y cuándo conviene tocarla; los
  parámetros de red y el certificado se explican además con detalle en
  [`instalacion.md`](instalacion.md) §6.

---

## 6. Referencia completa del `.env`, variable a variable

Esta es la lista **completa** de lo que se puede escribir en el `.env` del
servidor: **161 variables**, todas las que trae `.env.example`. Está aquí para
que no tengas que leer el fichero entero cuando buscas una sola cosa, y para
que sepas de un vistazo si tocarla mueve horas de trabajo o no.

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

### 6.0 Las nueve claves que NO son variables de entorno

Nueve propiedades de la instalación no viven en el `.env` sino en la tabla
`installation_settings`, se editan **desde el panel** y surten efecto en la
petición siguiente sin reiniciar nada:

| Clave | Dónde se edita | Dónde se explica |
| --- | --- | --- |
| `ATTENDANCE_MAX_SHIFT_HOURS` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `ATTENDANCE_DEBOUNCE_SECONDS` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `ATTENDANCE_MIN_TRANSIT_SECONDS` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.1 |
| `BRANDING_APP_NAME` | Panel → **Marca** (`/branding`) | Sección 2.2 |
| `BRANDING_LOGO_PATH` | Panel → **Marca** (`/branding`) | Sección 2.2 |
| `BRANDING_ACCENT_COLOR` | Panel → **Marca** (`/branding`) | Sección 2.2 |
| `LOCALE_DEFAULT` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.3 |
| `LOCALE_AVAILABLE` | Panel → **Ajustes operativos** (`/settings`) | Sección 2.3 |

Las dos pantallas piden cuenta de **administrador de instalación** y las dos
guardan con el mismo botón: el cambio surte efecto en la petición siguiente y
queda auditado con tu nombre, la fecha y el valor anterior. Si no ves esas
entradas en el menú, no es que falten: es que tu cuenta no es de
administrador.

**Manda la base de datos** (sección 1). Cinco de las nueve —las de marca y las
de idioma— ya no existen como variable de entorno: se retiraron para que no
hubiera dos sitios donde escribir el mismo dato.

**Las cuatro `ATTENDANCE_*` sí siguen apareciendo en `.env.example`, y conviene
saber exactamente qué son:** una copia del valor de serie, escrita ahí para que
quien lea el fichero sepa con qué números trabaja el sistema. **La aplicación no
las lee.** Los cuatro umbrales salen siempre de `installation_settings`, que la
migración sembró con esos mismos valores (12, 60, 15 y 120). Consecuencia
práctica, y es la causa de la mitad de los *«pues yo lo tengo puesto a otra
cosa»*:

> **Editar `ATTENDANCE_DEBOUNCE_SECONDS` en el `.env` no cambia nada.** Ni
> reiniciando. Se cambia en el panel, sección 2.1.

`product:doctor` lo detecta: si el `.env` y la base de datos dicen cosas
distintas en una de esas cuatro claves, la comprobación
`settings.env_differs_from_db` sale en **aviso** y te dice cuál. No es un fallo
—no hay nada roto— pero significa que el fichero está engañando a quien lo lea.
Lo correcto es dejar el `.env` con el mismo valor que el panel, o borrar esas
cuatro líneas.

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

**Las cuatro primeras se cambian en el panel, no aquí** (sección 6.0). La línea
del `.env` es una copia del valor de serie y **editarla no hace nada**.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `ATTENDANCE_DEBOUNCE_SECONDS` | — | Ventana anti-rebote entre dos escaneos de la misma persona. Ver **sección 2.1** | `60` | En el panel. Aquí, nunca | **Sí** |
| `ATTENDANCE_MAX_SHIFT_HOURS` | — | Duración a partir de la cual un tramo cerrado es anómalo. Ver **sección 2.1** | `12` | En el panel. Aquí, nunca | **Sí** (abre incidencias) |
| `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` | — | Desfase tolerado entre el reloj de la tablet y el del servidor. **Genera incidencia, nunca rechaza el fichaje** (RF-AT-10). Ver **sección 2.1** | `15` | En el panel. Aquí, nunca | **Sí** (abre incidencias) |
| `ATTENDANCE_MIN_TRANSIT_SECONDS` | — | Tránsito mínimo creíble entre dos quioscos. Ver **sección 2.1** | `120` | En el panel. Aquí, nunca | **Sí** (abre incidencias) |
| `ATTENDANCE_PATTERN_WINDOW_SECONDS` | — | Segundos por debajo de los cuales dos fichajes consecutivos en el mismo quiosco se considerarán un patrón anómalo | `10` | **Todavía no la lee nada**: ese detector llega en una versión posterior. La variable está reservada para no tener que cambiar el fichero entonces | No, todavía |
| `ATTENDANCE_PATTERN_MIN_REPEATS` | — | Coincidencias sistemáticas entre dos personas antes de abrir una incidencia | `3` | Íd. que la anterior | No, todavía |

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
| `KIOSK_HEALTH_SILENT_AFTER_SECONDS` | — | Segundos a partir de los cuales `php artisan kiosk:health` da un quiosco por callado y sale con código 2 | `600` | Casi nunca. Es el mismo umbral que la alerta «Quiosco sin latido > 10 min»: si los separas, la consola y la alerta dirán cosas distintas del mismo quiosco | No |

### 6.15 Red, TLS y borde

Los tres rangos están explicados con detalle, con síntomas y comprobaciones, en
[`instalacion.md`](instalacion.md) **§6**.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `KIOSK_VLAN_CIDR` | `[CLIENTE]` | Rango de la VLAN de quioscos, al que se le eleva el límite de fichaje. Ver [`instalacion.md`](instalacion.md) §6 | `10.0.20.0/24` | **Al instalar, siempre.** Si los quioscos quedan fuera, el fallo es silencioso y se manifiesta como «el quiosco va lento a las 06:00» | No |
| `PORTAL_INTERNAL_CIDR` | `[CLIENTE]` | Red desde la que se permite el portal del empleado. Fuera de ella se responde `403` antes de llegar a la aplicación. Ver [`instalacion.md`](instalacion.md) §6 | `172.28.0.0/16` (una red de desarrollo) | **Al instalar, siempre**, por la LAN real del hotel o la VPN. Exponerlo a internet es una decisión explícita que se toma poniendo `0.0.0.0/0`, nunca dejando el valor de serie; documéntala en el acta de entrega | No |
| `METRICS_ALLOW_CIDR` | `[CLIENTE]` | Único origen autorizado a leer las métricas. Todo lo demás recibe `403`, incluido el propio servidor. Ver [`instalacion.md`](instalacion.md) §6 | `172.29.0.20/32` | Al instalar, si mueves el recolector de métricas. Es una `/32` a propósito | No |
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
| `TECHNICAL_LOG_RETENTION_DAYS` | — | Días que se conserva el registro técnico, en un almacén distinto del anterior. Ver [`operacion.md`](operacion.md) §6 | `90` | Casi nunca | No |
| `COMPLIANCE_RETENTION_BATCH_SIZE` | — | Filas por sentencia de borrado en la purga. Ver [`operacion.md`](operacion.md) §6 | `1000` | Solo si la purga anual tarda demasiado | No |
| `COMPLIANCE_RETENTION_REPORT_PATH` | — | Dónde queda el informe de cada propuesta y de cada purga. **No se limpia solo**: es la constancia de que la purga fue regular. Ver [`operacion.md`](operacion.md) §6 | `storage/app/retention-reports` (en el contenedor) | Casi nunca | No |
| `COMPLIANCE_LEGAL_EXPORT_TEMP_RETENTION_HOURS` | — | Horas que puede vivir un temporal huérfano de la descarga de la exportación legal antes de que se borre solo. **No afecta** a la copia deliberada que genera el comando de exportación: esa la custodia quien la generó | `6` | Casi nunca | No |
| `COMPLIANCE_AUTHZ_DENIAL_WINDOW_SECONDS` | — | Ventana en la que las denegaciones repetidas de un mismo actor se agrupan en un solo asiento de auditoría. Protege la cadena de auditoría de una enumeración | `60` | Ponla a `0` si estás investigando un incidente y quieres un asiento por denegación | No |
| `COMPLIANCE_INCIDENT_LOOKBACK_DAYS` | — | Días hacia atrás que revisa la detección diaria de incidencias. Los tramos **todavía abiertos** se revisan siempre, sea cual sea su fecha | `7` | Casi nunca. **Subirlo puede abrir incidencias de jornadas ya entregadas a la plantilla o a la Inspección**, que es justo lo que la ventana evita | **Sí** (abre incidencias) |

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

Qué se pierde exactamente si apagas los servicios de observabilidad está en
[`operacion.md`](operacion.md) §10.

| Variable | Marca | Qué hace | De serie | Cuándo cambiarla | ¿Afecta al cálculo de horas? |
| --- | --- | --- | --- | --- | --- |
| `LOG_CHANNEL` | — | A dónde escribe la aplicación su registro técnico | `stderr` | Nunca. Es lo que permite que el recolector de registros lo lea | No |
| `LOG_LEVEL` | — | Cuánto detalle escribe | `debug` en la plantilla | **A `info` o `warning` en producción.** Con `debug` el registro crece mucho y se llena el disco antes de que nadie lo mire | No |
| `LOG_STDERR_FORMATTER` | — | Formato del registro: una línea JSON por evento, que es lo que se puede buscar y filtrar | `Monolog\Formatter\JsonFormatter` | Nunca | No |
| `LOKI_URL` | — | Dirección del almacén de registros | `http://loki:3100` | Casi nunca. **Por sí sola no cambia el destino de nada**: la aplicación escribe en `stderr` y el cuadro de mandos ya viene apuntado. Se conserva porque viaja en el paquete de diagnóstico y le dice a soporte a dónde miras | No |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | A dónde se exportan las trazas | *(vacía: desactivado)* | Solo si tienes un sistema de trazas propio al que enviarlas | No |
| `OTEL_SERVICE_NAME` | — | Nombre con el que aparece el servicio en esas trazas | `kronoqr-api` | Solo si el anterior está configurado y necesitas distinguir instalaciones | No |
| `GRAFANA_ADMIN_USER` | — | Cuenta de administración del cuadro de mandos | `admin` | Cámbiala si tu política lo pide | No |
| `GRAFANA_ADMIN_PASSWORD` | `[INSTALADOR]` | Su contraseña | (vacía; la genera `install.sh`) | Se rota desde el propio cuadro de mandos. **Nunca se expone sin autenticación** | No |

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
| `COMPOSE_PROFILES` | — | Enciende los servicios de observabilidad | `observability` | Déjalo puesto. Son los que avisan de que la copia de anoche falló o de que el archivado de transacciones se ha parado, los dos fallos que convierten una instalación sana en una pérdida de datos sin que nadie lo note. **Dejarlo vacío los apaga**, es una configuración soportada que libera unos 700 MiB, y entonces verificar la copia pasa a ser una tarea manual semanal tuya | No |
| `HTTP_PORT` | `[CLIENTE]` | Puerto en el que el servidor escucha peticiones sin cifrar, para redirigirlas | `80` | Solo si ese puerto ya está ocupado en la máquina | No |
| `HTTPS_PORT` | `[CLIENTE]` | Puerto cifrado por el que entran el panel, el portal y las tablets | `443` | Íd. Si lo cambias, tiene que aparecer también en `APP_URL` | No |
| `TLS_CERT_DIR` | `[CLIENTE]` | Carpeta **de tu servidor** con el certificado y su clave privada, montada de solo lectura. Ver [`instalacion.md`](instalacion.md) §6 | `./certs` | Al instalar, si guardas los certificados en otro sitio del servidor | No |

---

← [Instalación](instalacion.md) · [Operación](operacion.md) · [Obligaciones legales](obligaciones-legales.md)
