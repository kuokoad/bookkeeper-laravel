// Made tests/Fixtures/legacy-scrypt.json with the OLD app's own hash functions, read-only:
//   node <old>/node_modules/tsx/dist/cli.mjs --tsconfig <old>/tsconfig.json tests/Fixtures/make-legacy-scrypt.mts > tests/Fixtures/legacy-scrypt.json
import { hashPassword, hashPin, verifyPassword } from 'file:///C:/ClaudeProjects/bookkeeper/src/lib/auth/password.ts';
const cases: Array<[string, string, string]> = [
  ['password', 'kwame-shop-2026', ''],
  ['password', 'Adwoa-\u0186s\u025Be-2026', ''],
  // NFKC: the ligature fi and a fullwidth digit fold to plain "fi" and "2".
  ['password', 'of\uFB01ce-\uFF12026-caf\u0065\u0301', 'office-2026-caf\u00E9'],
  ['pin', '8351', ''],
];
const out = [];
for (const [kind, secret, alias] of cases) {
  const hash = kind === 'pin' ? await hashPin(secret) : await hashPassword(secret);
  if (!(await verifyPassword(secret, hash))) throw new Error('self-check failed');
  out.push({ kind, secret, hash, aliasVerifies: alias ? await verifyPassword(alias, hash) : null, alias: alias || null });
}
console.log(JSON.stringify(out, null, 2));
