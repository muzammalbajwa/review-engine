# TESTING — must pass before taking a single payment

## The seven gates (see SECURITY.md)
1. Cross-tenant isolation: as Tenant A, every read/write against Tenant B
   returns nothing. Test BOTH with the global scope AND with it bypassed
   (to prove RLS catches it).
2. Compliance gate: 20 bad templates all blocked; 10 good all pass.
3. No double-send: same contact through pipeline twice → one message.
4. Follow-up suppression: click/review between msg2 and msg3 → msg3 cancelled.
5. Drip throttle: 500 contacts release at 2–3/20min, business hours + tz only,
   never at 2am local.
6. Worker-death alarm fires when worker killed.
7. Lemon Squeezy lifecycle: subscribe/upgrade/cancel/fail → access changes correctly.

## Also
- SQL-injection tests: feed quotes/`;`/`--`/`OR 1=1` into every input; confirm
  parameterized handling, no error leakage.
- composer audit + npm audit clean.
- Feature tests (Pest/PHPUnit) for every endpoint's auth + validation.
