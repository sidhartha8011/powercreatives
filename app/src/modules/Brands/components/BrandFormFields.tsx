/**
 * BRANDS MODULE — Form Fields (Left Panel)
 *
 * Renders the text input fields for the brand form:
 * Name, Website, Niche, Location, Phone, Language, Business Summary.
 *
 * Pure presentational component — receives form state via props.
 * Uses shared UI components (Input, Textarea, Label, CreatableCombobox).
 */

import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { CreatableCombobox } from "@/components/ui/creatable-combobox";
import { LANGUAGES } from "@shared/brandTypes";
import type { BrandFormData } from "../types";

interface BrandFormFieldsProps {
  form: BrandFormData;
  onChange: (updated: BrandFormData) => void;
}

export function BrandFormFields({ form, onChange }: BrandFormFieldsProps) {
  /** Helper to update a single field */
  const set = (field: keyof BrandFormData, value: string) => {
    onChange({ ...form, [field]: value });
  };

  return (
    <div className="flex-1 min-w-0 space-y-4">
      {/* Name (required) */}
      <div className="space-y-1.5">
        <Label htmlFor="brand-name">
          Name <span className="text-destructive">*</span>
        </Label>
        <Input
          id="brand-name"
          placeholder="e.g. Profit Media"
          value={form.name}
          onChange={(e) => set("name", e.target.value)}
          autoFocus
        />
      </div>

      {/* Website */}
      <div className="space-y-1.5">
        <Label htmlFor="brand-website">Website</Label>
        <Input
          id="brand-website"
          placeholder="https://example.com"
          value={form.website}
          onChange={(e) => set("website", e.target.value)}
        />
      </div>

      {/* Niche + Location row */}
      <div className="grid grid-cols-2 gap-3">
        <div className="space-y-1.5">
          <Label htmlFor="brand-niche">Niche</Label>
          <Input
            id="brand-niche"
            placeholder="e.g. SaaS, E-commerce"
            value={form.niche}
            onChange={(e) => set("niche", e.target.value)}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="brand-location">Location</Label>
          <Input
            id="brand-location"
            placeholder="e.g. Stockholm, Sweden"
            value={form.location}
            onChange={(e) => set("location", e.target.value)}
          />
        </div>
      </div>

      {/* Phone + Language row */}
      <div className="grid grid-cols-2 gap-3">
        <div className="space-y-1.5">
          <Label htmlFor="brand-phone">Phone</Label>
          <Input
            id="brand-phone"
            placeholder="+46 70 123 4567"
            value={form.phone}
            onChange={(e) => set("phone", e.target.value)}
          />
        </div>
        <div className="space-y-1.5">
          <Label>Language</Label>
          <CreatableCombobox
            options={LANGUAGES as unknown as string[]}
            value={form.language || null}
            onChange={(val) => onChange({ ...form, language: val ?? "" })}
            placeholder="Select language..."
            emptyLabel="No languages found"
          />
        </div>
      </div>

      {/* Business Summary */}
      <div className="space-y-1.5">
        <Label htmlFor="brand-summary">Business Summary</Label>
        <Textarea
          id="brand-summary"
          placeholder="Brief description of the business, value proposition, and target market..."
          value={form.businessSummary}
          onChange={(e) => set("businessSummary", e.target.value)}
          rows={3}
        />
      </div>
    </div>
  );
}
