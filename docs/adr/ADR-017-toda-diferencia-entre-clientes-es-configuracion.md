# ADR-017 — Toda diferencia entre clientes es configuración, nunca código

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `producto-licencia` con `arquitecto-dominio` |
| **Afecta a** | Tareas 5.1, 5.2, 5.5 y 5.8 · [ADR-025](ADR-025-frontera-de-dependencias-del-nucleo.md) · **Reglas duras 13 y 14** de `CLAUDE.md` |
| **Requisitos** | RF-PD-01, RF-PD-03, RF-PD-07, RF-PD-08, RN-08, RN-10, RN-11, RN-12, RN-16, RL-11 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

El producto se instala en el servidor de cada cliente ([ADR-016](ADR-016-producto-licenciado-on-premise.md)), y cada cliente es distinto en cosas que parecen menores hasta que se acumulan: su marca, sus idiomas, su convenio, sus umbrales de descanso, la distancia entre sus quioscos, los años que conserva los datos.

La salida que aparece sola cuando aparece el segundo cliente es una rama. La del tercero es un `if` con su nombre. Y a partir de ahí:

- Una corrección de seguridad hay que aplicarla N veces y verificarla N veces.
- Nadie sabe qué versión tiene realmente cada instalación.
- **Vender deja de ser vender: pasa a ser un proyecto de desarrollo**, y el margen desaparece.

Hay además una razón de dominio, no solo de negocio: los umbrales de RN-10, RN-11 y RN-12 provienen del Estatuto de los Trabajadores, pero **son parámetros de la jurisdicción y del convenio**, no verdades del código. Escribirlos como constantes obliga a tocar el repositorio cuando cambie una norma o cuando el cliente sea de otro convenio, que son exactamente las dos cosas que van a pasar.

## Decisión

**Toda diferencia entre clientes es dato. Vender a un cliente nuevo no puede exigir tocar el repositorio, y nunca hay una rama por cliente.**

Cuatro mecanismos, cada uno con su sitio:

1. **`installation_settings`**, con ámbito (`installation` o `site`) y resolución en cascada (RF-PD-01): marca, idiomas, umbrales operativos y funcionalidades. Todo cambio queda auditado, porque algunos afectan al cálculo de horas.

   > **Enmienda 31-08-2026 (tarea 5.1).** Dos correcciones sobre la redacción original, que se
   > conserva porque es la decisión tal como se tomó:
   >
   > - **Un solo ámbito.** El ámbito `site` nunca llegó a usarse y [ADR-040](ADR-040-un-centro-por-instalacion-y-por-licencia.md)
   >   lo dejó sin sentido: hay exactamente un centro por instalación. La migración de contracción
   >   `2026_09_05_100000` retiró `scope` y `scope_id`, y la cascada quedó en **dos escalones: fila de
   >   instalación → valor por defecto del catálogo en código** (`SettingKey`). Un escalón que siempre
   >   resuelve al mismo sitio no es una cascada.
   > - **Las funcionalidades activas NO son claves de `installation_settings`.** Las gobierna
   >   `features` de la licencia, que es el mecanismo 3 de esta misma lista
   >   ([ADR-018](ADR-018-licencia-firmada-con-verificacion-local.md),
   >   [ADR-023](ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md)). Si el cliente pudiera
   >   encenderlas desde el panel, la licencia no limitaría nada. `installation_settings` cubre
   >   **marca, idiomas y umbrales operativos**, y nada más.
2. **Perfiles de cumplimiento** (`compliance_profiles`, RF-PD-07): jurisdicción, años de retención, descanso mínimo, jornada máxima diaria y semanal, pausas obligatorias, inicio de semana y calendario de festivos. Se entrega el perfil `ES-hosteleria` de serie.
3. **`features` de la licencia**: qué funcionalidad accesoria está habilitada ([ADR-023](ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md)).
4. **Marca blanca** (RF-PD-08): logotipo, colores y nombre aplicados al quiosco, al panel, al portal y a los PDF.

**La distinción entre umbral legal y umbral operativo no es cosmética.** El legal lo fija la jurisdicción y vive en el perfil de cumplimiento; el operativo lo fija el hotel y vive en `installation_settings`: la duración anómala de un tramo (RN-08) y el tiempo de tránsito entre dos quioscos (RN-16) son suyos. Son dos puertos distintos porque son dos fuentes distintas (ADR-025).

**Y el dominio recibe el umbral ya resuelto** (regla dura 14). Nunca consulta la configuración: se lo entrega el caso de uso a través de `CompliancePolicyProvider` u `OperationalSettingsProvider`. Lo invariable es la forma de la regla; configurable es el número.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Una rama por cliente** | Destruye la economía del producto en el tercer cliente e impide publicar una corrección de seguridad de forma fiable. Prohibido por la regla dura 13 |
| **Umbrales legales como constantes del código** | Convierte un cambio normativo o un convenio distinto en una versión nueva del producto. Y hace imposible probar RN-10..12 con varios perfiles |
| **Configuración solo por variables de entorno** | Sirve para lo que es del despliegue (rutas, credenciales, zona), no para lo que es del negocio: no tiene ámbito por centro, no se puede editar desde el panel, no se audita y obliga a reiniciar. Los dos mecanismos conviven, cada uno en su terreno |
| **Un módulo de personalización por cliente («plugins»)** | Código específico con otro nombre. Habría que versionarlo, probarlo y actualizarlo con el producto, y volveríamos a mantener N productos |
| **El dominio consulta la configuración cuando la necesita** | Rompe la pureza de `Domain/` y hace las pruebas dependientes de infraestructura. El umbral entra como dato de entrada del cálculo, que además es como se prueba bien |

## Consecuencias

- **Los umbrales legales dejan de ser constantes** y pasan a ser entrada del cálculo. Las pruebas de RN-10, RN-11 y RN-12 se escriben con el umbral inyectado, lo que además las hace más claras: dicen qué regla comprueban y con qué número.
- **Cambiar configuración que afecta al cálculo de horas se audita** (RL-04 por extensión): un cambio de perfil de cumplimiento altera qué se considera incumplimiento, y eso tiene que quedar registrado con autor y momento.
- **La puesta en marcha gana peso** (RF-PD-03, tarea 5.5): organización, centros, departamentos, zona horaria, perfil de convenio, primer administrador y primer quiosco. El asistente **debe preguntar el tiempo de tránsito entre quioscos** (RN-16): el valor de serie no puede asumir distancias.
- **La marca blanca atraviesa las tres aplicaciones y los PDF** (tarea 5.8), lo que obliga a que ningún componente lleve el nombre del producto escrito a mano.
- **Aparece un modo de fallo nuevo: la configuración incorrecta.** Un umbral mal puesto produce alertas de cumplimiento erróneas, así que los valores llevan validación y valores de serie sensatos, y `doctor.sh` los revisa.
- **Los identificadores técnicos internos no son configurables** y se mantienen deliberadamente —`FH1`, nombres de servicios y de base de datos—: no los ve el usuario y renombrarlos invalidaría credenciales impresas.

## Verificación

- Prueba de arquitectura: ninguna clase de `Domain/` lee configuración, `config()`, `env()` ni consulta `installation_settings`. El umbral llega como parámetro.
- Prueba unitaria: la misma regla RN-10 evaluada con dos perfiles distintos produce resultados distintos, sin cambiar código.
- Prueba de integración: la resolución en cascada devuelve **la fila de instalación cuando existe y el valor de serie del catálogo cuando no** (RF-PD-01). *(Enmienda 31-08-2026, tarea 5.1: la redacción anterior hablaba del valor de centro, que ya no existe — ver la enmienda del punto 1 de «Decisión». La prueba vive en `tests/Feature/Product/InstallationSettingsTest.php`, y la unitaria de la cascada pura en `tests/Unit/Product/Domain/ResolvedSettingsTest.php`.)*
- Prueba de integración: cambiar un valor del perfil de cumplimiento deja entrada en `audit_log` con autor y momento.
- Búsqueda en el árbol: cero nombres de cliente, cero umbrales legales escritos como literal en el código, cero condicionales por cliente.
- Prueba de *feature*: la marca configurada aparece en quiosco, panel, portal y PDF, y el valor por defecto es el del producto (RF-PD-08).
