<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Exception\UnknownSettingKey;
use App\Modules\Shared\Domain\ValueObject\KioskUpdateWindow;
use App\Modules\Shared\Domain\ValueObject\PayrollColumn;
use App\Modules\Shared\Domain\ValueObject\PayrollDateFormat;
use App\Modules\Shared\Domain\ValueObject\PayrollDelimiter;
use App\Modules\Shared\Domain\ValueObject\PayrollEncoding;
use App\Modules\Shared\Domain\ValueObject\PayrollHoursFormat;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;

/**
 * El catalogo de claves de configuracion de la instalacion (RF-PD-01, ADR-017).
 *
 * **Este enum es el catalogo.** No hay una clase `SettingCatalog` aparte porque
 * no habria nada dentro que no este ya aqui: el conjunto de claves es cerrado y
 * se conoce al compilar, y `SettingKey::cases()` lo recorre entero. Que sea un
 * tipo y no una cadena suelta es lo que impide que un modulo pida
 * `'ATTENDANC_MAX_SHIFT_HOURS'` y reciba el valor de serie durante meses sin que
 * nadie lo note.
 *
 * ## La convencion de nombres
 *
 * `MODULO_CONCEPTO` en mayusculas, **identica a la variable de entorno del Anexo
 * B del doc 02 cuando la propiedad tiene las dos caras**. No es coincidencia: la
 * variable de entorno es el valor de arranque con el que el instalador siembra
 * la primera fila, y la fila es la que manda a partir de ahi. Llamarlas igual es
 * lo que hace legible esa relacion y lo que permite el contraste cruzado que
 * pide la tarea 5.11 («cada parametro del Anexo B y cada clave de
 * `installation_settings` documentados»).
 *
 * Las cuatro claves `ATTENDANCE_*` de la tarea 1.3 **se conservan tal como las
 * sembro**: renombrarlas seria una migracion de datos a cambio de nada. La
 * quinta, `ATTENDANCE_BREAK_CLOCKING`, la anade la tarea 3.5 y sigue la misma
 * convencion. Son ademas identificadores tecnicos internos, y el doc 02 §5.8
 * dice que esos no se renombran.
 *
 * ## Que no esta aqui, y por que
 *
 * - **Los umbrales legales** —descanso minimo, jornada maxima, pausas,
 *   retencion— viven en `compliance_profiles` y llegan por
 *   `CompliancePolicyProvider`. Un umbral legal lo fija la jurisdiccion; uno
 *   operativo lo fija el hotel (doc 01 §4, nota sobre RN-08 y RN-16).
 * - **Las funcionalidades activas** las codifica `features` de la licencia
 *   (ADR-018, ADR-023) y no una clave editable por el administrador: si el
 *   cliente pudiera encenderlas desde el panel, la licencia no limitaria nada.
 * - **Lo que es del despliegue** —rutas, credenciales, endpoints— sigue siendo
 *   variable de entorno: no tiene sentido editarlo sin reiniciar y no se audita
 *   (ADR-017, alternativas descartadas).
 */
enum SettingKey: string
{
    /** RN-08: a partir de cuantas horas un tramo es anomalo. **Nunca se cierra solo**. */
    case ATTENDANCE_MAX_SHIFT_HOURS = 'ATTENDANCE_MAX_SHIFT_HOURS';

    /** RF-AT-06: ventana de gracia anti-rebote de un mismo empleado. */
    case ATTENDANCE_DEBOUNCE_SECONDS = 'ATTENDANCE_DEBOUNCE_SECONDS';

    /** RF-AT-10: desfase tolerado entre el reloj del quiosco y el del servidor. Nunca rechaza el fichaje. */
    case ATTENDANCE_MAX_CLOCK_SKEW_MINUTES = 'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES';

    /** RN-16: transito minimo creible entre dos quioscos del centro. */
    case ATTENDANCE_MIN_TRANSIT_SECONDS = 'ATTENDANCE_MIN_TRANSIT_SECONDS';

    /**
     * RF-PR-06: separacion por debajo de la cual dos fichajes de personas
     * distintas en el mismo quiosco cuentan como una coincidencia (tarea 3.11).
     *
     * Cero la desactiva. No rechaza ni marca ningun fichaje: solo decide que se
     * cuenta como coincidencia antes de mirar si se repite.
     */
    case ATTENDANCE_PATTERN_WINDOW_SECONDS = 'ATTENDANCE_PATTERN_WINDOW_SECONDS';

    /**
     * RF-PR-06: **dias** con coincidencia que tiene que acumular un par de
     * personas antes de que se abra la incidencia (tarea 3.11).
     *
     * Dias y no coincidencias sueltas: la cola del quiosco al cambio de turno
     * produce pares de segundos todos los dias entre companeros que llegan
     * juntos, y «sistematico» es «varios dias».
     */
    case ATTENDANCE_PATTERN_MIN_REPEATS = 'ATTENDANCE_PATTERN_MIN_REPEATS';

    /**
     * RF-AT-12: si el quiosco ofrece fichar la pausa en esta instalacion
     * (ADR-024, tarea 3.5).
     *
     * **«Configurable por centro» es esto**, y no una columna por centro: ADR-040
     * fija un centro por instalacion, asi que el ambito del centro y el de la
     * instalacion son el mismo (RF-PD-01).
     *
     * Activarlo hace dos cosas: el boton «Pausa» aparece en la tablet y **RN-12
     * vuelve a abrir incidencias** (`ComplianceRuleSuspension`). Por eso su
     * impacto es `COMPLIANCE_REVIEW` y no `PRESENTATION`: no mueve ni un minuto
     * del calculo de horas —la pausa son dos tramos y su tiempo simplemente no
     * esta en ninguno—, pero cambia que jornadas se marcan, y el asiento de
     * `installation_setting.changed` tiene que decirlo.
     */
    case ATTENDANCE_BREAK_CLOCKING = 'ATTENDANCE_BREAK_CLOCKING';

    /**
     * RF-PD-08: nombre de la aplicacion.
     *
     * **Se pinta desde la tarea 5.8**: la cabecera y el titulo de pestaÃ±a de las
     * tres SPA âque lo reciben por `GET /api/v1/branding`â, la tarjeta de
     * credencial, el informe sellado y la cabecera de la exportacion legal. Todos
     * lo reciben por el puerto `BrandingProvider`; ninguno lee esta tabla.
     */
    case BRANDING_APP_NAME = 'BRANDING_APP_NAME';

    /**
     * RF-PD-08: ruta del logotipo en el servidor del cliente.
     *
     * Del sistema de ficheros y no una URL: el PDF se genera en un Chromium sin
     * salida a internet (ADR-016).
     *
     * **EL FICHERO SE COMPRUEBA AL GUARDAR, NO AL LEER** (tarea 5.8). El `PATCH`
     * responde `422` si la ruta no es absoluta, se sale del directorio de marca,
     * no existe, no es PNG ni SVG por su contenido o pasa de los limites de
     * tamano: ahi hay una persona delante a la que decirle que arreglar, y ademas
     * es lo que impide que el endpoint publico del logotipo se convierta en una
     * lectura de cualquier fichero del servidor.
     *
     * **La lectura posterior es tolerante y tiene que serlo.** Si el fichero
     * desaparece un martes, los documentos salen sin logotipo y nadie se queda
     * sin fichar (regla dura 19). Quien lo avisa es `doctor` (tarea 5.9), que es
     * donde un aviso sirve para algo.
     *
     * La cadena vacia significa Â«el logotipo del productoÂ» y no se comprueba
     * contra nada.
     */
    case BRANDING_LOGO_PATH = 'BRANDING_LOGO_PATH';

    /**
     * RF-PD-08: color de acento, en notacion CSS.
     *
     * **Se pinta desde la tarea 5.8**: el filete de la tarjeta impresa, la
     * cabecera del informe sellado y, en las tres SPA, los tokens `--kq-*` de la
     * familia primaria, derivados en tiempo de ejecucion. No se recompila nada
     * por cliente (ADR-017).
     *
     * `GET /api/v1/branding` publica `null` mientras rija el valor de serie, para
     * que las SPA no toquen ningun token y el sistema visual del doc 06 quede
     * intacto. **La distincion la hace `GetBrandingHandler` comparando el VALOR
     * con el de serie** —insensible a mayusculas—, y no preguntando de donde sale
     * la fila: asi «no lo ha configurado» y «su plan no incluye la marca blanca»
     * se ven igual desde fuera, que es lo que promete ADR-023. Aqui siempre hay
     * un color.
     */
    case BRANDING_ACCENT_COLOR = 'BRANDING_ACCENT_COLOR';

    /** Idioma con el que se sirven las SPA y los documentos cuando nadie elige otro. */
    case LOCALE_DEFAULT = 'LOCALE_DEFAULT';

    /** Idiomas que la instalacion ofrece. El producto se entrega con dos (DoD §10.3: textos en español e ingles). */
    case LOCALE_AVAILABLE = 'LOCALE_AVAILABLE';

    /**
     * RF-KI-08: el codigo con el que se abre la pantalla de diagnostico de la
     * tablet (tarea 3.3, decision 6).
     *
     * **Numerico de 8 a 12 cifras** porque la tablet solo tiene el teclado en
     * pantalla de `PinNumericKeypad`: un codigo con letras seria un codigo que
     * nadie puede teclear donde hay que teclearlo.
     *
     * **Vacio de serie**, y el vacio significa «la pantalla se abre sin codigo».
     * Una tablet sin emparejar no tiene nada que proteger —ni token, ni padron,
     * ni cola con jornadas— y es justo la que hay que diagnosticar (regla dura
     * 19): el producto no se entrega con un codigo de fabrica que todo el mundo
     * conoceria.
     *
     * **El quiosco no recibe nunca el codigo**, solo su huella
     * `sha256("{uuid}:{codigo}")` en cada `POST /api/v1/kiosk/heartbeat`
     * (`service_code_hash`), y comprueba el codigo en local para que la pantalla
     * funcione sin red.
     *
     * **Es la unica clave `confidential` del catalogo:** su valor no se copia al
     * asiento de auditoria de su propio cambio —queda constancia de que cambio,
     * de quien y de cuando— ni entra en el paquete de diagnostico ni en ningun
     * log. `KIOSK_SERVICE_CODE` no esta en `DiagnosticsConfigurationAllowlist`,
     * que solo deja pasar de la familia `KIOSK_` los techos de peticiones.
     *
     * Impacto `PRESENTATION`: no mueve ni un minuto del registro horario. Lo que
     * cambia es quien puede abrir una pantalla que no muestra ningun dato
     * personal.
     */
    case KIOSK_SERVICE_CODE = 'KIOSK_SERVICE_CODE';

    /**
     * RF-IN-07: **que columnas lleva la salida a nomina, en que orden y con que
     * rotulo**.
     *
     * ## Es configuracion porque el formato es del cliente, no del producto
     *
     * El doc 05 §5.4 promete «exportacion de horas en el formato que necesite la
     * herramienta de nomina del hotel». Cada hotel tiene la suya —A3, Sage,
     * Meta4, la hoja que le mantiene su gestoria— y cada una espera unas columnas
     * distintas en un orden distinto con unos nombres de campo distintos. Si eso
     * viviera en el codigo, vender a un cliente nuevo obligaria a tocar el
     * repositorio, que es literalmente lo que ADR-017 y la regla dura 13
     * prohiben, y a la tercera venta habria una rama por cliente.
     *
     * Lo que **no** es del cliente es el significado de cada columna: eso lo fija
     * el producto y por eso el catalogo de identificadores es cerrado
     * ({@see PayrollColumn}). El cliente elige **cuales**, **en que orden** y
     * **como se llaman**; nunca **que miden**.
     *
     * ## Impacto `PRESENTATION`, y no es un descuido
     *
     * Ninguna de las seis claves `PAYROLL_EXPORT_*` mueve un minuto ni abre una
     * incidencia: el informe que se exporta es exactamente el mismo con cualquier
     * plantilla, y lo unico que cambia es como se escribe en el fichero. El
     * asiento de `installation_setting.changed` se escribe igual —RL-04 no
     * distingue— pero con `affects_worked_hours: false`, que es la verdad.
     */
    case PAYROLL_EXPORT_COLUMNS = 'PAYROLL_EXPORT_COLUMNS';

    /** RF-IN-07: separador de campos del fichero de nomina. Ver {@see PayrollDelimiter}. */
    case PAYROLL_EXPORT_DELIMITER = 'PAYROLL_EXPORT_DELIMITER';

    /**
     * RF-IN-07: como se escribe una duracion en el fichero de nomina.
     *
     * **La unica excepcion del producto al «horas como `HH:MM`, nunca decimal»**
     * del paso 6 de `/informe-nuevo`, y esta razonada en
     * {@see PayrollHoursFormat}: esa regla protege a quien lee, y este fichero lo
     * lee un programa que multiplica por un precio hora. `hhmm` sigue siendo el
     * valor de serie.
     */
    case PAYROLL_EXPORT_HOURS_FORMAT = 'PAYROLL_EXPORT_HOURS_FORMAT';

    /** RF-IN-07: `AAAA-MM-DD` o `DD/MM/AAAA`. Ver {@see PayrollDateFormat}. */
    case PAYROLL_EXPORT_DATE_FORMAT = 'PAYROLL_EXPORT_DATE_FORMAT';

    /**
     * RF-IN-07: codificacion del fichero de nomina.
     *
     * `latin1` existe porque los programas de nomina del sector llevan decadas
     * instalados y varios solo importan ISO-8859-1. Ver {@see PayrollEncoding}.
     */
    case PAYROLL_EXPORT_ENCODING = 'PAYROLL_EXPORT_ENCODING';

    /**
     * RF-IN-07: si el fichero de nomina lleva fila de rotulos.
     *
     * `enabled` de serie —es lo que espera una persona que lo abra para
     * comprobarlo— y `disabled` para los importadores que tratan la primera linea
     * como datos y acaban dando de alta a un empleado llamado «Codigo».
     */
    case PAYROLL_EXPORT_HEADER_ROW = 'PAYROLL_EXPORT_HEADER_ROW';

    /**
     * RF-PR-05: si la instalacion envia el **resumen semanal por correo** al
     * responsable de cada departamento (tarea 3.12).
     *
     * **`disabled` de serie**, porque el doc 05 §5.7 lo vende como «correo
     * opcional» y porque lo que sale por SMTP son nombres de la plantilla: una
     * instalacion recien puesta en marcha no manda datos personales a ninguna
     * parte hasta que alguien lo decide en el panel.
     *
     * `choice` de dos valores y no un tipo booleano nuevo, por el mismo motivo
     * que {@see self::ATTENDANCE_BREAK_CLOCKING}: una sola clave no justifica
     * ampliar `SettingType`, y el dia que haya un tercer valor —«solo a RRHH»—
     * el enumerado ya lo admite sin migrar lo guardado.
     *
     * Impacto `DATA_DISCLOSURE`, y es la unica clave que lo lleva: no mueve ni
     * un minuto del registro ni abre ninguna incidencia, pero **enciende una
     * salida de datos personales de la instalacion**. Lo que sale cada lunes son
     * nombres y horas de la plantilla hacia el buzon de un responsable, que es
     * una copia fuera del producto. En el asiento de `installation_setting.changed`
     * no puede quedar en el mismo cajon que un color de marca.
     */
    case WEEKLY_SUMMARY_EMAIL = 'WEEKLY_SUMMARY_EMAIL';

    /**
     * RF-KI-07: la franja en la que una tablet puede aplicar una version nueva
     * de la PWA, `HH:MM-HH:MM` **en hora local del centro** (tarea 3.12).
     *
     * `03:00-05:00` de serie, y **puede cruzar la medianoche**. Con los dos
     * extremos iguales la franja no se abre nunca, que es la forma de decir «mis
     * tablets las actualizo yo». La forma y su significado los fija
     * {@see KioskUpdateWindow}, que es de
     * donde sale tambien la expresion regular con la que se valida aqui: una
     * segunda copia escrita a mano acabaria admitiendo lo que el objeto rechaza.
     *
     * **Es una ventana de permiso, no de bloqueo** (regla dura 19): fuera de
     * ella el quiosco sigue fichando y encolando; lo unico que no hace es
     * recargarse. Y se **declara**, no se infiere del historico de escaneos: el
     * producto no adivina el cambio de turno (regla dura 13).
     */
    case KIOSK_UPDATE_WINDOW = 'KIOSK_UPDATE_WINDOW';

    /**
     * RF-KI-07: minutos sin **ningun escaneo** que la tablet exige, ademas de la
     * franja, antes de aplicar una version pendiente (tarea 3.12).
     *
     * Cero la desactiva. El maximo son dos horas: una guarda mayor que la propia
     * ventana de serie la dejaria cerrada para siempre en un hotel con actividad
     * de madrugada, y la tablet no volveria a actualizarse sin que nadie
     * entendiera por que.
     */
    case KIOSK_UPDATE_QUIET_MINUTES = 'KIOSK_UPDATE_QUIET_MINUTES';

    /**
     * Los idiomas que el producto trae traducidos.
     *
     * No es configuracion del cliente: es lo que hay en `lang/` y en los `i18n`
     * de las tres SPA. Ofrecer uno que no existe daria una interfaz a medio
     * traducir, asi que el conjunto es cerrado y crece cuando crece la
     * traduccion, no cuando lo pide un cliente.
     */
    private const array SHIPPED_LOCALES = ['es', 'en'];

    /**
     * La clave, o {@see UnknownSettingKey}.
     *
     * Es el unico punto por el que una cadena de fuera —el cuerpo de un `PATCH`,
     * un argumento de consola— se convierte en clave. Que falle aqui y no mas
     * adentro es lo que hace que el 422 diga cual es la clave mala.
     */
    public static function fromString(string $key): self
    {
        return self::tryFrom($key) ?? throw new UnknownSettingKey($key);
    }

    /** Que puede valer esta clave, cuanto vale de serie y que es cuando cambia. */
    public function definition(): SettingDefinition
    {
        return self::catalog()[$this->value] ?? throw new UnknownSettingKey($this->value);
    }

    /**
     * El catalogo, literal.
     *
     * Un array y no un `match`: con un brazo por clave, la complejidad
     * ciclomatica del metodo crece con el catalogo y choca con el limite de 10
     * del §3.5 en la decima clave. Un literal no tiene puntos de decision, y
     * `SettingCatalogTest` comprueba que no falte ninguno.
     *
     * @return array<string, SettingDefinition>
     */
    private static function catalog(): array
    {
        return [
            // 12 h, el valor del Anexo B y el que sembro la tarea 1.3. En horas
            // y no en minutos porque asi lo dice el negocio; la conversion la
            // hace el adaptador, en el borde.
            self::ATTENDANCE_MAX_SHIFT_HOURS->value => SettingDefinition::integer(
                12, 1, 24, SettingImpact::COMPLIANCE_REVIEW,
            ),
            // Cero es legitimo: apaga el anti-rebote. El maximo es una hora,
            // porque una ventana mayor se comeria un fichaje real.
            self::ATTENDANCE_DEBOUNCE_SECONDS->value => SettingDefinition::integer(
                60, 0, 3600, SettingImpact::WORKED_HOURS,
            ),
            // Minimo 1: `OperationalSettings` rechaza el cero, porque un cero
            // marcaria incidencia ante un segundo de deriva en cada escaneo.
            self::ATTENDANCE_MAX_CLOCK_SKEW_MINUTES->value => SettingDefinition::integer(
                15, 1, 1440, SettingImpact::COMPLIANCE_REVIEW,
            ),
            // Cero es legitimo: dos tablets contiguas en la misma puerta.
            self::ATTENDANCE_MIN_TRANSIT_SECONDS->value => SettingDefinition::integer(
                120, 0, 3600, SettingImpact::COMPLIANCE_REVIEW,
            ),
            // RF-PR-06. Cero es legitimo: apaga la coincidencia de quiosco. El
            // maximo son cinco minutos, porque una ventana mayor deja de
            // describir «fichajes consecutivos separados por segundos» y empieza
            // a emparejar a todo el turno que entra a la misma hora.
            self::ATTENDANCE_PATTERN_WINDOW_SECONDS->value => SettingDefinition::integer(
                10, 0, 300, SettingImpact::COMPLIANCE_REVIEW,
            ),
            // RF-PR-06. Minimo 1 y no 0: «sistematico» con cero repeticiones no
            // significa nada. Para apagar el hallazgo se pone la ventana a cero.
            self::ATTENDANCE_PATTERN_MIN_REPEATS->value => SettingDefinition::integer(
                3, 1, 30, SettingImpact::COMPLIANCE_REVIEW,
            ),
            // `choice` de dos valores y no un tipo booleano nuevo: anadir
            // `SettingType::BOOLEAN` obligaria a ampliar el enum, el contrato,
            // la validacion y el panel para una sola clave, y el dia que haya
            // una tercera opcion —«solo en el quiosco de cocina»— el enumerado
            // ya la admite sin migrar el valor guardado (decision 7 de la ficha
            // 3.5).
            //
            // **`disabled` de serie.** El doc 05 lo vende como opcional, y
            // arrancar en `enabled` reactivaria RN-12 en una plantilla que
            // descansa sin fichar: incidencias contra gente que no hizo nada
            // mal, el primer dia de uso.
            self::ATTENDANCE_BREAK_CLOCKING->value => SettingDefinition::choice(
                'disabled', ['enabled', 'disabled'], SettingImpact::COMPLIANCE_REVIEW,
            ),
            // El valor por defecto **es** el producto (tarea 5.8, paso 8): sin
            // configurar nada se ve la marca del fabricante, nunca la de otro
            // cliente. 60 caracteres es lo que cabe en la cabecera de la tarjeta.
            self::BRANDING_APP_NAME->value => SettingDefinition::text(
                'KronoQR', 60, SettingImpact::PRESENTATION,
            ),
            // Vacia significa «el logotipo del producto». Ruta del sistema de
            // ficheros y no URL: el PDF se genera en un Chromium sin salida a
            // internet (ADR-016). Que el fichero exista lo comprueba el
            // adaptador, no el dominio.
            self::BRANDING_LOGO_PATH->value => SettingDefinition::optionalText(
                '', 512, SettingImpact::PRESENTATION,
            ),
            // El `primary-strong` del doc 06, que es el «texto de marca» del
            // producto. Cambio de la 5.8: era `#111827`, un gris neutro que no era
            // la marca de nadie —ni del producto—, y con el la tarjeta impresa
            // salia de serie con un filete gris. El valor por defecto ES el
            // producto (paso 8 de la tarea). Se valida la forma para que un color
            // mal escrito de un 422 y no una interfaz sin estilo.
            self::BRANDING_ACCENT_COLOR->value => SettingDefinition::text(
                '#b8542a', 7, SettingImpact::PRESENTATION, '/^#[0-9a-fA-F]{6}$/',
            ),
            self::LOCALE_DEFAULT->value => SettingDefinition::choice(
                'es', self::SHIPPED_LOCALES, SettingImpact::PRESENTATION,
            ),
            self::LOCALE_AVAILABLE->value => SettingDefinition::choiceList(
                self::SHIPPED_LOCALES, self::SHIPPED_LOCALES, SettingImpact::PRESENTATION,
            ),
            // Vacio de serie: sin codigo, la pantalla de diagnostico de la
            // tablet se abre sin el (RF-KI-08). Solo cifras y de 8 a 12 porque
            // se teclea en el teclado numerico del quiosco; el maximo de 12 es
            // el mismo del patron y no una longitud de columna.
            //
            // `confidential`: su valor no viaja al asiento de auditoria ni al
            // paquete de diagnostico. Ver el docblock de la clave.
            self::KIOSK_SERVICE_CODE->value => SettingDefinition::optionalText(
                '', 12, SettingImpact::PRESENTATION, '/^[0-9]{8,12}$/', confidential: true,
            ),
            // LA SALIDA A NOMINA (RF-IN-07, tarea 3.9). Las seis son
            // `PRESENTATION`: cambian como se escribe el fichero, nunca lo que
            // el informe calcula.
            //
            // **Los valores de serie y el catalogo de columnas viven en
            // `Shared/Domain`** y no aqui, porque los necesitan los dos lados de
            // la frontera —`Reporting` para escribir el fichero y `Product` para
            // construir la plantilla— y ninguno puede importar al otro (doc 02
            // §1.6, ADR-025). Copiarlos aqui daria dos plantillas de serie y
            // ganaria la que nadie mira.
            self::PAYROLL_EXPORT_COLUMNS->value => SettingDefinition::labelledChoiceList(
                PayrollLayout::DEFAULT_COLUMNS,
                PayrollColumn::ids(),
                SettingImpact::PRESENTATION,
                PayrollLayout::MAXIMUM_LABEL_LENGTH,
            ),
            self::PAYROLL_EXPORT_DELIMITER->value => SettingDefinition::choice(
                PayrollDelimiter::Semicolon->value, PayrollDelimiter::names(), SettingImpact::PRESENTATION,
            ),
            self::PAYROLL_EXPORT_HOURS_FORMAT->value => SettingDefinition::choice(
                PayrollHoursFormat::HoursMinutes->value, PayrollHoursFormat::names(), SettingImpact::PRESENTATION,
            ),
            self::PAYROLL_EXPORT_DATE_FORMAT->value => SettingDefinition::choice(
                PayrollDateFormat::Iso->value, PayrollDateFormat::names(), SettingImpact::PRESENTATION,
            ),
            self::PAYROLL_EXPORT_ENCODING->value => SettingDefinition::choice(
                PayrollEncoding::Utf8Bom->value, PayrollEncoding::names(), SettingImpact::PRESENTATION,
            ),
            self::PAYROLL_EXPORT_HEADER_ROW->value => SettingDefinition::choice(
                PayrollLayout::HEADER_ROW_ENABLED,
                [PayrollLayout::HEADER_ROW_ENABLED, PayrollLayout::HEADER_ROW_DISABLED],
                SettingImpact::PRESENTATION,
            ),
            // EL RESUMEN SEMANAL (RF-PR-05, tarea 3.12). Apagado de serie: el
            // doc 05 §5.7 lo vende como «correo opcional» y lo que sale por SMTP
            // son nombres de la plantilla.
            //
            // **`DATA_DISCLOSURE` y no `PRESENTATION`** (decision 14 de la
            // segunda vuelta): encenderla saca cada lunes nombres y horas de la
            // plantilla por SMTP. Es el cambio con mas consecuencias en
            // privacidad que se puede hacer desde el panel, y en el asiento no
            // puede quedar en el mismo cajon que un logotipo.
            self::WEEKLY_SUMMARY_EMAIL->value => SettingDefinition::choice(
                'disabled', ['enabled', 'disabled'], SettingImpact::DATA_DISCLOSURE,
            ),
            // LA VENTANA DE ACTUALIZACION DE LA TABLET (RF-KI-07, tarea 3.12).
            // La forma la presta el objeto de valor, que es quien la garantiza
            // de verdad: aqui solo se copia para que un valor mal escrito de un
            // `422` con una persona delante, en vez de un fallo mas adentro. Los
            // 11 caracteres son los de `HH:MM-HH:MM`, ni uno mas.
            self::KIOSK_UPDATE_WINDOW->value => SettingDefinition::text(
                '03:00-05:00', 11, SettingImpact::PRESENTATION, KioskUpdateWindow::SHAPE,
            ),
            // Cero es legitimo: apaga la guarda de silencio y deja mandar a la
            // franja y a la cola vacia. El maximo son dos horas, porque una
            // guarda mayor que la ventana de serie la dejaria cerrada para
            // siempre en un hotel con actividad de madrugada.
            self::KIOSK_UPDATE_QUIET_MINUTES->value => SettingDefinition::integer(
                10, 0, 120, SettingImpact::PRESENTATION,
            ),
        ];
    }
}
