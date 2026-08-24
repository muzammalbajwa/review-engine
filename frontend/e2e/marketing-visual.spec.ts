import { devices, expect, test } from "@playwright/test";
import { mkdirSync } from "node:fs";
import { join } from "node:path";

/**
 * Real Playwright screenshots of the marketing page at genuine device
 * widths — not responsive classes verified by reading Tailwind
 * breakpoints in the source. The marketing page (/) needs no backend
 * session (unlike every other spec in this suite), so these don't touch
 * global-setup.ts's shared tenant at all.
 *
 * "iPhone SE (3rd gen)" (375×667) and "iPad Mini" (768×1024) are
 * Playwright's own bundled device profiles — real viewport + real
 * user-agent + real device pixel ratio, not a bare `{ width: 375 }`
 * override that would miss DPR-dependent rendering. Each profile's own
 * `defaultBrowserType` (webkit, for both of these) is dropped: the
 * suite's one configured project is Chromium
 * (playwright.config.ts), and `test.use()` inside a describe block can't
 * switch browser engines — only the config's `projects` can. Everything
 * else about the device (viewport, deviceScaleFactor, isMobile,
 * hasTouch, userAgent) is real and unchanged.
 */
const SCREENSHOT_DIR = join(__dirname, "screenshots");
mkdirSync(SCREENSHOT_DIR, { recursive: true });

function chromiumDevice(device: (typeof devices)[string]) {
  const { defaultBrowserType: _defaultBrowserType, ...rest } = device;
  return rest;
}

/**
 * `page.screenshot({ fullPage: true })` captures the whole document
 * regardless of current scroll position, but the reveal-on-scroll
 * IntersectionObservers only fire once a section has actually
 * intersected the viewport — without this, a full-page capture right
 * after load just shows most of the page mid-fade (caught this for real:
 * the first screenshot taken for this task showed everything below the
 * hero blank/half-transitioned). Scrolling through in steps triggers
 * every observer in order; the final wait covers the slowest transition
 * (RevealOnScroll's 700ms) before the shot is taken.
 */
async function settleAllReveals(page: import("@playwright/test").Page) {
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  for (let y = 0; y <= height; y += 400) {
    await page.evaluate((yy) => window.scrollTo(0, yy), y);
    await page.waitForTimeout(80);
  }
  await page.waitForTimeout(900);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(100);
}

async function assertNoHorizontalOverflow(page: import("@playwright/test").Page, label: string) {
  const overflowPx = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth
  );
  expect(overflowPx, `${label}: page must not scroll horizontally`).toBeLessThanOrEqual(1);
}

/**
 * WCAG 2.5.8 (AA, "target size minimum") is 24×24 CSS px; this project's
 * own persona (DESIGN.md: "checking this on a phone between jobs") is
 * exactly the real-thumb-on-a-real-screen case that guideline exists
 * for, so this checks the stricter 44×44 (Apple HIG / Material) target
 * most mobile guidance actually recommends over the AA floor.
 */
async function assertComfortableTapTarget(locator: import("@playwright/test").Locator, label: string) {
  const box = await locator.boundingBox();
  expect(box, `${label}: must be visible with a real bounding box`).not.toBeNull();
  if (!box) return;
  expect(box.height, `${label}: tap target height`).toBeGreaterThanOrEqual(44);
}

test.describe("marketing page — small phone (iPhone SE 3rd gen, 375×667)", () => {
  test.use({ ...chromiumDevice(devices["iPhone SE (3rd gen)"]) });

  test("renders with no horizontal overflow and a full-page screenshot", async ({ page }) => {
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    await assertNoHorizontalOverflow(page, "375px");
    await settleAllReveals(page);
    await page.screenshot({ path: join(SCREENSHOT_DIR, "marketing-375-iphone-se.png"), fullPage: true });
  });

  test("hero product-screenshot card fits inside the viewport, not just its parent", async ({ page }) => {
    await page.goto("/");
    const card = page.locator('p:text("Example account")').locator("..").locator("..");
    const box = await card.boundingBox();
    const viewport = page.viewportSize();
    expect(box, "dashboard preview card must be visible").not.toBeNull();
    if (box && viewport) {
      expect(box.width, "card width must not exceed the 375px viewport").toBeLessThanOrEqual(viewport.width);
      expect(box.x, "card must not start off-screen to the left").toBeGreaterThanOrEqual(0);
    }
  });

  test("sticky header's Start free button is a comfortable tap target", async ({ page }) => {
    await page.goto("/");
    await assertComfortableTapTarget(page.locator("header").getByText("Start free"), "header Start free");
  });

  test("FAQ rows are comfortable tap targets and actually expand on a real tap", async ({ page }) => {
    await page.goto("/");
    const firstSummary = page.locator("details summary").first();
    await firstSummary.scrollIntoViewIfNeeded();
    await assertComfortableTapTarget(firstSummary, "FAQ question row");

    const firstDetails = page.locator("details").first();
    await expect(firstDetails).not.toHaveAttribute("open", "");
    await firstSummary.tap();
    await expect(firstDetails).toHaveAttribute("open", "");
  });

  test("the single pricing card stays inside the viewport", async ({ page }) => {
    await page.goto("/#pricing");
    await page.waitForLoadState("networkidle");
    const cards = page.locator('[data-slot="card"]').filter({ hasText: "ReviewEngine" });
    await expect(cards).toHaveCount(1);
    const viewport = page.viewportSize();
    const box = await cards.first().boundingBox();
    expect(box).not.toBeNull();
    if (box && viewport) {
      expect(box.width, "pricing card: width must fit the viewport").toBeLessThanOrEqual(viewport.width);
    }
  });

  test("the monthly/annual toggle switches the displayed price", async ({ page }) => {
    await page.goto("/#pricing");
    await page.waitForLoadState("networkidle");
    const card = page.locator('[data-slot="card"]').filter({ hasText: "ReviewEngine" });
    await expect(card).toContainText("$20");
    await expect(card).toContainText("$25");

    await card.getByRole("radio", { name: "Annual" }).tap();

    await expect(card).toContainText("$200");
    await expect(card).toContainText("2 months free");
  });
});

test.describe("marketing page — tablet (iPad Mini, 768×1024)", () => {
  test.use({ ...chromiumDevice(devices["iPad Mini"]) });

  test("renders with no horizontal overflow and a full-page screenshot", async ({ page }) => {
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    await assertNoHorizontalOverflow(page, "768px");
    await settleAllReveals(page);
    await page.screenshot({ path: join(SCREENSHOT_DIR, "marketing-768-ipad-mini.png"), fullPage: true });
  });

  test("the single pricing card stays centered and doesn't stretch full-width at the sm breakpoint", async ({
    page,
  }) => {
    await page.goto("/#pricing");
    await page.waitForLoadState("networkidle");
    const card = page.locator('[data-slot="card"]').filter({ hasText: "ReviewEngine" });
    const box = await card.boundingBox();
    const viewport = page.viewportSize();
    expect(box).not.toBeNull();
    if (box && viewport) {
      // max-w-sm on the card — a tablet-width viewport must not stretch it
      // full-width, and it should sit roughly centered in the section.
      expect(box.width).toBeLessThan(viewport.width * 0.7);
      const leftGap = box.x;
      const rightGap = viewport.width - (box.x + box.width);
      expect(Math.abs(leftGap - rightGap)).toBeLessThan(5);
    }
  });
});

/**
 * A slow mobile connection's real risk isn't the CSS transition duration
 * — it's the gap before JS ever runs. RevealOnScroll's whole design
 * principle (globals.css's comment on --animate-draw-path, carried
 * through every motion-safe rule added since) is that the *unanimated*
 * base state must already be the fully-correct, fully-visible page, so
 * that gap is invisible to the visitor. `javaScriptEnabled: false` is
 * the most direct real test of that claim: no hydration ever happens at
 * all, which is strictly worse than "JS arrives late" and a superset of
 * what a slow connection actually risks.
 */
test.describe("marketing page — content visible with no JS at all (worst case of a slow connection)", () => {
  test.use({ ...chromiumDevice(devices["iPhone SE (3rd gen)"]), javaScriptEnabled: false });

  test("hero, trades strip, and FAQ text are all visible without any JavaScript running", async ({ page }) => {
    await page.goto("/");

    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    await expect(page.getByText("Built for the trades")).toBeVisible();

    const heroReveal = page.locator('[data-revealed]').first();
    await expect(heroReveal).toHaveCSS("opacity", "1");

    await page.locator("details").first().scrollIntoViewIfNeeded();
    await expect(page.getByText("Is this against Google's rules?")).toBeVisible();

    await page.screenshot({ path: join(SCREENSHOT_DIR, "marketing-375-no-js.png"), fullPage: true });
  });
});
