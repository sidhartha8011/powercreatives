/**
 * DYNAMIC SECTION — Shared Component
 *
 * Renders a collapsible section with its fields based on config.
 * Fields are shown/hidden based on currently selected types.
 * All rendering is driven by SectionConfig — no hardcoded UI.
 *
 * Select fields use shadcn Select components for consistent styling.
 *
 * Originally from Copy module — extracted to shared/ for cross-module reuse.
 */

import { useState } from "react";
import { ChevronDown, ChevronRight } from "lucide-react";
import type { SectionConfig, FieldConfig } from "./copySettingsConfig";
import { isFieldVisible } from "./copySettingsConfig";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

interface Props {
  section: SectionConfig;
  values: Record<string, string | number | undefined>;
  selectedTypes: Record<string, boolean>;
  onChange: (fieldId: string, value: string | number) => void;
}

function FieldRenderer({
  field,
  value,
  onChange,
}: {
  field: FieldConfig;
  value: string | number | undefined;
  onChange: (value: string | number) => void;
}) {
  const baseInputStyle = {
    borderColor: "#e5e7eb",
    background: "#fff",
    color: "#1a1a1a",
  };

  switch (field.inputType) {
    case "text":
    case "url":
      return (
        <input
          type={field.inputType === "url" ? "url" : "text"}
          value={(value as string) ?? ""}
          onChange={(e) => onChange(e.target.value)}
          placeholder={field.placeholder}
          className="w-full rounded-md border px-3 py-2 text-sm outline-none"
          style={baseInputStyle}
        />
      );

    case "textarea":
      return (
        <textarea
          value={(value as string) ?? ""}
          onChange={(e) => onChange(e.target.value)}
          placeholder={field.placeholder}
          rows={3}
          className="w-full rounded-md border px-3 py-2 text-sm outline-none resize-y"
          style={baseInputStyle}
        />
      );

    case "number":
      return (
        <input
          type="number"
          value={value ?? ""}
          onChange={(e) =>
            onChange(e.target.value ? Number(e.target.value) : "")
          }
          placeholder={field.placeholder}
          min={field.min}
          max={field.max}
          step={field.step}
          className="w-full rounded-md border px-3 py-2 text-sm outline-none"
          style={baseInputStyle}
        />
      );

    case "select": {
      // Use shadcn Select component
      const currentValue = (value as string) ?? field.defaultValue ?? "";

      // shadcn Select doesn't support empty string values well,
      // so we use a sentinel for "no selection"
      const EMPTY_SENTINEL = "__empty__";
      const selectValue = currentValue === "" ? EMPTY_SENTINEL : currentValue;

      return (
        <Select
          value={selectValue}
          onValueChange={(v) => onChange(v === EMPTY_SENTINEL ? "" : v)}
        >
          <SelectTrigger className="w-full">
            <SelectValue placeholder="Select…" />
          </SelectTrigger>
          <SelectContent>
            {/* Show "Select…" / "None" option if no default or if field has empty option */}
            {!field.defaultValue && (
              <SelectItem value={EMPTY_SENTINEL}>Select…</SelectItem>
            )}
            {field.options?.map((opt) => {
              // Skip empty-value options since we handle them via EMPTY_SENTINEL
              if (opt.value === "" && !field.defaultValue) return null;
              const itemValue =
                opt.value === "" ? EMPTY_SENTINEL : opt.value;
              return (
                <SelectItem key={opt.value || "__none__"} value={itemValue}>
                  {opt.label}
                </SelectItem>
              );
            })}
          </SelectContent>
        </Select>
      );
    }

    case "slider":
      return (
        <div className="flex items-center gap-3">
          <input
            type="range"
            value={(value as number) ?? field.min ?? 0}
            onChange={(e) => onChange(Number(e.target.value))}
            min={field.min}
            max={field.max}
            step={field.step}
            className="flex-1"
          />
          <span
            className="text-sm font-medium"
            style={{ color: "#555", minWidth: "2rem", textAlign: "right" }}
          >
            {value ?? field.min ?? 0}
          </span>
        </div>
      );

    default:
      return null;
  }
}

export function DynamicSection({
  section,
  values,
  selectedTypes,
  onChange,
}: Props) {
  const [isCollapsed, setIsCollapsed] = useState(section.defaultCollapsed);

  // Filter fields to only those visible for current type selection
  const visibleFields = section.fields.filter((f) =>
    isFieldVisible(f, selectedTypes)
  );

  if (visibleFields.length === 0) return null;

  // Count fields that have been filled (non-empty string, non-zero number)
  const filledCount = visibleFields.filter((f) => {
    const v = values[f.id];
    if (v === undefined || v === null || v === '') return false;
    // Treat default select values (e.g. 'auto', 'en') as unfilled
    if (f.defaultValue !== undefined && v === f.defaultValue) return false;
    return true;
  }).length;

  return (
    <div className="rounded-lg border" style={{ borderColor: "#e5e7eb" }}>
      {/* Section Header */}
      <button
        type="button"
        onClick={() => setIsCollapsed(!isCollapsed)}
        className="flex w-full items-center justify-between px-3 py-2 text-left"
        style={{
          background: "#fff",
          borderRadius: isCollapsed
            ? "0.5rem"
            : "0.5rem 0.5rem 0 0",
        }}
      >
        <span
          className="text-xs font-semibold uppercase tracking-wide"
          style={{ color: "#555" }}
        >
          {section.title}
        </span>
        <div className="flex items-center gap-2">
          <span className="text-[10px]" style={{ color: "#999" }}>
            {filledCount}/{visibleFields.length} field
            {visibleFields.length !== 1 ? "s" : ""}
          </span>
          {isCollapsed ? (
            <ChevronRight className="w-4 h-4" style={{ color: "#999" }} />
          ) : (
            <ChevronDown className="w-4 h-4" style={{ color: "#999" }} />
          )}
        </div>
      </button>

      {/* Fields — single column for compact sidebar */}
      {!isCollapsed && (
        <div className="p-3 pt-2">
          <div className="grid grid-cols-1 gap-y-2.5">
            {visibleFields.map((field) => (
              <div key={field.id} className="col-span-1">
                <label
                  className="block text-xs font-medium mb-1"
                  style={{ color: "#555" }}
                >
                  {field.label}
                  {/* Show required asterisk for required fields */}
                  {field.requirements &&
                    Object.values(field.requirements).includes('required') && (
                      <span style={{ color: "#ef4444" }}> *</span>
                    )}
                </label>
                <FieldRenderer
                  field={field}
                  value={values[field.id]}
                  onChange={(val) => onChange(field.id, val)}
                />
                {field.helpText && (
                  <p
                    className="text-[10px] mt-1"
                    style={{ color: "#999" }}
                  >
                    {field.helpText}
                  </p>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
