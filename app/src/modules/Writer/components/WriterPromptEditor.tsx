/**
 * WRITER MODULE — AI Prompt Editor
 *
 * Prompt textarea with a dropdown that pulls prompt templates from the Templates module.
 * Only templates assigned to the 'writer' module with category 'prompt' are shown.
 *
 * Architecture:
 * - Data source: `trpc.templates.list({ module: 'writer' })` — same API used by TemplateDropdown.
 * - Filters entries client-side: only entries with `category === 'prompt'` are displayed.
 * - Selecting a template populates the textarea with the entry's value.
 * - "Custom Prompt" clears the textarea for freeform input.
 * - NO hardcoded presets — all prompt data is live from the Templates module.
 */

import { useState, useEffect, useMemo } from "react";
import { colors, typography } from "@/components/shared";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Variable } from "lucide-react";
import { PanelHeader } from "@/components/shared/PanelHeader";
import { trpc } from "@/lib/trpc";

interface Props {
  value: string;
  onChange: (val: string) => void;
}

/** Sentinel value for the "Custom Prompt" option (freeform text) */
const CUSTOM_PROMPT_VALUE = "__custom__";

export function WriterPromptEditor({ value, onChange }: Props) {
  const [selectedTemplateId, setSelectedTemplateId] = useState<string>("");

  // ── Fetch prompt templates from Templates module ──
  const { data: allTemplates = [], isLoading } = trpc.templates.list.useQuery({ module: 'writer' });

  // ── Filter to prompt-category templates only ──
  // Each template has an `entries` array; we show templates where at least one entry is category 'prompt'
  const promptTemplates = useMemo(() => {
    return allTemplates.filter((tpl: any) => {
      const entries = Array.isArray(tpl.entries) ? tpl.entries : [];
      return entries.some((e: any) => e.category === 'prompt');
    });
  }, [allTemplates]);

  // ── Extract the prompt value from a template's entries ──
  const getPromptValue = (tpl: any): string => {
    const entries = Array.isArray(tpl.entries) ? tpl.entries : [];
    const promptEntry = entries.find((e: any) => e.category === 'prompt');
    return promptEntry?.value ?? '';
  };

  // ── Sync dropdown selection when value changes externally (e.g. settings template population) ──
  useEffect(() => {
    if (!value) {
      setSelectedTemplateId("");
      return;
    }
    // Check if the current value matches any prompt template
    const matching = promptTemplates.find((tpl: any) => getPromptValue(tpl) === value);
    setSelectedTemplateId(matching ? String(matching.id) : CUSTOM_PROMPT_VALUE);
  }, [value, promptTemplates]);

  /** Handle dropdown selection */
  const handleSelect = (selectValue: string) => {
    setSelectedTemplateId(selectValue);

    if (selectValue === CUSTOM_PROMPT_VALUE) {
      onChange("");
      return;
    }

    // Find the selected template and populate the textarea with its prompt value
    const tpl = promptTemplates.find((t: any) => String(t.id) === selectValue);
    if (tpl) {
      onChange(getPromptValue(tpl));
    }
  };

  return (
    <div className="rounded-lg border overflow-hidden" style={{ borderColor: colors.border, background: colors.bgSurface }}>
      <PanelHeader
        title="AI Prompt"
        className="transition-colors"
        rightElement={
          <div className="w-48">
            <Select value={selectedTemplateId} onValueChange={handleSelect}>
              <SelectTrigger className="h-7 text-xs" style={{ background: colors.bgSurface, borderColor: colors.borderLight }}>
                <SelectValue placeholder={isLoading ? "Loading…" : "Select prompt…"} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={CUSTOM_PROMPT_VALUE}>Custom Prompt</SelectItem>
                {promptTemplates.map((tpl: any) => (
                  <SelectItem key={tpl.id} value={String(tpl.id)}>
                    {tpl.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        }
      />

      {/* Prompt Textarea */}
      <div className="p-0 relative group">
        <textarea
          value={value}
          onChange={(e) => {
            onChange(e.target.value);
            // Switch to "Custom" when user edits the text manually
            if (selectedTemplateId !== CUSTOM_PROMPT_VALUE) {
              setSelectedTemplateId(CUSTOM_PROMPT_VALUE);
            }
          }}
          placeholder="Enter prompt instructions here. Use {{variable}} syntax for dynamic injection."
          className="w-full text-sm p-3 outline-none resize-y min-h-[120px] font-mono"
          style={{ lineHeight: "1.6", color: colors.textSecondary, background: colors.bgSurface }}
        />

        {/* Status bar — visible on hover */}
        <div
          className="absolute bottom-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-2 backdrop-blur-sm px-2 py-1 rounded shadow-sm border text-[10px]"
          style={{ color: colors.textMuted, background: 'rgba(255,255,255,0.9)' }}
        >
          <Variable className="w-3 h-3" />
          <span>Supports {'{{'}variables{'}}'}</span>
        </div>
      </div>

      {/* Empty state when no prompt templates exist */}
      {!isLoading && promptTemplates.length === 0 && (
        <div className="px-3 pb-2">
          <span style={{ fontSize: typography.xs, color: colors.textFaint }}>
            No prompt templates yet. Create one in Templates → Writer → Prompt.
          </span>
        </div>
      )}
    </div>
  );
}
