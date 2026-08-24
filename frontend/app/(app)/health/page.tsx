type HealthResponse = {
  data: {
    status: string;
  };
};

async function getHealth(): Promise<
  { ok: true; body: HealthResponse } | { ok: false; error: string }
> {
  const apiUrl = process.env.NEXT_PUBLIC_API_URL;

  if (!apiUrl) {
    return { ok: false, error: "NEXT_PUBLIC_API_URL is not set" };
  }

  try {
    const res = await fetch(`${apiUrl}/api/v1/health`, { cache: "no-store" });

    if (!res.ok) {
      return { ok: false, error: `API responded with ${res.status}` };
    }

    return { ok: true, body: await res.json() };
  } catch {
    return { ok: false, error: `Could not reach ${apiUrl}` };
  }
}

export default async function HealthPage() {
  const result = await getHealth();

  return (
    <main className="flex min-h-screen items-center justify-center p-8">
      <div className="w-full max-w-md rounded-lg border p-6">
        <h1 className="mb-4 text-lg font-semibold">API Connection Check</h1>
        {result.ok ? (
          <>
            <p className="mb-2 text-sm text-green-600">
              Frontend reached the Laravel API.
            </p>
            <pre className="rounded bg-neutral-100 p-3 text-sm dark:bg-neutral-900">
              {JSON.stringify(result.body, null, 2)}
            </pre>
          </>
        ) : (
          <p className="text-sm text-red-600">{result.error}</p>
        )}
      </div>
    </main>
  );
}
