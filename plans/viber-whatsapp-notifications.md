# Plan: Viber and WhatsApp notifications for ZPMS

Status: PLAN ONLY, nothing implemented. Written 2026-10-15.
Caveat: written from general knowledge of both platforms; confirm Viber's
current business-messaging rules and Meta's current template pricing before building.

## 1. Feasibility

| | WhatsApp (Meta Cloud API) | Viber |
|---|---|---|
| Messages to patients | Yes, but the first message must use a Meta-approved template. Free text only within 24 h of the patient's last reply. | Only via paid Viber Business Messages through a Viber partner. A free Bot API account cannot message people who haven't subscribed. |
| Messages to doctors/secretaries | Same template rules. | Good fit for the Bot API: each staff member subscribes once via a deep link. |
| Account | Meta Business account, dedicated phone number, business verification | Partner contract, approved sender name and templates |
| Delivery status | Webhook: sent, delivered, read, failed | Webhook/callback |
| Cost | Per message by template category (reminders = "utility"). Check current rates. | Per message, set by the partner |

Recommendation: WhatsApp Cloud API directly for patients; Viber bot for staff only
(free, no templates); email stays as fallback. Decide Viber-for-patients later.
An aggregator (Infobip, Sinch, Bird) gives WhatsApp + Viber + SMS behind one API:
less to build, higher per-message cost. Start direct with WhatsApp.

## 2. Compliance (before any code)
- Consent: store opt-in per patient and channel with date and source (phone call,
  form, reply). Honour STOP / "ΔΙΑΚΟΠΗ" replies immediately.
- Minimal content: date, time and place only. No diagnosis, operation details or
  free-text notes.
- GDPR: Meta and the Viber partner become processors: DPA, processing register
  entry, check of data transfers outside the EU.
- Logs: never store message bodies, names or notes. Log event code, channel,
  template and recipient id only (same rule as the audit log).

## 3. Implementation steps

Step 0, manual quick win (about an hour, no API): add "Send via WhatsApp" /
"Send via Viber" buttons beside the existing copy-message buttons, opening
https://wa.me/<number>?text=... or viber://forward?text=... with the Greek text
filled in; the secretary presses send. No approval or consent infrastructure needed.

Step 1, data model (YAML entities, then spill:class:all and spill:sql:all):
- users: notify_channel (email|whatsapp|viber), whatsapp_number, viber_id.
- patients: notify_whatsapp_optin, notify_whatsapp_optin_at, notify_optin_source,
  mobile number in E.164.
- New table notifications: recipient_type, recipient_id, event, channel, template,
  vars_json (date/time/place only), status (queued|sent|delivered|read|failed),
  attempts, provider_msg_id, error, created_at, sent_at.

Step 2, channel layer (web/notify.php):
- zpms_notify($db, $recipientType, $id, $event, $vars): checks channel and consent,
  inserts a queued row; with no consent/channel falls back to the existing email function.
- Transport interface send(array $msg): array{ok,id,error} with WhatsAppCloudTransport
  and ViberBotTransport. A fake transport can be injected (like $mail) so tests never
  touch the network.

Step 3, sending worker (bin/send_notifications.php, cron every minute):
- Sends queued rows, retries 3 times with backoff, then marks failed and sends the
  email fallback. Web requests never wait on Meta/Viber.
- bin/send_reminders.php queues reminders for appointments 24 h ahead, skipping
  cancelled ones.

Step 4, WhatsApp transport:
- Config in config/messaging.php (gitignored, copied from a committed .php.in):
  wa_phone_number_id, wa_access_token (permanent system-user token), wa_app_secret,
  wa_verify_token.
- Send: POST https://graph.facebook.com/<version>/<phone_number_id>/messages, Bearer
  token, body:
  {"messaging_product":"whatsapp","to":"3069...","type":"template",
   "template":{"name":"appt_reminder_el","language":{"code":"el"},
   "components":[{"type":"body","parameters":[{"type":"text","text":"..."}]}]}}
  (number in E.164 without the +).
- Create and submit Greek templates in Meta Business Manager, category Utility:
  appt_confirmed_el, appt_rescheduled_el, appt_reminder_el, appt_cancelled_el;
  variables {{1}} date, {{2}} time, {{3}} place.
- Webhook route /webhooks/whatsapp (no login, like DocArc's qr.php): GET answers
  the hub.challenge handshake; POST verifies X-Hub-Signature-256 (HMAC with app
  secret) before anything else; delivery events update notifications.status; a STOP
  reply clears the patient's opt-in.

Step 5, Viber transport (staff):
- Create a bot in the Viber admin panel, get its token.
- Once: POST https://chatapi.viber.com/pa/set_webhook with header X-Viber-Auth-Token
  (URL must be HTTPS with a valid certificate).
- Profile page "Connect Viber" button shows a deep link/QR
  (viber://pa?chatURI=<bot>&context=<one-time-token>); on conversation_started /
  subscribed with that context, save the user's Viber id.
- Send: POST https://chatapi.viber.com/pa/send_message with receiver id, type text.
- For patients later: add a second transport (partner API) behind the same interface.

Step 6, hook into existing events (call zpms_notify where the code already emails
or syncs):
- New pending appointment: notify doctor and secretary.
- Reschedule from the edit page or Google Calendar: notify doctor, plus patient if opted in.
- Cancellation or conversion: notify patient.
- Per-user settings page with checkboxes for which events they want.

Step 7, tests and audit:
- Functional tests with the fake transport: missing consent falls back to email,
  retry/failure behaviour, webhook signature rejected, STOP clears opt-in, no notes
  or names in the log.
- Audit events notification_sent / notification_failed carrying channel, template and
  recipient id only.
- Permission notifications-manage (doctor) for the settings page and delivery-status list.

## 4. Order of work
1. Step 0 immediately.
2. Meta Business verification and template approval (takes days), in parallel.
3. Steps 1-3, then staff-only Viber and WhatsApp (Steps 4, 5) with doctors and
   secretaries as first recipients.
4. Patient messaging (reminders, Step 6) once consent capture is in place.

## 5. Open decisions
1. Patient messaging needed, or staff only to begin with?
2. Is there a dedicated phone number for the WhatsApp Business account?
3. One aggregator (simpler, higher cost) or direct integrations (cheaper, more work)?
4. Should patients be able to reply "1 = confirm, 2 = cancel" and have it update the appointment?
