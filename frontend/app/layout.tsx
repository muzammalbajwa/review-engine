import type { Metadata } from "next";
import { IBM_Plex_Mono, Inter, Space_Grotesk } from "next/font/google";
import "./globals.css";

// .claude/FRONTEND.md design brief: Space Grotesk (display, used with
// restraint), Inter (body), IBM Plex Mono (data/utility — tables,
// timestamps, IDs, audit logs). Self-hosted via next/font — no external
// font CDN request at runtime.
const spaceGrotesk = Space_Grotesk({
  variable: "--font-space-grotesk",
  subsets: ["latin"],
});

const inter = Inter({
  variable: "--font-inter",
  subsets: ["latin"],
});

const ibmPlexMono = IBM_Plex_Mono({
  variable: "--font-ibm-plex-mono",
  subsets: ["latin"],
  weight: ["400", "500", "600"],
});

export const metadata: Metadata = {
  // Required for og:image/twitter:image to resolve to an absolute URL —
  // without it, Next falls back to localhost even in production. Same
  // env var frontend/.env.local already uses for this frontend's own
  // public origin (NEXT_PUBLIC_APP_URL — see its own comment there:
  // matches backend's FRONTEND_URL, e.g. reviewengine.com in prod).
  metadataBase: new URL(process.env.NEXT_PUBLIC_APP_URL ?? "https://localhost:3000"),
  title: {
    default: "ReviewEngine",
    template: "%s | ReviewEngine",
  },
  description: "Get more Google reviews without any risk to your profile.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="en"
      className={`${spaceGrotesk.variable} ${inter.variable} ${ibmPlexMono.variable} h-full antialiased`}
    >
      <body className="min-h-full flex flex-col">{children}</body>
    </html>
  );
}
