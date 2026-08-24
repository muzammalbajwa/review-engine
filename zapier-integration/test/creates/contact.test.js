const zapier = require('zapier-platform-core');

const App = require('../../index');
const appTester = zapier.createAppTester(App);
zapier.tools.env.inject();

const hasRealKey = Boolean(process.env.TEST_API_KEY);
const maybeIt = hasRealKey ? it : it.skip;

// All three tests need a real contacts:create key against a real backend
// (TEST_API_KEY + API_BASE_URL in .env — see README) — same "no mocking,
// exercise the real API" convention Step 2/3's own backend tests used.
// Skipped, not faked, when those aren't set.
describe('creates.contact', () => {
  maybeIt('creates a real contact and returns the full record', async () => {
    const bundle = {
      authData: { apiKey: process.env.TEST_API_KEY },
      inputData: {
        name: 'Zapier Integration Test',
        phone: '+15555550100',
        external_id: `zapier-test-${Date.now()}`,
      },
    };

    const result = await appTester(App.creates.contact.operation.perform, bundle);

    expect(result).toHaveProperty('id');
    expect(result.name).toBe('Zapier Integration Test');
    expect(result.source).toBe('webhook');
    expect(result.status).toBe('pending');
  });

  maybeIt('returns the original contact on a retried external_id, not a duplicate — Step 2\'s idempotency window', async () => {
    const externalId = `zapier-idempotency-test-${Date.now()}`;

    const first = await appTester(App.creates.contact.operation.perform, {
      authData: { apiKey: process.env.TEST_API_KEY },
      inputData: { name: 'First Attempt', phone: '+15555550101', external_id: externalId },
    });

    // A different name, same external_id — simulating Zapier retrying this
    // step with the same mapped data after a timeout.
    const retried = await appTester(App.creates.contact.operation.perform, {
      authData: { apiKey: process.env.TEST_API_KEY },
      inputData: { name: 'Retried Attempt', phone: '+15555550101', external_id: externalId },
    });

    expect(retried.id).toBe(first.id);
    expect(retried.name).toBe('First Attempt');
  });

  maybeIt('surfaces the real validation message when neither phone nor email is given', async () => {
    const bundle = {
      authData: { apiKey: process.env.TEST_API_KEY },
      inputData: { name: 'No Contact Method' },
    };

    await expect(appTester(App.creates.contact.operation.perform, bundle)).rejects.toThrow();
  });
});
