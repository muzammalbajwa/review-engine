import type { NextConfig } from "next";

const nextConfig: NextConfig = {
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
