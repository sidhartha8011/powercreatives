import React from "react";
import { colors } from "@/components/shared/design-tokens";
import { Button } from "@/components/ui/button";
import { Globe, Loader2, CheckCircle2, AlertCircle } from "lucide-react";

interface Props {
  url: string;
  handleUrlChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  handleUrlFetch: () => void;
  fetchStatus: "idle" | "success" | "error";
  fetchError: string;
  isFetching: boolean;
}

export function UrlInput({
  url,
  handleUrlChange,
  handleUrlFetch,
  fetchStatus,
  fetchError,
  isFetching,
}: Props) {
  return (
    <div className="space-y-1.5">
      <div className="flex gap-2">
        <div className="relative flex-1">
          <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
            <Globe className="w-4 h-4" style={{ color: colors.textFaint }} />
          </div>
          <input
            type="url"
            value={url}
            onChange={handleUrlChange}
            placeholder="Paste client website URL…"
            className="w-full rounded-md border pr-3 py-2 text-sm outline-none min-w-0"
            style={{
              paddingLeft: '2.5rem',
              borderColor: fetchStatus === "error" ? colors.danger : colors.border,
              background: colors.bgSurface,
            }}
            onKeyDown={(e) => {
              if (e.key === "Enter") handleUrlFetch();
            }}
            disabled={isFetching}
          />
        </div>
        <Button
          size="sm"
          onClick={handleUrlFetch}
          disabled={isFetching || !url.trim()}
          className="shrink-0 gap-1.5"
        >
          {isFetching ? (
            <Loader2 className="w-3.5 h-3.5 animate-spin" />
          ) : fetchStatus === "success" ? (
            <CheckCircle2 className="w-3.5 h-3.5" />
          ) : null}
          {isFetching ? "Analyzing…" : fetchStatus === "success" ? "Done" : "Fetch Info"}
        </Button>
      </div>

      {fetchStatus === "error" && fetchError && (
        <div className="flex items-start gap-1.5 text-[11px]" style={{ color: colors.danger }}>
          <AlertCircle className="w-3 h-3 mt-0.5 shrink-0" />
          <span>{fetchError}</span>
        </div>
      )}

      {fetchStatus === "success" && (
        <div className="flex items-center gap-1.5 text-[11px]" style={{ color: colors.success }}>
          <CheckCircle2 className="w-3 h-3 shrink-0" />
          <span>Business info extracted successfully</span>
        </div>
      )}
    </div>
  );
}
