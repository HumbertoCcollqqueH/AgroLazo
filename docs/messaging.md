# AgroConecta — Mensajería implementada

Actualizado el 19 de septiembre de 2026. **Fase 6 operativa localmente; fases 7–8 implementadas con pruebas de contrato. Entregas reales pendientes.** Se conserva Meta oficial mediante Evolution, se añade QR/Baileys dentro de la web y Twilio permanece preparado para pruebas. Configuración administrativa: `/admin/settings/whatsapp`; pasos en [README](../README.md#conectar-whatsapp-y-probar-desde-el-teléfono).

## Probar sin cuentas externas

Entra como administrador o coordinador en [el simulador](http://localhost:8080/dev/message-simulator). También está en Mensajería → Abrir simulador. Todos sus contactos usan el proveedor interno fake, incluso cuando se simula el canal SMS; nunca llama a Evolution ni a Twilio.

Recorrido predeterminado: registra primero el teléfono y ficha en Productores → HOLA → ACEPTO. El coordinador verifica la identidad antes de permitir ofertas. Después escribe QUINUA 80KG PUNO, responde variedad/calidad y fechas, y copia CONFIRMAR con la referencia del resumen. La oferta queda pendiente. AYUDA pausa, CANCELAR descarta el borrador, ESTADO muestra la última oferta propia, BAJA revoca el canal y ALTA presenta de nuevo el aviso. Los números desconocidos no reciben respuesta ni se persisten en AgroConecta. El antiguo flujo HOLA → nombre → tipo corresponde únicamente a `MESSAGING_REGISTERED_CONTACTS_ONLY=false`; algunos escenarios históricos lo activan expresamente dentro de tests aislados.

CABANA no forma parte del catálogo inicial. QUINUA 80KG CABANA reconoce producto y cantidad, pero pide seleccionar una localidad existente o solicitar asistencia. No inventa la localidad, el precio ni el peso de un saco.

## Código y persistencia

Rutas web: application/routes/messaging.php y /dev/message-simulator; requieren sesión activa y permiso manage-messaging. El simulador se limita a entornos local/testing y MESSAGING_SIMULATOR_ENABLED. Rutas de proveedores: application/routes/api.php, sin sesión ni CSRF y con autenticación mediante firma.

| Componente | Responsabilidad |
| --- | --- |
| MessageIngress | Normalizar contacto, depurar texto, deduplicar y guardar mensaje + evento outbox en PostgreSQL |
| ConversationEngine | Procesar cada conversación en orden, conservar estado y confirmar intenciones mediante OfferRegistry |
| OfferParser | Resolver catálogo, cantidad decimal, unidad y fechas sin IA ni equivalencias inventadas |
| OutboxDispatcher | Despachar eventos durables a Redis; recuperar los no terminados tras vencer su arrendamiento |
| MessageDelivery | Registrar intento, aplicar consentimiento/ventana/capacidades y distinguir aceptación, entrega y resultado incierto |
| EvolutionProvider / TwilioProvider | Adaptadores HTTP de envío; pruebas usan respuestas interceptadas |
| ProcessConversation / DeliverMessage / ForwardEvolutionEvent | Jobs que transportan identificadores; los datos privados permanecen cifrados en la base |
| MessagingController | Simulador, consulta privada, captura asistida, pausa/reanudación, respuesta del coordinador y recibos falsos |

Tablas: conversations, messages, message_attempts, message_receipts y outbox_events. producer_consents ahora admite productor o conversación y actor nulo cuando la respuesta viene del contacto. Los consentimientos son registros nuevos inmutables; el consentimiento del simulador se identifica por separado y no autoriza mensajes reales.

Teléfonos, contenido, contexto y cargas del outbox se cifran con los casts de Laravel. El índice de teléfono comparte la clave de identidad estable de Productores. No se guardan webhooks crudos; se conserva información mínima depurada. Los posibles números de documento escritos por error se ocultan; una fecha ISO válida permanece interpretable. El DNI se captura exclusivamente mediante el formulario privado del coordinador.

## Garantías y límites

- Entrada única por proveedor, cuenta e identificador externo. Un reintento no crea otra conversación, respuesta u oferta.
- Las modificaciones de dominio se serializan con la misma exclusión del registro administrativo y un bloqueo de conversación. Estado, oferta, respuesta y outbox se confirman en transacción.
- El borrador vence a los siete días. CONFIRMAR usa una referencia de intención; un 1 atrasado o una referencia de un borrador anterior no confirma el actual.
- Fechas completas ISO o DD/MM/AAAA; HOY y MAÑANA usan el instante original del mensaje en America/Lima. No se supone la disponibilidad.
- 1.000 kg / 1,000 kg requieren aclaración; los sacos quedan pendientes si no existe equivalencia aprobada. Las ofertas sin clasificación o normalización suficientes no pasan la revisión.
- BAJA cancela salidas pendientes y admite un único acuse de baja. La atención humana pausa el bot. Las notas internas se guardan sin enviar.
- El scheduler despacha el outbox cada minuto. Una caída de Redis conserva el evento para reintento. Los trabajos repetidos son idempotentes.
- Un timeout, un 5xx o una respuesta sin identificador de aceptación se consideran inciertos: no se reenvían automáticamente. Un 429 se reintenta con espera y hasta tres intentos.
- Un recibo temprano se conserva y concilia cuando aparece la referencia de envío. Un estado enviado o fallido atrasado no rebaja un mensaje leído/entregado. Los recibos no aceptan condiciones comerciales.
- En Meta, audios/medios derivan a atención asistida; en QR se ignoran y solo se admite texto. No se descargan ni transcriben. Las fotos se cargan en el panel privado.
- El catálogo conversacional muestra hasta 15 opciones; si no aparece una opción se puede escribir su nombre exacto para producto/localidad o solicitar ayuda.
- La conciliación de un envío incierto requiere revisar el proveedor. No existe una promesa de entrega exactamente una vez en una red externa.

## WhatsApp oficial por Evolution

Endpoints públicos previstos:

- GET /api/v1/webhooks/whatsapp/meta: desafío con META_VERIFY_TOKEN.
- POST /api/v1/webhooks/whatsapp/meta: valida HMAC SHA-256 del cuerpo original con META_APP_SECRET, la cuenta y PHONE_NUMBER_ID. Recorre cada entrada y mensaje; el lote se revierte si un evento de otra cuenta aparece en él.
- POST /api/v1/webhooks/whatsapp/evolution: callback interno con X-Evolution-Secret e instancia esperada. Actualiza señal técnica de callback; no duplica mensajes ni decisiones comerciales.

Meta es la entrada canónica. El outbox reenvía a /webhook/meta de Evolution una copia mínima por evento, dentro de Docker. Evolution 2.3.7 solo consume el primer mensaje de ciertas estructuras; por eso se divide el lote. La confirmación HTTP del reenvío solo acredita su recepción por Evolution, no el procesamiento interno ni la entrega al teléfono.

El envío usa /message/sendText/{instance} con number, text y linkPreview. Se exige key.id con prefijo wamid. Para plantillas, /message/sendTemplate/{instance} recibe name, language como cadena y components. Estos contratos se comprobaron contra el código fijado 2.3.7; algunos ejemplos de la documentación web muestran otro formato.

WHATSAPP_APPROVED_TEMPLATES es un objeto JSON de entradas autorizadas. Cada entrada necesita name, language, components y una descripción preview para que el coordinador revise el contenido. Ejemplo exclusivamente ilustrativo, **no es una plantilla aprobada real**:

    {"aviso":{"name":"nombre_aprobado_por_meta","language":"es","components":[],"preview":"Texto exacto y finalidad de la plantilla aprobada"}}

El panel puede poner una respuesta en cola o seleccionar una plantilla configurada. Fuera de 24 horas desde la última entrada, bloquea texto libre. Al elegir plantilla, no incorpora el texto libre escrito en el formulario. No se crea ni se aprueba una plantilla en Meta automáticamente.

Configuración en application/.env: MESSAGING_LIVE_ENABLED=false por defecto; WHATSAPP_ENABLED=false; META_APP_SECRET, META_VERIFY_TOKEN, META_ACCESS_TOKEN, META_PHONE_NUMBER_ID, META_BUSINESS_ACCOUNT_ID; WHATSAPP_PUBLIC_WEBHOOK_URL (HTTPS hasta la ruta Meta); EVOLUTION_URL, EVOLUTION_API_KEY, EVOLUTION_INSTANCE y EVOLUTION_WEBHOOK_SECRET. Antes de uso real, establecer un aviso veraz y su versión con MESSAGING_NOTICE_TEXT, MESSAGING_NOTICE_VERSION y MESSAGING_NOTICE_APPROVED.

Comprobación sin envíos:

    docker compose exec -T app php artisan messaging:check
    docker compose exec -T app php artisan messaging:prepare-evolution

Con credenciales propias ya configuradas, el operador puede ejecutar messaging:prepare-evolution --apply para crear la instancia WHATSAPP-BUSINESS y su callback interno. No genera QR ni envía mensajes. La suscripción del webhook público en Meta debe completarse con los activos reales. Consulta también [Evolution](evolution.md).

## Twilio preparado para pruebas

Se instaló twilio/sdk 8.12.1. Se utiliza Twilio\Security\RequestValidator para verificar X-Twilio-Signature con la URL HTTPS exacta configurada y todos los parámetros del cuerpo original, antes de la normalización. Los callbacks usan application/x-www-form-urlencoded y URLs sin query string.

- POST /api/v1/webhooks/sms/inbound: exige cuenta, destino y MessageSid válidos. Persiste entrada + outbox y devuelve TwiML vacío; la respuesta comercial viaja por la cola.
- POST /api/v1/webhooks/sms/status: conserva recibos sent/delivered/failed/undelivered y concilia la salida.
- Envío REST a /2010-04-01/Accounts/{AccountSid}/Messages.json con Basic Auth, From, To, Body y StatusCallback.
- SMS_ENABLED=false y SMS_TWO_WAY_VERIFIED=false inicialmente. Variables: TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER, TWILIO_INBOUND_URL y TWILIO_STATUS_URL.
- SmsEncoding calcula GSM-7, caracteres de extensión y unidades UTF-16 para Unicode: 160/153 y 70/67 por segmento. SMS_MAX_SEGMENTS limita salidas reales; el simulador muestra la estimación sin inventar tarifas.

**La guía oficial de Twilio para Perú indica que no ofrece SMS bidireccional.** El adaptador y sus callbacks se prueban localmente; una conversación SMS real necesita un país/número/proveedor que permita recepción y envío. Cambiar una bandera no modifica la cobertura del proveedor. No se enviaron SMS reales.

## Validación pendiente fuera del entorno local

Cuenta y número Meta, permisos/token reales, suscripción HTTPS, entrada/salida y recibos en un teléfono de prueba, plantillas aprobadas y ventana de atención. Para SMS: cuenta, saldo de prueba, número y cobertura compatibles, recepción/entrega y costo efectivo. Estas comprobaciones impiden declarar 7–8 integradas en producción; no impiden probar 5–6 localmente.

Pruebas: OfferManagementTest, MessagingFlowTest y MessagingProviderTest con PostgreSQL aislado y HTTP interceptado. Cubren firmas inválidas, cuentas ajenas, lotes, duplicados, consentimiento, captura asistida, referencias de confirmación, fechas, fallos de red/cola, ventana de 24 horas y segmentos SMS.

## Fuentes primarias

- [Evolution 2.3.7: implementación Cloud](https://github.com/EvolutionAPI/evolution-api/blob/2.3.7/src/api/integrations/channel/meta/whatsapp.business.service.ts)
- [Evolution 2.3.7: DTO de mensajes y plantillas](https://github.com/EvolutionAPI/evolution-api/blob/2.3.7/src/api/dto/sendMessage.dto.ts)
- [Seguridad de webhooks de Twilio](https://www.twilio.com/docs/usage/webhooks/webhooks-security)
- [Guía SMS de Twilio para Perú](https://www.twilio.com/en-us/guidelines/pe/sms)
- [Política de WhatsApp Business](https://whatsappbusiness.com/policy/)

