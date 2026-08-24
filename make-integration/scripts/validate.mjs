// Offline validation of this app's Forman-schema field definitions —
// the Make-side equivalent of Step 4's `zapier-platform validate`. Loads
// the real JSON files from this repo (not a copy), so it can never drift
// from what actually ships. No Make account or network access needed.
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { toJSONSchema, validateForman } from '@makehq/forman-schema';

const root = dirname(dirname(fileURLToPath(import.meta.url)));

async function loadJson(relativePath) {
  const raw = await readFile(join(root, relativePath), 'utf8');
  return JSON.parse(raw);
}

let failed = false;

function report(label, ok, detail) {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}`);
  if (!ok) {
    failed = true;
    console.log('  ' + JSON.stringify(detail));
  }
}

const expectFields = await loadJson('modules/create-contact/create-contact.expect.json');
const interfaceFields = await loadJson('modules/create-contact/create-contact.interface.json');
const sample = await loadJson('modules/create-contact/create-contact.samples.json');
const connectionParams = await loadJson('connections/webhook-api-key.parameters.json');

// 1. Every field definition converts to a valid JSON Schema at all —
// throws on a malformed Forman field (bad type name, etc.).
report('expect fields convert to JSON Schema', true, toJSONSchema({ type: 'collection', spec: expectFields }));
report('interface fields convert to JSON Schema', true, toJSONSchema({ type: 'collection', spec: interfaceFields }));
report(
  'connection parameters convert to JSON Schema',
  true,
  toJSONSchema({ type: 'collection', spec: connectionParams })
);

// 2. A real request payload, captured live against the dev backend in
// Step 3, validates against expect fields.
const realRequest = {
  name: 'Priya Shah',
  phone: '+14155550142',
  email: 'priya@example.com',
  external_id: 'crm-lead-48213',
};
const requestResult = await validateForman(realRequest, expectFields);
report('a real Step 3 request payload validates against expect fields', requestResult.valid, requestResult.errors);

// 3. The shipped sample (also real Step 3 data) validates against
// interface fields — proves the two files describe the same shape.
const sampleResult = await validateForman(sample, interfaceFields);
report('the shipped sample validates against interface fields', sampleResult.valid, sampleResult.errors);

// 4. A request missing both phone and email — mirrors Step 2's own
// required_without validation — is correctly rejected by `required: true`
// on name (Make can't replicate the backend's required_without check
// client-side without a custom IML validator; this confirms what Make
// *can* enforce client-side still works).
const missingName = await validateForman({ phone: '+15555550100' }, expectFields);
report('a request missing the required name field is rejected', missingName.valid === false, missingName.errors);

if (failed) {
  console.error('\nValidation failed.');
  process.exit(1);
}

console.log('\nAll checks passed.');
