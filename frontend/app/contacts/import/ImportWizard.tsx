"use client";

import { useMemo, useRef, useState } from "react";

import { Button } from "@/components/ui/button";
import { confirmImport, previewImport, type ImportResult, type PreviewResult } from "./actions";

type Step = "upload" | "mapping" | "done";

/** Best-effort default mapping so most tenants don't have to touch the dropdowns. */
function guessColumn(headers: string[], keywords: string[]): string {
  const match = headers.find((h) => keywords.some((k) => h.toLowerCase().includes(k)));
  return match ?? "";
}

export function ImportWizard() {
  const [step, setStep] = useState<Step>("upload");
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<PreviewResult | null>(null);
  const [mapping, setMapping] = useState({ name: "", phone: "", email: "" });
  const [result, setResult] = useState<ImportResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const invalidCount = useMemo(() => preview?.rows.filter((r) => !r.valid).length ?? 0, [preview]);
  const warningCount = useMemo(
    () => preview?.rows.filter((r) => r.warnings.length > 0).length ?? 0,
    [preview]
  );

  async function handlePreview() {
    if (!file) {
      setError("Choose a CSV file first.");
      return;
    }

    setPending(true);
    setError(null);

    const formData = new FormData();
    formData.set("file", file);

    const state = await previewImport(formData);
    setPending(false);

    if (state.status === "error") {
      setError(state.message);
      return;
    }

    setPreview(state.preview);
    setMapping({
      name: guessColumn(state.preview.headers, ["name"]),
      phone: guessColumn(state.preview.headers, ["phone", "mobile", "cell"]),
      email: guessColumn(state.preview.headers, ["email"]),
    });
    setStep("mapping");
  }

  async function handleConfirm() {
    if (!file) return;

    setPending(true);
    setError(null);

    const formData = new FormData();
    formData.set("file", file);
    formData.set("mapping_name", mapping.name);
    formData.set("mapping_phone", mapping.phone);
    formData.set("mapping_email", mapping.email);

    const state = await confirmImport(formData);
    setPending(false);

    if (state.status === "error") {
      setError(state.message);
      return;
    }

    setResult(state.result);
    setStep("done");
  }

  function reset() {
    setStep("upload");
    setFile(null);
    setPreview(null);
    setMapping({ name: "", phone: "", email: "" });
    setResult(null);
    setError(null);
    if (fileInputRef.current) fileInputRef.current.value = "";
  }

  return (
    <div className="flex w-full max-w-3xl flex-col gap-6">
      <ol className="flex gap-4 text-sm text-muted-foreground">
        <li className={step === "upload" ? "font-semibold text-foreground" : ""}>1. Upload</li>
        <li className={step === "mapping" ? "font-semibold text-foreground" : ""}>
          2. Map &amp; preview
        </li>
        <li className={step === "done" ? "font-semibold text-foreground" : ""}>3. Done</li>
      </ol>

      {error && (
        <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </p>
      )}

      {step === "upload" && (
        <div className="flex flex-col gap-4 rounded-lg border p-6">
          <label htmlFor="csv-file" className="text-sm font-medium">
            Upload a CSV of your past customers
          </label>
          <input
            id="csv-file"
            ref={fileInputRef}
            type="file"
            accept=".csv,.txt,text/csv,text/plain"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            className="text-sm"
          />
          <div>
            <Button onClick={handlePreview} disabled={!file || pending}>
              {pending ? "Reading file..." : "Preview"}
            </Button>
          </div>
        </div>
      )}

      {step === "mapping" && preview && (
        <div className="flex flex-col gap-6">
          <div className="rounded-lg border p-6">
            <h2 className="mb-4 text-sm font-semibold">Which column is which?</h2>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <ColumnSelect
                label="Name (required)"
                headers={preview.headers}
                value={mapping.name}
                onChange={(v) => setMapping((m) => ({ ...m, name: v }))}
              />
              <ColumnSelect
                label="Phone"
                headers={preview.headers}
                value={mapping.phone}
                onChange={(v) => setMapping((m) => ({ ...m, phone: v }))}
                allowNone
              />
              <ColumnSelect
                label="Email"
                headers={preview.headers}
                value={mapping.email}
                onChange={(v) => setMapping((m) => ({ ...m, email: v }))}
                allowNone
              />
            </div>
          </div>

          <div className="rounded-lg border p-6">
            <h2 className="mb-4 text-sm font-semibold">
              Validation preview — {preview.rows.length} of {preview.total_row_count} rows shown
              {invalidCount > 0 && (
                <span className="ml-2 font-normal text-destructive">
                  ({invalidCount} will be skipped)
                </span>
              )}
              {warningCount > 0 && (
                <span className="ml-2 font-normal text-warning">
                  ({warningCount} auto-corrected)
                </span>
              )}
            </h2>

            {preview.row_cap_exceeded && (
              <p className="mb-4 text-sm text-destructive">
                This file has more rows than the import cap allows. Only the first{" "}
                {preview.rows.length} rows will be imported.
              </p>
            )}

            <div className="max-h-80 overflow-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b text-muted-foreground">
                    <th className="py-1 pr-2">Row</th>
                    {preview.headers.map((h) => (
                      <th key={h} className="py-1 pr-2">
                        {h}
                      </th>
                    ))}
                    <th className="py-1 pr-2">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {preview.rows.map((row) => (
                    <tr key={row.row_number} className="border-b last:border-0">
                      <td className="py-1 pr-2 text-muted-foreground">{row.row_number}</td>
                      {preview.headers.map((h) => (
                        <td key={h} className="py-1 pr-2">
                          {row.data[h]}
                        </td>
                      ))}
                      <td className="py-1 pr-2">
                        {!row.valid ? (
                          <span className="text-destructive" title={row.errors.join("; ")}>
                            skipped
                          </span>
                        ) : row.warnings.length > 0 ? (
                          <span className="text-warning" title={row.warnings.join("; ")}>
                            corrected
                          </span>
                        ) : (
                          <span className="text-muted-foreground">ok</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div className="flex gap-2">
            <Button variant="outline" onClick={reset} disabled={pending}>
              Start over
            </Button>
            <Button onClick={handleConfirm} disabled={pending || mapping.name === ""}>
              {pending ? "Importing..." : "Confirm import"}
            </Button>
          </div>
        </div>
      )}

      {step === "done" && result && (
        <div className="flex flex-col gap-4 rounded-lg border p-6">
          <h2 className="text-sm font-semibold">Import complete</h2>
          <p className="text-sm">
            Imported <strong>{result.imported}</strong> contact{result.imported === 1 ? "" : "s"}.
            {result.skipped > 0 && (
              <>
                {" "}
                Skipped <strong>{result.skipped}</strong> invalid row
                {result.skipped === 1 ? "" : "s"}.
              </>
            )}
          </p>
          <div>
            <Button onClick={reset}>Import another file</Button>
          </div>
        </div>
      )}
    </div>
  );
}

function ColumnSelect({
  label,
  headers,
  value,
  onChange,
  allowNone,
}: {
  label: string;
  headers: string[];
  value: string;
  onChange: (value: string) => void;
  allowNone?: boolean;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <label className="text-xs font-medium text-muted-foreground">{label}</label>
      <select
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="h-9 rounded-lg border border-border bg-background px-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
      >
        {allowNone && <option value="">— none —</option>}
        {!allowNone && value === "" && <option value="">— choose —</option>}
        {headers.map((h) => (
          <option key={h} value={h}>
            {h}
          </option>
        ))}
      </select>
    </div>
  );
}
