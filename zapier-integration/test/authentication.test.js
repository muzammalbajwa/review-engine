const zapier = require('zapier-platform-core');

const App = require('../index');
const appTester = zapier.createAppTester(App);
zapier.tools.env.inject();

const hasRealKey = Boolean(process.env.TEST_API_KEY);
const maybeIt = hasRealKey ? it : it.skip;

describe('authentication', () => {
  it('fails clearly on an invalid key', async () => {
    const bundle = { authData: { apiKey: 'not-a-real-key' } };

    await expect(appTester(App.authentication.test, bundle)).rejects.toThrow();
  });

  // Requires TEST_API_KEY + API_BASE_URL in .env — see README. Skipped
  // (not faked) rather than run against a placeholder, since the point of
  // this test is proving the real /tenant round trip works.
  maybeIt('succeeds against a real key and returns the tenant name for the connection label', async () => {
    const bundle = { authData: { apiKey: process.env.TEST_API_KEY } };

    const result = await appTester(App.authentication.test, bundle);

    expect(result).toHaveProperty('id');
    expect(result).toHaveProperty('name');
  });
});
