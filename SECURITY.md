# Política de seguridad

KronoQR es un producto de registro horario con valor legal que se despliega en
el servidor de cada cliente. Una vulnerabilidad puede comprometer datos
personales de los empleados o la validez del registro, así que se atiende con
prioridad sobre cualquier otro trabajo.

## Cómo notificar una vulnerabilidad

**No abras una incidencia pública, ni un *pull request*, ni publiques el
detalle** hasta que el fabricante confirme que hay corrección disponible.

Notifícala **en privado** por correo a **[contacto@kodigolab.es](mailto:contacto@kodigolab.es)**, con «Seguridad
KronoQR» en el asunto. Responsable: Borja Peón Saiz.

Si eres **cliente**, puedes usar además el canal de soporte de tu licencia, con
la misma regla: sin datos personales en el mensaje.

### Qué incluir

- Versión afectada (`GET /api/v1/health` la publica) y cómo se instaló.
- Qué se ve y qué se esperaba; pasos mínimos para reproducirlo.
- Impacto que crees que tiene (¿datos de empleados? ¿el fichaje? ¿el registro
  de auditoría?).

### Qué NO incluir

- **Datos personales de empleados**: ni nombres, ni códigos de empleado, ni
  capturas del panel con datos reales. Si necesitas ilustrar algo, usa datos
  inventados.
- **Secretos del cliente**: ni el `.env`, ni `BACKUP_ENCRYPTION_KEY`, ni claves
  de licencia, ni copias de seguridad. **El fabricante no los necesita y no
  accede a los datos de un cliente** salvo concesión expresa, temporal y
  auditada (ADR-020). Si la reproducción exige datos del cliente, el fabricante
  lo acordará contigo por escrito antes.

## Qué puedes esperar de nosotros

| Hito | Plazo |
| --- | --- |
| Acuse de recibo | 48 horas |
| Valoración inicial (¿es una vulnerabilidad? ¿qué gravedad?) | 72 horas |
| Corrección de una vulnerabilidad **crítica o alta** | 30 días naturales desde la valoración, o un plan de mitigación comunicado en ese plazo |
| Corrección de gravedad media o baja | En la siguiente versión menor |

- Te mantendremos informado del estado y te diremos si no coincidimos con tu
  valoración.
- Si el hallazgo puede constituir una **brecha de seguridad de datos
  personales** en la instalación de un cliente, el cliente (responsable del
  tratamiento) dispone de **72 horas** para notificarla desde que tiene
  conocimiento (art. 33 RGPD). Por eso, lo que afecte a un cliente concreto se
  le comunica **sin esperar a la corrección**. El procedimiento está en
  [`docs/runbooks/brecha-de-seguridad.md`](docs/runbooks/brecha-de-seguridad.md).
- Agradeceremos tu aviso en las notas de la versión si lo deseas. No hay
  programa de recompensas.
- Divulgación coordinada: publicamos el detalle junto con la versión corregida.

## Versiones que reciben parches

**Hoy reciben correcciones de seguridad solo las versiones 2.2.x.** La 2.1.0 y
la 2.0.0 se publicaron, pero no se llegaron a vender a ningún cliente, así que
no tienen soporte de seguridad: quien tenga una de ellas en una instalación de
prueba o de demostración actualiza a la 2.2.x con `update.sh`. La 2.2.x es además la que trae el
segundo factor obligatorio para los responsables, el bloqueo por origen del
portal y las copias autenticadas.

**La regla para las versiones siguientes.** Reciben parches la **versión menor
vigente y, como mucho, las dos anteriores de la misma versión mayor**, y de
esas, **solo las que se hayan vendido a algún cliente**. Una versión que ningún
cliente ha recibido no entra en el soporte, aunque esté publicada y aunque por
número le tocara. Con la 2.4.0 vigente, el máximo sería 2.4.x, 2.3.x y 2.2.x.
Esta tabla se actualiza al publicar cada versión menor.

| Versión | ¿Recibe parches de seguridad? |
| --- | --- |
| 2.2.x | Sí |
| 2.1.0 y 2.0.0 (no vendidas a ningún cliente) | No: actualiza a la 2.2.x con `update.sh` ([`docs/runbooks/actualizacion-cliente.md`](docs/runbooks/actualizacion-cliente.md)) |
| Cualquier otra | No |

La matriz de [`infra/versions.txt`](infra/versions.txt) es otra cosa: dice
**desde qué versiones puede actualizar** `update.sh` y por qué versiones
intermedias pasa, no cuáles reciben parches. Una instalación fuera de esa
matriz tiene que actualizar primero a la versión intermedia que el script le
indica.

Cada versión publicada es **inmutable**: una corrección se entrega como una
versión nueva (por ejemplo, un parche), nunca reescribiendo una ya publicada.
Las imágenes del paquete de entrega se fijan por *digest*.

## Alcance

**Dentro del alcance:** el código y la configuración de este repositorio, las
imágenes y el paquete de entrega publicados, y la documentación entregada al
cliente.

**Fuera del alcance:** el sistema operativo, la red, el certificado y las
tablets del cliente (su endurecimiento lo cubre
[`docs/cliente/endurecimiento.md`](docs/cliente/endurecimiento.md)); ataques que
exigen acceso físico o root al servidor; denegación de servicio por volumen; y
los hallazgos automáticos sin ruta de explotación.

## Cómo gestionamos nosotros las vulnerabilidades conocidas

- Cada integración ejecuta análisis estático (Semgrep), de dependencias y de
  imágenes (Trivy), y de secretos; una publicación no sale con vulnerabilidades
  críticas o altas conocidas en dependencias ni en las imágenes. El triaje de un
  hallazgo automático está en
  [`docs/runbooks/triaje-hallazgos-seguridad.md`](docs/runbooks/triaje-hallazgos-seguridad.md).
- Una vulnerabilidad confirmada se corrige con prueba que falla antes y pasa
  después, y se anota en el `CHANGELOG.md` de la versión que la corrige.
- La autoevaluación de madurez y los riesgos aceptados están en
  [`docs/07-seguridad-madurez-y-amenazas.md`](docs/07-seguridad-madurez-y-amenazas.md).
