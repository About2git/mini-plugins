// HMAC signing identical to the Parent (includes/class-signature.php).
import crypto from 'node:crypto';

export const HEADER = 'x-msug-signature';
export const KEY_HEADER = 'x-msug-key-id';
export const MAX_SKEW = 60;
export const MAX_LIFETIME = 900;
export const MAX_BODY = 65536;

export function sign(body, secret) {
  return 'v1=' + crypto.createHmac('sha256', secret).update(body).digest('hex');
}

// Constant-time comparison against all accepted secrets.
export function verify(body, header, secrets) {
  if (typeof header !== 'string' || !header.startsWith('v1=')) return false;
  let ok = false;
  for (const secret of secrets) {
    if (typeof secret !== 'string' || secret.length < 32) continue;
    const expected = Buffer.from(sign(body, secret));
    const given = Buffer.from(header);
    if (expected.length === given.length && crypto.timingSafeEqual(expected, given)) ok = true;
  }
  return ok;
}

const ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/;

export function checkEnvelope(msg, now = Math.floor(Date.now() / 1000)) {
  if (typeof msg.event_id !== 'string' || !/^[A-Za-z0-9_-]{16,64}$/.test(msg.event_id)) return 'bad_event_id';
  if (!ISO.test(msg.issued_at || '') || !ISO.test(msg.expires_at || '')) return 'bad_time';
  const issued = Math.floor(Date.parse(msg.issued_at) / 1000);
  const expires = Math.floor(Date.parse(msg.expires_at) / 1000);
  if (issued > now + MAX_SKEW) return 'issued_in_future';
  if (expires <= now) return 'expired';
  if (expires - issued > MAX_LIFETIME || expires < issued) return 'lifetime_too_long';
  return true;
}

export function envelope(payload, lifetime = 300) {
  const now = Math.floor(Date.now() / 1000);
  return {
    ...payload,
    event_id: crypto.randomUUID(),
    issued_at: new Date(now * 1000).toISOString().replace('.000Z', 'Z'),
    expires_at: new Date((now + lifetime) * 1000).toISOString().replace('.000Z', 'Z'),
  };
}
