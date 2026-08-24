const authentication = require('./authentication');
const contactCreate = require('./creates/contact');

/**
 * Attaches the tenant's API key to every outgoing request. This is the one
 * place that happens — .claude/API.md's webhook auth (`Authorization: Bearer
 * <api_key>`) applies uniformly, so there's no per-action header logic to
 * keep in sync as more actions get added later.
 */
const includeBearerToken = (request, z, bundle) => {
  if (bundle.authData && bundle.authData.apiKey) {
    request.headers = Object.assign({}, request.headers, {
      Authorization: `Bearer ${bundle.authData.apiKey}`,
    });
  }
  return request;
};

module.exports = {
  version: require('./package.json').version,
  platformVersion: require('zapier-platform-core').version,

  authentication,

  beforeRequest: [includeBearerToken],

  afterResponse: [],

  resources: {},

  triggers: {},

  searches: {},

  creates: {
    [contactCreate.key]: contactCreate,
  },
};
