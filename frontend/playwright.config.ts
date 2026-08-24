import { defineConfig, devices } from "@playwright/test";

/**
 * True end-to-end, not component/unit — see the decision recorded in
 * conversation: the registration bug this suite specifically guards
 * against (a "use server" file exporting a non-async value) is a Next.js
 * server-compilation failure, invisible to a jsdom-based component
 * render. Every other critical path here is equally about real
 * integration with the real Laravel backend (.claude/CLAUDE.md golden
 * rule #1: Next.js never touches the DB, only calls the real API), so
 * these tests drive a real browser against the real running dev server
 * and the real running backend — no mocked fetch, no faked session.
 *
 * webServer below reuses whichever frontend/backend are already running
 * (true throughout this project's own development) and would also boot
 * both from scratch in a clean environment (CI, a fresh clone) —
 * reuseExistingServer makes both paths work from the same config.
 */
export default defineConfig({
  testDir: "./e2e",
  globalSetup: require.resolve("./e2e/global-setup.ts"),
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: "list",
  timeout: 30_000,

  use: {
    baseURL: "https://localhost:3000",
    ignoreHTTPSErrors: true,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },

  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
  ],

  webServer: [
    {
      command: "npm run dev",
      url: "https://localhost:3000",
      ignoreHTTPSErrors: true,
      reuseExistingServer: true,
      timeout: 120_000,
    },
    {
      command: "php artisan serve --port=8123",
      cwd: "../backend",
      url: "http://127.0.0.1:8123/api/v1/health",
      reuseExistingServer: true,
      timeout: 120_000,
    },
  ],
});
