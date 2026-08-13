import { Plus, X } from "lucide-react";
import type {
  WriterSectionConfig,
  WriterFieldConfig,
} from "../types";
// Shared controls only (20260715 card: same accordions + the same shared
// dropdowns/inputs as Ads and Video — no bespoke grey-styled ones).
import { colors } from "@/components/shared/design-tokens";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { trpc } from "@/lib/trpc";
import { AccordionSection } from "@/components/shared/AccordionSection";
import { Field, FieldLabel, FieldContent, FieldDescription } from "@/components/ui/field";

interface Props {
  section: WriterSectionConfig;
  values: Record<string, any>;
  onChange: (fieldId: string, value: any) => void;
  /** Overrides the section's own defaultCollapsed when provided. */
  defaultOpen?: boolean;
}

function DynamicListInput({
  value,
  onChange,
  placeholder,
}: {
  value: string[];
  onChange: (val: string[]) => void;
  placeholder?: string;
}) {
  const items = Array.isArray(value) ? value : [];

  const handleAdd = () => {
    onChange([...items, ""]);
  };

  const handleUpdate = (index: number, newVal: string) => {
    const next = [...items];
    next[index] = newVal;
    onChange(next);
  };

  const handleRemove = (index: number) => {
    const next = [...items];
    next.splice(index, 1);
    onChange(next);
  };

  return (
    <div className="space-y-2">
      {items.map((item, idx) => (
        <div key={idx} className="flex items-center gap-2">
          <Input
            type="url"
            value={item}
            onChange={(e) => handleUpdate(idx, e.target.value)}
            placeholder={placeholder}
            className="flex-1"
          />
          <button
            type="button"
            onClick={() => handleRemove(idx)}
            className="p-2 rounded-md transition-colors"
            onMouseEnter={(e) => (e.currentTarget.style.background = colors.bgHover)}
            onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
            style={{ color: colors.textMuted }}
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      ))}
      <button
        type="button"
        onClick={handleAdd}
        className="flex items-center gap-1 text-xs font-medium px-2 py-1.5 rounded transition-colors"
        style={{ color: colors.primary }}
      >
        <Plus className="w-3.5 h-3.5" />
        Add URL
      </button>
    </div>
  );
}

function FieldRenderer({
  field,
  value,
  onChange,
}: {
  field: WriterFieldConfig;
  value: any;
  onChange: (value: any) => void;
}) {
  switch (field.inputType) {
    case "text":
    case "url":
      return (
        <Input
          type={field.inputType === "url" ? "url" : "text"}
          value={(value as string) ?? ""}
          onChange={(e) => onChange(e.target.value)}
          placeholder={field.placeholder}
        />
      );

    case "switch":
      return (
        <div className="flex items-center h-10">
          <Switch
            checked={!!value}
            onCheckedChange={onChange}
          />
        </div>
      );

    case "textarea":
      return (
        <Textarea
          value={(value as string) ?? ""}
          onChange={(e) => onChange(e.target.value)}
          placeholder={field.placeholder}
          rows={3}
          className="resize-y"
        />
      );

    case "select": {
      const currentValue = (value as string) ?? field.defaultValue ?? "";
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
            {!field.defaultValue && (
              <SelectItem value={EMPTY_SENTINEL}>Select…</SelectItem>
            )}
            {field.options?.map((opt) => {
              if (opt.value === "" && !field.defaultValue) return null;
              const itemValue = opt.value === "" ? EMPTY_SENTINEL : opt.value;
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

    case "dynamic_list":
      return (
        <DynamicListInput
          value={value ?? []}
          onChange={onChange}
          placeholder={field.placeholder}
        />
      );

    default:
      return null;
  }
}

export function WriterDynamicSection({
  section,
  values,
  onChange,
  defaultOpen,
}: Props) {
  // Only fetch image models for the image_generation section — avoids redundant API calls from other sections
  const needsImageModels = section.id === 'image_generation';
  const { data: imageModels = [] } = trpc.models.getForGeneration.useQuery(
    { type: 'image' },
    { enabled: needsImageModels }
  );

  const visibleFields = section.fields;
  if (visibleFields.length === 0) return null;

  return (
    <AccordionSection
      title={section.title}
      defaultOpen={defaultOpen ?? !section.defaultCollapsed}
    >
      <div className="grid grid-cols-1 gap-y-4">
        {visibleFields.map((field) => (
          <Field key={field.id} orientation="vertical" className="mb-2">
            <FieldLabel className="text-xs font-medium" style={{ color: colors.textSecondary }}>
              {field.label}
            </FieldLabel>
            <FieldContent>
              {field.id === 'imageModel' ? (
                <FieldRenderer
                  field={{
                    ...field,
                    options: [
                      { value: 'auto', label: 'Auto (Any capable model)' },
                      ...imageModels.map(m => ({ value: m.modelId, label: m.customName || m.originalName }))
                    ]
                  }}
                  value={values[field.id]}
                  onChange={(val) => onChange(field.id, val)}
                />
              ) : (
                <FieldRenderer
                  field={field}
                  value={values[field.id]}
                  onChange={(val) => onChange(field.id, val)}
                />
              )}
              {field.helpText && (
                <FieldDescription className="text-[11px] mt-1 leading-snug" style={{ color: colors.textMuted }}>
                  {field.helpText}
                </FieldDescription>
              )}
            </FieldContent>
          </Field>
        ))}
      </div>
    </AccordionSection>
  );
}
