# Probar AgroConecta en tu equipo

El entorno local permite probar el recorrido hasta la fase 19: productores, ofertas, lotes, cotizaciones, reservas, compras, acopio, entregas, cobros manuales, liquidaciones, trazabilidad con QR, reportes, controles de seguridad y restauración aislada. WhatsApp oficial y SMS siguen pendientes de validación externa; las operaciones comerciales preparadas son sintéticas. Consulta README para el estado y evidencia de cada entrega.

## Iniciar

En esta instalación ya preparada, abre Docker Desktop y ejecuta desde `E:\VSCODE\Agroconecta` una sola línea:

```powershell
docker compose -f docker-compose.yml -f docker-compose.evolution.yml --profile workers --profile mail --profile messaging up -d
```

Abre [AgroConecta](http://localhost:8080) o [la orden de demostración](http://localhost:8080/commerce/orders/1). No necesitas ejecutar el script en cada arranque. Los pasos siguientes son para preparar una instalación o aplicar actualizaciones con nuevas migraciones, dependencias o recursos.

Abre Docker Desktop y espera a que el motor de contenedores Linux esté disponible. PHP, Composer, Node.js/npm, PostgreSQL y Redis se ejecutan dentro de Docker; `start.ps1` compila con el servicio temporal `assets` y dependencias en volúmenes Linux.

En PowerShell:

```powershell
Set-Location 'E:\VSCODE\Agroconecta'
.\scripts\start.ps1
```

También puedes invocar el script por su ruta completa desde otra carpeta. El script utiliza automáticamente la raíz del proyecto.

Si Windows bloquea la ejecución de scripts, puedes habilitarla únicamente para esa terminal y volver a ejecutar el comando:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\scripts\start.ps1
```

El primer inicio puede tardar varios minutos por la descarga y construcción de imágenes. El script muestra ocho pasos y se detiene ante errores. Crea los archivos locales de configuración cuando no existen, conserva la clave de Laravel existente, instala dependencias, aplica migraciones pendientes y prepara la base independiente `agroconecta_testing`. Después compila la interfaz, inicia los procesos y ejecuta el diagnóstico de salud. La comprobación final puede esperar hasta 75 segundos para observar la primera actividad del scheduler. No reinicia la base de datos ni carga datos comerciales ficticios.

En cada inicio se recrean únicamente el worker y el scheduler para que carguen el código actualizado. Se respeta su período de parada ordenada y se conservan los trabajos pendientes en Redis. Si existe `docker-compose.evolution.yml`, ambos scripts lo incluyen en la configuración; `start.ps1` activa también Evolution y sus servicios privados; las respuestas permanecen sujetas a la configuración administrativa.

Al terminar, abre [http://localhost:8080](http://localhost:8080). Si cambias `APP_PORT` en el `.env` de la raíz, utiliza el puerto que el script muestra al finalizar. Mantén también `APP_URL` de `application/.env` coherente con esa dirección.

Para los siguientes inicios, si no has cambiado la interfaz y ya existen recursos compilados:

```powershell
.\scripts\start.ps1 -SkipAssets
```

Cuando se modifique el Dockerfile o sus dependencias del sistema, reconstruye la imagen:

```powershell
.\scripts\start.ps1 -Build
```

## Qué observar y comprobar

Abre [Ingresar](http://localhost:8080/login). El script de inicio crea tres cuentas de prueba únicamente en entorno local:

| Rol | Correo |
| --- | --- |
| Administrador | admin@agroconecta.test |
| Coordinador | coordinador@agroconecta.test |
| Comprador | comprador@agroconecta.test |

La contraseña inicial aleatoria está en `E:\VSCODE\Agroconecta\.local\demo-access.txt`, excluido de Git. El inicio no cambia contraseñas de cuentas existentes. Si ya cambiaste una, utiliza la nueva o solicita recuperación.

1. Ingresa como administrador y abre **Usuarios**. Revisa los tres perfiles. Modifica una cuenta distinta de la tuya indicando el motivo; se registra quién hizo el cambio.
2. Ingresa como comprador. Al principio su perfil está pendiente. No puede entrar a `/admin/users`.
3. Regresa como administrador, aprueba al comprador explicando la revisión y comprueba que su panel muestre **Tu perfil está aprobado**. Podrá cotizar y comprar según las reglas habilitadas; en el entorno preparado son operaciones sintéticas. El comprador demo ya está aprobado.
4. Ingresa como coordinador y verifica que no tenga acceso a administración de usuarios ni permiso para confirmar pagos.
5. Cierra la sesión y usa **Crear cuenta** para registrar otro comprador. Quedará pendiente, siempre con rol comprador.
6. En **Olvidé mi contraseña**, solicita un enlace para una cuenta de prueba. Abre [Correo local Mailpit](http://localhost:8025), sigue el enlace y cambia la clave. Los correos se capturan en el equipo y no salen a un servidor de correo externo.

No uses documentos ni información personal reales durante estas pruebas. Las cuentas y el recorrido comercial de demostración son sintéticos; no representan ventas ni transferencias reales. El arranque normal no crea órdenes de demostración nuevas.

La página inicial muestra el alcance de esta entrega. No requiere una cuenta. La ruta [http://localhost:8080/up](http://localhost:8080/up) sirve como comprobación HTTP de Laravel; el diagnóstico por consola también verifica los componentes de fondo.

Ejecuta los comandos siguientes desde `E:\VSCODE\Agroconecta`:

```powershell
docker compose -f docker-compose.yml -f docker-compose.evolution.yml ps
docker compose -f docker-compose.yml -f docker-compose.evolution.yml exec -T app php artisan agroconecta:health --wait=75
docker compose -f docker-compose.yml -f docker-compose.evolution.yml exec -T app php artisan migrate:status
```

`app`, `nginx`, `postgres` y `redis` deben aparecer iniciados y saludables; `queue` y `scheduler`, en ejecución. El diagnóstico debe terminar correctamente y mostrar el resultado de cada componente. Si falla, conserva la salida y consulta los logs; un contenedor iniciado por sí solo no demuestra que todos los componentes funcionen.

Para ejecutar la suite automatizada:

```powershell
docker compose -f docker-compose.yml -f docker-compose.evolution.yml exec -T app php artisan test
```

La base `agroconecta_testing` está reservada para las pruebas automatizadas. No la utilices para registros que quieras conservar y no cambies la configuración de pruebas para apuntar a `agroconecta`.

Para ver los mensajes recientes o seguirlos mientras navegas:

```powershell
docker compose -f docker-compose.yml -f docker-compose.evolution.yml logs --tail=100 app nginx queue scheduler
docker compose -f docker-compose.yml -f docker-compose.evolution.yml logs -f --tail=50 app queue scheduler
```

Pulsa `Ctrl+C` para salir del seguimiento de logs; los servicios continúan funcionando. No compartas el contenido de `.env` ni ejecutes `docker compose config` sin `--quiet` al preparar una captura, porque la salida puede incluir credenciales.

## Probar los catálogos (Fase 3)

Abre [Catálogos](http://localhost:8080/admin/catalog/products) como administrador. El coordinador puede consultar y calcular equivalencias; el comprador no tiene acceso a estas pantallas administrativas.

1. En **Productos → Añadir producto**, registra otro producto, como haba u oca, eligiendo una categoría y unidad base activas. Si falta la categoría, créala primero. Indica el motivo del alta.
2. En **Variedades** y **Calidades**, añade registros para ese producto. Las calidades son criterios declarados por el operador; no acreditan una certificación.
3. Edita el producto. Puedes cambiar el nombre o desactivarlo; el identificador, categoría y unidad base quedan permanentes. No se elimina el producto ni se reasignan sus variedades. Para una corrección estructural crea otro registro con un identificador diferente.
4. En **Localidades**, respeta la secuencia país → departamento → provincia → distrito → comunidad. Un padre desactivado bloquea nuevas altas dependientes. Solo se incluyen Perú y Puno como referencia inicial; los códigos geográficos quedan vacíos hasta verificarlos.
5. En **Conversiones → Comprobar una cantidad**, selecciona Quinua, cantidad `1000`, Gramo y la fecha de hoy. El resultado debe ser `1.000 kg` con fuente **Equivalencia SI**. No necesitas registrar esa equivalencia.
6. Selecciona Saco y cantidad `2` sin añadir una equivalencia: debe indicar que no existe una conversión aprobada y vigente. No se asumen 50 ni 100 kg por saco.
7. Para un escenario sintético, crea una conversión Saco → Kilogramo, producto Papa, factor `50`, contexto `saco-papa-prueba`, fuente `Pesaje sintético para prueba local`, vigencia que incluya hoy y motivo. Al guardar, el administrador queda como verificador. Prueba `2` sacos con ese contexto: debe dar `100.000 kg`.
8. Intenta crear otra conversión activa con el mismo producto, unidades, contexto y fechas superpuestas: debe rechazarla. Para corregir el factor, desactiva la versión anterior y crea otra; el factor anterior se conserva. Al terminar, desactiva las equivalencias de prueba para que no se confundan con acuerdos comerciales.

Se creó y desactivó **Oca (prueba de interfaz)** al verificar el navegador; queda disponible como ejemplo de conservación del historial. No se cargaron factores comerciales ficticios mediante el seeder.

Los factores se guardan como decimales con 9 posiciones. Las cantidades finales usan 3 decimales y redondeo de mitades hacia arriba. Se rechazan valores negativos, cero, notación científica, resultados fuera de rango y conversiones de conteo a masa. No hay conversiones inversas ni encadenadas implícitas.

Los cambios y aprobaciones se auditan en la misma transacción; por ahora la auditoría se conserva en la base de datos. La consulta de trazabilidad se construye en fases posteriores.

## Detener y volver a entrar

```powershell
.\scripts\stop.ps1
```

El script detiene todos los servicios del proyecto, incluidos los del perfil de Evolution si estaban activos, y conserva todos sus volúmenes de datos. Puedes repetirlo y volver a iniciar la aplicación con `start.ps1`, que también inicia Evolution y sus servicios privados. Los scripts no utilizan `down -v`, borrado de volúmenes, `--remove-orphans` ni `migrate:fresh`.

Las credenciales locales permanecen en `.env` y `application/.env`, fuera de Git. Conserva esos archivos junto con los volúmenes: cambiar contraseñas en los archivos no modifica automáticamente las credenciales de una base ya creada. El código y la documentación permanecen en el directorio del proyecto; los datos de PostgreSQL y Redis se guardan en volúmenes administrados por Docker Desktop.

## Incidencias habituales

| Síntoma | Acción |
| --- | --- |
| No conecta con Docker o indica que falta el motor | Abre Docker Desktop y comprueba que usa contenedores Linux. Repite `start.ps1`. |
| Falla la descarga de dependencias o la compilación Vite | Comprueba Docker e Internet y repite `start.ps1` sin `-SkipAssets`. Node/npm se ejecutan en el servicio Docker `assets`; no requieren instalación en Windows. |
| El puerto 8080 está ocupado | Detén la aplicación que lo utiliza o cambia `APP_PORT` en `.env` y `APP_URL` en `application/.env`. Ejecuta de nuevo el script. |
| Falta un archivo de assets o una modificación visual no aparece | Ejecuta `start.ps1` sin `-SkipAssets` y recarga el navegador. |
| Fallan permisos de `storage` o `bootstrap/cache` | Repite `start.ps1`; aplica los permisos del usuario de ejecución sobre esas carpetas. Si persiste, conserva el error para revisar el montaje de Docker. |
| Falla la cola o el scheduler en el diagnóstico | Revisa los logs de `queue` y `scheduler` y el estado de los servicios con los comandos de arriba. Repite el diagnóstico después de resolver la causa. |
| Falla una migración | Conserva el mensaje completo y revisa la causa. El script se detiene y mantiene los datos; no borres la base para ocultar el error. |

## Registro y verificación de productores (fase 4)

Como administrador o coordinador, abre **Productores** en el menú. La ficha es privada; visitantes y compradores no pueden consultar ni modificar estos registros.

1. Pulsa **Registrar productor**. Solo se exigen nombre completo, DNI de ocho dígitos, teléfono y tipo de productor. Un celular peruano de nueve dígitos se guarda con `+51`. La validación del DNI comprueba su formato, no realiza una consulta a RENIEC.
2. Guarda el registro y observa **Pendiente de verificación**. La ficha muestra el responsable del alta. En el listado, DNI y teléfono aparecen parcialmente ocultos.
3. Intenta registrar el mismo DNI o el mismo teléfono: se debe rechazar el duplicado, aunque el teléfono se escriba con su prefijo internacional. Para el piloto cada teléfono actual pertenece a una sola ficha, incluidos los productores archivados.
4. En **Registrar una decisión**, selecciona **Verificado**, el método realmente utilizado y las observaciones. Comprueba que el historial conserva tu usuario y la fecha. En pruebas, utiliza datos sintéticos y deja explícito en las observaciones que es una simulación.
5. Usa **Editar ficha** para añadir ubicación, comunidad, dirección y coordenadas opcionales. Cambiar la ubicación conserva su versión anterior. El historial privado de correcciones conserva valores anteriores y posteriores, motivo, fecha y responsable. Cambiar nombre o tipo de un productor verificado lo devuelve a pendiente de revisión.
6. En **Cambiar teléfono**, confirma el número actual y el nuevo e indica el motivo. Un número actual incorrecto o un nuevo número ya asignado se rechaza. El cambio conserva el historial privado y devuelve a pendiente a un productor verificado. Una suspensión se mantiene.
7. En **Registrar consentimiento**, anota únicamente una respuesta efectivamente recibida, con su finalidad y versión del aviso presentado. Aceptación y retiro generan constancias separadas; no se envía ningún mensaje.
8. Suspende y vuelve a verificar una ficha para comprobar las decisiones admitidas. **Archivar** es el cierre definitivo de esa ficha en este módulo: conserva la información e impide nuevas ediciones. No uses esta acción para una pausa temporal.
9. Entra con la cuenta de comprador e intenta abrir la dirección de una ficha: el acceso debe ser denegado.

El DNI queda permanente después del alta. La base soporta fotografía opcional mediante una referencia privada; la carga de fotografías no forma parte de esta pantalla. El módulo de ofertas se incorpora en la fase 5: verificar a un productor aún no crea una oferta ni una publicación comercial.

### Claves de los datos privados

Conserva y respalda `APP_KEY` y `PRODUCER_LOOKUP_KEY` de `application/.env` junto con las copias de seguridad de la base. La primera protege los datos cifrados; la segunda permite detectar identidades duplicadas sin almacenar DNI o teléfonos legibles en los índices. El inicio prepara esta última una sola vez, con la base de productores vacía. Si ya hay registros o una huella de clave y falta la clave original, se detiene: restaura la configuración respaldada. No reemplaces claves para evitar un error de arranque.

## WhatsApp y trabajo pendiente

Se admiten **API oficial de Meta a través de Evolution** (`WHATSAPP-BUSINESS`) y **vinculación QR con Evolution/Baileys** (`WHATSAPP-BAILEYS`). La comprobación del 20/09/2026 confirmó estado `open` en Evolution y «WhatsApp conectado» en la web; las respuestas se activaron para la prueba solicitada con el productor registrado y falta confirmar la entrega real. En una instalación nueva empiezan desactivadas. Meta oficial requiere además credenciales y callback HTTPS público; Twilio conserva su validación externa pendiente. Consulta [mensajería](messaging.md) y [Evolution](evolution.md).

El [roadmap](roadmap.md) describe las 20 fases. Fases 1–6 y 9–19 están implementadas y comprobadas localmente: 17 fases de desarrollo. Las fases 7–8 tienen adaptadores probados y requieren validación externa. La fase 20 tiene Docker de producción y ensayo HTTPS local; siguen pendientes servidor, dominio, CI/CD remoto y recuperación externa. La evidencia de la suite final está en README.

## Probar ofertas y conversaciones (fases 5–8)

Instalación actual preparada: desde E:\VSCODE\Agroconecta basta:

```powershell
docker compose -f docker-compose.yml -f docker-compose.evolution.yml --profile workers --profile mail --profile messaging up -d
```

Este comando inicia los servicios existentes. Después de cambios que añadan dependencias, migraciones o assets, usa el script de preparación descrito al principio de esta guía. Ninguno conecta por sí solo una cuenta de Meta/Twilio.

1. Entra como administrador o coordinador y registra primero una ficha en **Productores**, con nombre, DNI y teléfono sintéticos. El DNI se introduce en el formulario privado, nunca en el chat.
2. Abre [Simulador](http://localhost:8080/dev/message-simulator), usa ese teléfono registrado y envía HOLA. Acepta el aviso de prueba con ACEPTO. Con la configuración actual, un teléfono desconocido se ignora sin crear conversación.
3. Verifica la ficha de prueba desde Productores, indicando que es una simulación. El consentimiento permite la conversación; la verificación habilita presentar ofertas.
4. Regresa a la conversación y envía QUINUA 80KG PUNO. CABANA requiere que exista una localidad inequívoca en el catálogo; si no existe, se pide aclaración.
5. Selecciona variedad/calidad, fecha inicial y final. Se aceptan fechas ISO, DD/MM/AAAA, HOY y MAÑANA. Confirma copiando CONFIRMAR y la referencia del resumen. Repetir la confirmación no crea otra oferta.
6. Abre Ofertas: la intención queda pendiente. El coordinador verifica los datos; si faltan, selecciona Necesita información y corrige la ficha. Los sacos sin equivalencia aprobada no se pueden verificar.
7. Sobre una oferta verificada, propone un bruto y un costo por unidad base, condiciones y vigencia. En el escenario sintético se probó S/ 5.00 − S/ 0.50 = S/ 4.50 por kg. Registra una respuesta simulada claramente identificada como tal y publica la oferta de prueba.
8. Revisa el historial, las condiciones por versión y las fotografías privadas. El estado Publicada habilita la agrupación de su saldo libre; el lote comercial se publica por separado en el marketplace. Las compras sintéticas están disponibles; el comercio real sigue desactivado.
9. En Mensajería, simula recibos entregado/leído/fallido. Un fallo atrasado no rebaja un mensaje leído. AYUDA pausa el bot; el coordinador lo reanuda con nota interna. BAJA revoca el canal y ALTA vuelve a pedir consentimiento.
10. Abre otra conversación con el canal SMS para comprobar independencia del contexto y la estimación de segmentos. Sigue siendo una simulación sin llamadas a Twilio.

El entorno conserva **Productora Sintética del Simulador**, conversación #1 y oferta #1 de 80 kg de Quinua, publicada durante la prueba visual. Condiciones y constancia indican expresamente que son sintéticas. La disponibilidad declarada termina el 20/09/2026; el scheduler marca ofertas vencidas cuando corresponde. Usa registros nuevos para repetir el alta completa.

Validación al cerrar las fases 5–8: **224 pruebas / 1124 aserciones**, PHP/Pint y compilación Vite correctos. El formateador se ejecutó como root dentro del contenedor porque el volumen de código no es escribible por www-data; se usó Pint completo porque el montaje de application no contiene .git para --dirty. Recorrido visual comprobado hasta publicación, sin mensajes externos ni operaciones comerciales reales.

Comprobaciones útiles:

```powershell
docker compose exec -T app php artisan messaging:check
docker compose exec -T app php artisan messaging:dispatch
docker compose exec -T app php artisan test --compact
```

`messaging:dispatch` envía eventos a la cola, no garantiza entrega. La cola y el scheduler deben estar activos. No habilites los envíos reales solo para quitar un bloqueo de configuración: antes se necesitan las credenciales, el aviso del canal y las pruebas externas descritas en la guía de mensajería. Twilio no ofrece SMS bidireccional en Perú según su guía vigente; una bandera de configuración no cambia esa cobertura.

## Probar lotes y marketplace (fases 9–10)

La migración de lotes y los permisos de administrador/coordinador ya están aplicados. Reiniciar este entorno solo requiere la línea de Docker Compose anterior. En otra instalación que aún no tenga estos cambios, ejecuta el script de preparación para aplicar migraciones y compilar recursos.

1. Entra como administrador o coordinador y abre [Lotes](http://localhost:8080/admin/lots). Selecciona **Formar lote**. Solo aparecen ofertas publicadas con productor verificado, catálogo activo, condiciones aceptadas vigentes y saldo libre.
2. Selecciona manualmente ofertas compatibles o pulsa **Sugerir compatibles con igual precio** sobre una oferta. La sugerencia no consume cantidades. Comprueba los nombres de variedad/calidad, localidad, fechas, acuerdos y saldo de cada fuente.
3. Modifica las aportaciones en su unidad base. Por ejemplo, separar 60 kg de una oferta de 80 kg deja 20 kg sin agrupar. Si utilizas precios distintos, revisa el rango y marca la aceptación explícita de esa composición.
4. Indica un motivo que identifique la prueba como sintética y crea el borrador. Revisa la composición privada y su historial. Un borrador ya separa sus porciones, pero todavía no aparece en el catálogo público.
5. En **Revisar estado**, selecciona **Publicado** y registra el motivo de la prueba. Se vuelven a comprobar las fuentes y sus vigencias. Abre **Ver ficha pública**.
6. En [Marketplace](http://localhost:8080/marketplace), prueba filtros de producto, variedad, calidad, región, provincia, unidad, cantidad, precio, fecha y disponibilidad. Una provincia filtra también sus distritos y comunidades; no atribuye provincia a una oferta que solo tiene región registrada.
7. Abre un lote y escribe una cantidad parcial. Los controles +/− y la edición directa actualizan el rango orientativo. El botón **Actualizar estimación y disponibilidad** vuelve a consultar el servidor; funciona también sin JavaScript. El cálculo no reserva stock. El acceso a cotización permite continuar al recorrido comercial descrito más abajo.
8. Intenta superar la disponibilidad o introducir una fracción para un producto por unidades: la estimación se rechaza. Los precios del producto excluyen logística, cargos del comprador y tributos; la cotización final corresponde a fase 11.
9. Regresa al panel, pausa el lote y comprueba que desaparece del catálogo conservando sus porciones. Puedes publicarlo otra vez si las fuentes siguen siendo elegibles. Cancelar libera el saldo sin operaciones vinculadas y conserva el historial; un lote cancelado no se reactiva.
10. El comprador y el visitante solo acceden a la proyección pública del lote. No ven identidades privadas, acuerdos, observaciones ni fotografías originales. Las compras, reservas, recepciones y pesajes ya se pueden probar en sus módulos, siguiendo el recorrido de fases 11–16.

Escenario conservado: **L-CNNSF4LO**, [ficha pública](http://localhost:8080/marketplace/L-CNNSF4LO) y [panel privado](http://localhost:8080/admin/lots/1), con **60 kg sintéticos de Quinua**, aportados por la oferta #1; quedan **20 kg libres** en esa oferta. Ventana del lote: 13–20/09/2026. La estimación de 25.125 kg a S/ 5 por kg es S/ 125.63. Se comprobó el incremento a 26.125 kg y el rechazo de 61 kg. Las condiciones y motivos privados identifican el escenario como prueba; no se realizó una operación comercial real.

Pruebas específicas:

```powershell
docker compose exec -T app php artisan test --compact tests/Feature/LotManagementTest.php tests/Feature/MarketplaceTest.php tests/Feature/LotConcurrencyTest.php
```

Estas fases incorporan 37 pruebas y 256 aserciones sobre PostgreSQL aislado: porciones exactas, compatibilidad, idempotencia, permisos, publicación, cancelación, retiros, expiración, privacidad, filtros, paginación, estimaciones y concurrencia. La prueba concurrente utiliza dos procesos independientes bloqueados sobre el mismo inventario; de dos solicitudes de 60 kg sobre 80 kg, solo una se acepta. El test verifica el nombre de la base `agroconecta_testing` antes de recrear su esquema y no modifica la base local del sistema. Las lecturas de diagnóstico renuevan la instantánea de estadísticas para observar bloqueos actuales, siguiendo la [documentación de PostgreSQL](https://www.postgresql.org/docs/18/monitoring-stats.html).

## Probar el recorrido comercial (fases 11–16)

Para observar un ejemplo completo, entra como administrador y abre [O-7HHTJSCGBM](http://localhost:8080/commerce/orders/1). Incluye 25.125 kg sintéticos recibidos, consolidados, entregados, cobrados y liquidados; saldo comprador y productor: S/ 0.00. El enlace **Ver recorrido de origen a entrega** relaciona la aportación con cada pesaje, lote físico y despacho. **Revisar ficha pública y QR** abre la gestión de publicaciones. La ficha física muestra el origen y cantidades de esta mercancía; una ficha comercial todavía no acredita recepción.

Para crear otra operación manualmente:

1. **Comprador aprobado → Marketplace.** Abre un lote vigente, pulsa **Cotizar para comprar o reservar**, indica cantidad, regla de prueba, retiro y medio. Por ejemplo, 10.000 kg del lote demo mientras conserve saldo y vigencia. Las cotizaciones vencen en hasta 20 minutos y no apartan stock.
2. **Revisión y confirmación.** Comprueba producto, servicio, logística, descuento, tributos y total. Acepta expresamente. Puedes comprar directamente o reservar durante hasta 10 minutos y convertir esa reserva. Vencer/cancelar libera solo la reserva; el producto comprado permanece comprometido.
3. **Coordinador → Compras → Orden.** Selecciona un punto de acopio con la misma unidad, capacidad suficiente y horario diario compatible. Confirma dirección/acceso y programa una ventana futura dentro de las fechas del lote. GPS no es obligatorio. Cada productor recibe un aviso preparado con su aportación; la pantalla distingue pendiente, bloqueado, en cola y resultado de mensajería. En prueba se usa el proveedor falso. Tras corregir contacto/consentimiento se puede reintentar un aviso bloqueado antes de crear el mensaje.
4. **Recepción.** Registra cantidades esperada, recibida, aceptada y rechazada, decisión de calidad, hora real y observaciones. Para una entrega completa de 10 kg: 10 / 10 / 10 / 0. La fecha de llegada no puede ser futura. Las fotografías son privadas. Puedes registrar varias entregas parciales sin superar lo comprometido.
5. **Diferencias.** Un faltante o exceso genera incidencia. Para faltante definitivo, propone la reducción desde la orden, ingresa como comprador para aceptarla y vuelve como coordinador para documentar la resolución. No marques producto inexistente como libre y vendible. Resolver una incidencia sin conciliar sus cantidades se rechaza.
6. **Lote físico y despacho.** Selecciona recepciones y cantidades aceptadas para consolidar. Luego selecciona porciones físicas para preparar el retiro/despacho. El escenario de retiro tiene costo logístico cero. Registra la salida y, después, cantidades entregadas, devueltas o perdidas. Los movimientos parciales conservan lo que queda en custodia.
7. **Cobro.** El comprador o coordinación declara medio, importe, referencia y fecha; puede adjuntar JPG/PNG/WEBP/PDF de hasta 5 MB. Usa archivos y referencias sintéticos. El importe queda pendiente. Administración inicia revisión y después confirma o rechaza con constancia. La pantalla muestra el saldo efectivo y las devoluciones comprometidas.
8. **Liquidación.** Como administrador, calcula por productor después del pesaje aceptado. Revisa bruto, costos pactados, neto y origen por recepción/acuerdo. Aprueba, autoriza un importe y registra el resultado simulado de su desembolso. El sistema no transfiere dinero. Un resultado incierto conserva la obligación y bloquea volver a comprometer ese saldo hasta conciliarlo.
9. **Cierre.** Solo administración cierra cuando la orden está entregada o cancelada, no hay incidencias abiertas, el saldo comprador es cero y todos los productores están conciliados. Un comprobante pendiente o un reembolso incierto impide el cierre.
10. **Publicación.** Desde la trazabilidad elige lote comercial o físico, revisa los campos públicos y autoriza la publicación. Descarga el SVG y abre su ficha. Si revocas, tanto HTML/JSON como el QR dejan de responder para ese token; se conserva el historial privado. Para reflejar nuevos movimientos publica una nueva ficha y revoca la anterior si ya no debe compartirse.

El acopio de la demostración completa puede figurar con aviso al productor cancelado: al ejecutarse todas las etapas en segundos, el worker detecta que el acopio ya terminó y evita enviar una convocatoria obsoleta. En la prueba manual, deja trabajar al worker después de programar para observar el aviso simulado antes de completar la recepción y entrega.

Los importes y cantidades originales son inmutables. Una corrección se registra mediante una operación con historial; no se borran pesajes, cobros o liquidaciones para cuadrar saldos. Después de nuevas recepciones debes calcular/aprobar la revisión vigente de la liquidación; los pagos anteriores siguen descontándose.

### Rutas y controles

- Público: `GET /trace/{token}`, `GET /trace/{token}/qr.svg`, `GET /api/v1/traces/{token}`.
- Privado: `GET /commerce/orders/{order}/trace` y `GET /api/v1/orders/{order}/trace`; comprador solo su orden, coordinación/admin con origen interno.
- Cotizaciones/compras: rutas de sesión en `routes/commerce.php`. Cotizar, consultar quote, reservar y confirmar aceptan `Accept: application/json` y devuelven una proyección limitada. No se ha habilitado una API con tokens para terceros.
- Escrituras web: sesión activa, CSRF, autorización de rol/recurso y UUID de idempotencia. Recargar la orden actualiza la versión antes de nuevas decisiones.
- En la API del marketplace, `purchasing_enabled` indica el comercio real; `demo_checkout_enabled` identifica el checkout sintético y `checkout_url` conduce al formulario autenticado.
- El QR usa `APP_URL`, no el encabezado Host enviado por visitantes. El enlace local solo funciona en este equipo; no se abrió la aplicación a Internet ni a la red del teléfono.

### Ejecutar las comprobaciones

Las pruebas usan exclusivamente PostgreSQL `agroconecta_testing`, con HTTP externo interceptado. No ejecutes `migrate:fresh` en la base de uso local.

```powershell
docker compose exec -T app php artisan test --compact
```

Suite acotada al recorrido añadido:

```powershell
docker compose exec -T app php artisan test --compact tests/Feature/CommerceTest.php tests/Feature/CheckoutConcurrencyTest.php tests/Feature/FulfillmentTest.php tests/Feature/PaymentSettlementTest.php tests/Feature/PaymentConcurrencyTest.php tests/Feature/TraceabilityTest.php tests/Feature/CommerceFactoryTest.php tests/Feature/CommerceWalkthroughTest.php tests/Unit/QrCodeTest.php
```

La preparación compila las vistas y los recursos para reducir la demora de la primera visita en Docker sobre Windows. Si editas Blade, Laravel detecta los cambios; tras una actualización grande puedes volver a ejecutar `docker compose exec -T app php artisan view:cache`. Esto no cambia los datos.

## Reportes y seguridad (fases 17–18)

1. Como administrador abre [Reportes](http://localhost:8080/reports?from=2026-09-01&to=2026-09-19&data=synthetic&report=sales). La orden demo cerrada muestra 25.125 kg, GMV S/ 125.63, total/cobrado S/ 128.14 y neto/desembolsado productor S/ 113.07; saldos cero.
2. Cambia a operaciones reales: no se trasladan las compras sintéticas. Padrón e inventario permanecen actuales y se explican aparte. Fechas, producto y localidad exacta delimitan cada informe.
3. Selecciona un informe y **Preparar descarga privada**; luego **Actualizar estado**. Descarga Excel, CSV o PDF al estar listo. Excel incluye Datos/Criterios y el PDF presenta todos los campos por registro. Datos de texto no se ejecutan como fórmulas.
4. Como comprador solo se ven compras, pagos y logística propios. Un usuario diferente o con permiso revocado no puede descargar un informe anterior aunque conozca su enlace.
5. El mapa de coordinación exige coordenadas capturadas y consentimiento vigente. No exporta coordenadas ni geolocaliza al visitante. Sin datos autorizados se muestra un estado vacío.
6. Recepciones posteriores a una liquidación quedan pendientes de recalcular; las revisiones anteriores no se suman dos veces.

    docker compose exec -T app php artisan test --compact tests/Feature/ReportsTest.php tests/Feature/SecurityHardeningTest.php

    docker compose exec -T app php artisan agroconecta:security-check

--production debe devolver fallo en local. Los límites generales son 120 escrituras por usuario/minuto y 300 webhooks por huella de IP/minuto; siguen aplicándose los límites específicos más estrictos. El registro security-*.log conserva 14 archivos diarios, omitiendo cuerpos, consultas, tokens e IP originales. CSP permite recursos del mismo origen: aquí se usa npm run build; HMR no está configurado.

## Recorrido de 220 kg y restauración (fase 19)

    docker compose exec -T app php artisan test --compact tests/Feature/CompleteJourneyTest.php tests/Feature/RestoreVerificationTest.php

La prueba sigue HOLA → ACEPTO → nombre/tipo → registro asistido → verificación de tres productores. Cada uno confirma QUINUA 80KG PUNO; coordinación verifica variedad/calidad y aceptación del precio. Se agrupan 240 kg y se cotizan, reservan y compran 220. Reintentar mensajes, compra, avisos y desembolsos no duplica registros.

Los avisos se procesan antes del acopio y reciben confirmaciones falsas, sin llamar a Meta/Twilio. Se reciben 80/80/60 kg, se consolida un lote físico y se adjunta un comprobante sintético. Administración revisa y confirma S/ 1,121.00. Se entregan las tres aportaciones y se liquidan S/ 360.00, S/ 360.00 y S/ 270.00. El cierre concilia saldos y el QR acredita tres orígenes. Dashboard/exportación concilian los 220 kg. Todo ocurre únicamente en agroconecta_testing.

El ensayo de recuperación es independiente: una prueba transaccional no demuestra la restauración de pg_dump.

    powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\backup-local.ps1

- Exige app/PostgreSQL activos y configuración Laravel sin cache para aislar DB_DATABASE. Está preparado para el entorno local, no para producción.
- Crea una carpeta nueva .local/backups/fecha-guid, entra temporalmente en mantenimiento y detiene cola/scheduler activos, conservando el estado previo incluso si falla.
- Guarda PostgreSQL custom y almacenamiento privado TAR. Compara manifiestos antes/después; si el origen cambia, falla y conserva la copia incompleta para revisar.
- Reabre la aplicación y restablece sus procesos. Restaura los archivos guardados en disco en una base agroconecta_restore_<id> y carpeta nuevas, nunca sobre agroconecta.
- Verifica tablas, secuencias, restricciones validadas, descifrado, índices de identidad y archivos referenciados. Comprueba las huellas SHA-256 de las evidencias que las tienen y de todos los archivos de la copia.
- Escribe result.json solo si coinciden los manifiestos. Retira únicamente la base temporal creada por ese ensayo y sus archivos temporales; conserva el respaldo.
- Protege el respaldo: contiene datos personales, como nombres, que no están cifrados a nivel de archivo. Guarda APP_KEY/PRODUCER_LOOKUP_KEY originales por separado. No incluye Redis, Evolution, código o configuración secreta.

Resultado del 19/09/2026: **restauración aprobada**, 191 campos descifrados, tres archivos privados y dos referencias verificadas. No acredita RPO/RTO productivo ni entrega real de mensajes. agroconecta:verify-restore también comprueba el estado actual sin escribir ni reparar datos.

**Validación final hasta fase 19 — 19/09/2026:** **347 pruebas / 2,032 aserciones aprobadas**, 376.60 segundos, con PostgreSQL aislado. Incluye concurrencia real, canales interceptados, recorrido de 220 kg, seguridad, privacidad, reportes y detección de evidencias alteradas. Evidencia: .local/phases-17-19-full-tests.log. Pint finalizó correctamente; las vistas y los recursos de fase 17 están compilados. PDF y XLSX se abrieron con lectores independientes y el dashboard administrativo/comprador se comprobó en navegador. El respaldo se restauró realmente en una base aislada; el resultado está en la carpeta indicada arriba. El código añadió 27 pruebas a las 320 previas. La revisión local de dependencias no encontró vulnerabilidades conocidas (sin nuevas dependencias en estas fases).
