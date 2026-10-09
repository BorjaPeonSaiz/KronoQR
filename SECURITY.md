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

La matriz de versiones soportadas es la de
[`infra/versions.txt`](infra/versions.txt): reciben correcciones de seguridad la
**versión menor vigente y las dos anteriores de la misma versión mayor**
(con la 2.4.0 publicada: 2.4.x, 2.3.x y 2.2.x). **Hoy, con la 2.2.0 como
versión vigente, son la 2.2.x, la 2.1.x y la 2.0.x**, y la recomendación es
estar en la 2.2.x: es la que trae el segundo factor obligatorio para los
responsables, el bloqueo por origen del portal y las copias autenticadas. Una
instalación fuera de esa matriz tiene que actualizar primero con `update.sh`;
el script le indica la versión intermedia.

| Versión | ¿Recibe parches de seguridad? |
| --- | --- |
| La menor vigente y las **dos anteriores** de la mayor vigente | Sí |
| Cualquier otra | No: actualiza con `update.sh` ([`docs/runbooks/actualizacion-cliente.md`](docs/runbooks/actualizacion-cliente.md)) |

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
