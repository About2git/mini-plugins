import test from 'node:test';
import assert from 'node:assert/strict';
import { checkEnvelope, envelope, sign, verify } from '../src/signature.js';

test('same HMAC vector as the PHP Parent (tests/unit.php)', () => {
  assert.equal(sign('msug-vector', 'msug-test-vector-secret-0123456789'), 'v1=37de43ef025b8ac0e43203c93db021a8d09b1b9e021b04ebb9490553a144f456');
});

test('verify, rotation and envelope', () => {
  const s = 'a'.repeat(32);
  const h = sign('body', s);
  assert.ok(verify('body', h, [s]));
  assert.ok(!verify('body ', h, [s]));
  assert.ok(verify('body', h, ['b'.repeat(32), s]));
  assert.equal(checkEnvelope(envelope({ x: 1 })), true);
  assert.equal(checkEnvelope({ ...envelope({}), expires_at: '2020-01-01T00:00:00Z' }), 'expired');
});
