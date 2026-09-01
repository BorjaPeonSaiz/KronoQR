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

> **Excepción mientras dure la versión actual.** Los dos sitios donde hoy se
> imprime la marca —la tarjeta de credencial y la cabecera de la exportación
> legal— siguen leyendo las variables del `.env` (`BRANDING_NAME`,
> `BRANDING_LOGO_PATH`, `BRANDING_ACCENT_COLOR`). Lo que guardes en el panel se
> almacena y se audita, pero **todavía no cambia lo que se imprime**. La versión
> que trae la marca blanca completa pasa esos dos documentos, las tres
> aplicaciones y los avisos del diagnóstico a las claves del panel. Hasta
> entonces, si necesitas cambiar la marca de una tarjeta, cámbiala en el `.env`
> y reinicia.

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
> **Las tres claves de esta sección se guardan y se auditan, y todavía no se
> pintan.** Están disponibles desde ya para que la puesta en marcha pueda dejar
> la marca escrita; la versión que aplica la marca blanca a las tres aplicaciones
> y a los PDF llega después. Ver la nota de la sección 1.

| Clave | De serie | Qué es |
| --- | --- | --- |
| `BRANDING_APP_NAME` | `KronoQR` | Nombre de la aplicación. Hasta 60 caracteres: es lo que cabrá en la cabecera de la tarjeta impresa. |
| `BRANDING_LOGO_PATH` | *(vacío)* | Ruta **absoluta en el servidor** a un PNG o un SVG. Vacío significa «el logotipo del producto», no «sin logotipo». |
| `BRANDING_ACCENT_COLOR` | `#111827` | Color de acento, en notación `#rrggbb`. Cualquier otra forma se rechaza. |

`BRANDING_LOGO_PATH` es una ruta del sistema de ficheros y **no una URL**: los PDF
se generan en un navegador sin salida a internet, así que una URL remota no se
descargaría. El fichero tiene que estar montado dentro del contenedor de la
aplicación. **Que exista no se comprueba al guardar**: si la ruta es incorrecta,
los documentos se imprimirán sin logotipo en lugar de fallar — nadie se queda sin
poder fichar porque falte una imagen. El diagnóstico avisará de una ruta que no
existe cuando llegue la versión que lo incorpora.

### 2.3 Idiomas

| Clave | De serie | Qué es |
| --- | --- | --- |
| `LOCALE_AVAILABLE` | `["es","en"]` | Idiomas que la instalación ofrece. Solo se admiten los que el producto trae traducidos. |
| `LOCALE_DEFAULT` | `es` | Idioma con el que se sirven las aplicaciones y los documentos cuando el navegador no pide otro. **Se guarda y se audita; el idioma que hoy se aplica sale de `APP_LOCALE` y `APP_SUPPORTED_LOCALES` del `.env`**, y la versión de la marca blanca los unifica con estas dos claves. |

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
