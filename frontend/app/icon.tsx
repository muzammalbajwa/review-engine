import { ImageResponse } from "next/og";

/**
 * Replaces app/favicon.ico, which was the unmodified create-next-app
 * scaffold icon (same timestamp as the other default template assets in
 * public/ — next.svg, vercel.svg, etc.) — every real visitor's browser
 * tab was showing the generic Next.js mark, not anything ReviewEngine.
 * Generated via Satori (next/og), same reasoning as opengraph-image.tsx:
 * no image-editing tool available, and this keeps it defined in code.
 *
 * A checkmark in DESIGN.md's real moss-green primary (#2F6F4E, the same
 * token this whole app already reads "compliant/done" through) —
 * favicons render at 16–32px, too small for anything but a single
 * simple mark to survive; a checkmark also isn't an arbitrary shape
 * choice; it's literally what "a review landed" means everywhere else
 * in this product (DashboardPreview's review-received icon, the
 * SinglePathLine's final step).
 */
export const size = { width: 32, height: 32 };
export const contentType = "image/png";

export default function Icon() {
  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          backgroundColor: "#2F6F4E",
          borderRadius: 7,
        }}
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <path d="M4 12.5L9.5 18L20 6" stroke="#F5F6F2" strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </div>
    ),
    { ...size }
  );
}
