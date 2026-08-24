import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // The floating "N" badge next dev shows in the bottom-left corner —
  // route/build info for local development only, never rendered in a
  // production build (`next build && next start`). Disabled per request;
  // note this also removes it for every other developer running `next
  // dev` locally, not just this one screen.
  devIndicators: false,
  experimental: {
    serverActions: {
      // Server Actions default to a 1MB body cap. The CSV import wizard
      // uploads through a Server Action (backend/config/csv_import.php
      // caps files at 5MB), so this has to clear that plus multipart
      // overhead or Next.js rejects the upload before Laravel ever sees it.
      bodySizeLimit: "6mb",
    },
  },
};

export default nextConfig;
