import { useState, useRef } from "react";
import { ChevronDown, ChevronRight, X, Image as ImageIcon } from "lucide-react";
import { Switch } from "@/components/ui/switch";
import { LANGUAGES } from "@shared/brandTypes";
import { useBrandAssets } from "./hooks/useBrandAssets";
import { BrandColorSection } from "./BrandColorSection";
import { BrandLogoSection } from "./BrandLogoSection";
import { BrandAssetGrid } from "./BrandAssetGrid";
import { DEFAULT_BRAND_TOGGLES } from "./ContextPanel/types";
import type { ContextData } from "./ContextPanel/types";
import type { SessionReferenceImage } from "@shared/referenceImageIntents";
import { getBrandLogo } from "@shared/brandAssetResolver";

interface EnhancedBrandSectionProps {
  // Shared context (for brand colors, logo, and toggles)
  contextData: ContextData;
  onContextChange: (data: ContextData) => void;
  
  // Form values (for text fields: business_name, niche, etc.)
  formValues: Record<string, string | number | undefined>;
  onFormChange: (fieldId: string, value: string | number) => void;

  // Reference Subjects
  referenceImages?: SessionReferenceImage[];
  onReferenceImagesChange?: (images: SessionReferenceImage[]) => void;
}

export function EnhancedBrandSection({
  contextData,
  onContextChange,
  formValues,
  onFormChange,
  referenceImages = [],
  onReferenceImagesChange,
}: EnhancedBrandSectionProps) {
  const [isCollapsed, setIsCollapsed] = useState(true);
  const colorPickerRef = useRef<HTMLInputElement>(null);
  const certInputRef = useRef<HTMLInputElement>(null);
  const { 
    addColor, removeColor, assignAsPrimary, assignAsSecondary, refreshBrand,
    uploadCertification, removeCertification, certifications, isUploadingCertification
  } = useBrandAssets(contextData, onContextChange);

  // Hardcoded fields that match the exact visual layout of Copy's business_info section
  const textFields = [
    { id: "business_name", label: "Business Name", type: "text", width: "half", placeholder: "e.g. Acme Corp" },
    { id: "niche", label: "Niche / Industry", type: "text", width: "half", placeholder: "e.g. SaaS, E-commerce, Fitness" },
    { id: "location", label: "Location", type: "text", width: "half", placeholder: "e.g. Stockholm, Sweden" },
    { id: "phone", label: "Phone", type: "text", width: "half", placeholder: "+46 70 123 4567" },
    { id: "website", label: "Website", type: "url", width: "full", placeholder: "https://example.com" },
    { id: "business_summary", label: "Business Summary", type: "textarea", width: "full", placeholder: "Brief description...", helpText: "Auto-generated from URL fetch. Edit freely." },
    { id: "language", label: "Language", type: "select", width: "half", options: LANGUAGES },
  ];

  const brand = contextData.brand as Record<string, any> | null;
  const brandColors = (Array.isArray(brand?.colors) && brand.colors.length > 0) ? brand.colors as string[] : null;
  const brandLogo = getBrandLogo(brand);

  const toggles = { ...DEFAULT_BRAND_TOGGLES, ...contextData.brandToggles };

  const handleToggle = (key: keyof typeof toggles, checked: boolean) => {
    onContextChange({
      ...contextData,
      brandToggles: {
        ...toggles,
        [key]: checked,
      },
    });
  };

  const handleSubjectUpload = (file: File) => {
    if (!file || !onReferenceImagesChange) return;

    const reader = new FileReader();
    reader.onloadend = () => {
      if (typeof reader.result === 'string') {
        const newImage: SessionReferenceImage = {
          id: crypto.randomUUID(),
          url: reader.result,
          filename: file.name,
          mimeType: file.type,
          intent: 'subject', // Enforce intent as subject
        };
        onReferenceImagesChange([...referenceImages, newImage]);
      }
    };
    reader.readAsDataURL(file);
  };

  const handleSubjectPick = (url: string) => {
    if (!onReferenceImagesChange) return;
    const newImage: SessionReferenceImage = {
      id: crypto.randomUUID(),
      url,
      filename: 'Picked from brand',
      mimeType: 'image/*',
      intent: 'subject',
    };
    onReferenceImagesChange([...referenceImages, newImage]);
  };

  const handleCertificationPick = async (url: string) => {
    try {
      const res = await fetch(url);
      const blob = await res.blob();
      const file = new File([blob], "certification.png", { type: blob.type });
      await uploadCertification(file);
    } catch (e) {
      console.error("Failed to pick certification from brand", e);
    }
  };

  const removeSubject = (id: string) => {
    if (onReferenceImagesChange) {
      onReferenceImagesChange(referenceImages.filter(img => img.id !== id));
    }
  };

  // Count filled fields + active assets for the header counter
  const filledTextCount = textFields.filter(f => !!formValues[f.id]).length;
  const activeAssets = (toggles.useColors && brandColors ? 1 : 0) + (toggles.useLogo && brandLogo ? 1 : 0) + (toggles.useCertifications && certifications.length > 0 ? 1 : 0) + (referenceImages.length > 0 ? 1 : 0);
  const totalCount = filledTextCount + activeAssets;
  const totalPossible = textFields.length + 4; // 4 asset blocks

  return (
    <div className="rounded-lg border border-border mb-3">
      {/* Section Header */}
      <button
        type="button"
        onClick={() => setIsCollapsed(!isCollapsed)}
        className={`flex w-full items-center justify-between px-3 py-2 text-left transition-colors hover:bg-muted/30 bg-card ${
          isCollapsed ? "rounded-lg" : "rounded-t-lg"
        }`}
      >
        <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Brand Info & Assets
        </span>
        <div className="flex items-center gap-2">
          <span className="text-[10px] text-muted-foreground/80">
            {totalCount}/{totalPossible} items
          </span>
          {isCollapsed ? (
            <ChevronRight className="w-4 h-4" style={{ color: "#999" }} />
          ) : (
            <ChevronDown className="w-4 h-4" style={{ color: "#999" }} />
          )}
        </div>
      </button>

      {!isCollapsed && (
        <div className="p-3 pt-2 bg-background border-t border-border">
          {/* TEXT FIELDS (Exactly like DynamicSection) */}
          <div className="grid grid-cols-2 gap-y-2.5 gap-x-3">
            {textFields.map((field) => (
              <div key={field.id} className={field.width === "full" ? "col-span-2" : "col-span-1"}>
                <label className="block text-xs font-medium mb-1 text-muted-foreground">
                  {field.label}
                </label>
                
                {field.type === "text" || field.type === "url" ? (
                  <input
                    type={field.type}
                    value={(formValues[field.id] as string) ?? ""}
                    onChange={(e) => onFormChange(field.id, e.target.value)}
                    placeholder={field.placeholder}
                    className="w-full rounded-md border border-border px-3 py-2 text-sm outline-none bg-background text-foreground transition-colors focus:border-primary"
                  />
                ) : field.type === "textarea" ? (
                  <textarea
                    value={(formValues[field.id] as string) ?? ""}
                    onChange={(e) => onFormChange(field.id, e.target.value)}
                    placeholder={field.placeholder}
                    rows={3}
                    className="w-full rounded-md border border-border px-3 py-2 text-sm outline-none bg-background text-foreground resize-y transition-colors focus:border-primary"
                  />
                ) : field.type === "select" ? (
                  <select
                    value={(formValues[field.id] as string) ?? ""}
                    onChange={(e) => onFormChange(field.id, e.target.value)}
                    className="w-full rounded-md border border-border px-3 py-2 text-sm outline-none bg-background text-foreground transition-colors focus:border-primary"
                  >
                    <option value="">Select...</option>
                    {field.options?.map((opt) => (
                      <option key={opt as string} value={opt as string}>
                        {opt as string}
                      </option>
                    ))}
                  </select>
                ) : null}

                {field.helpText && (
                  <p className="text-[10px] mt-1 text-muted-foreground/70">
                    {field.helpText}
                  </p>
                )}
              </div>
            ))}
          </div>

          <div className="my-4 h-px w-full bg-border" />

          {/* VISUAL ASSETS (Enhancements) */}
          <div className="space-y-3">
            
            {/* Colors */}
            <div className="flex flex-col gap-2 p-2.5 rounded bg-muted/20 border border-border">
              <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-muted-foreground">Brand Colors</span>
                <div className="flex items-center gap-2">
                  <Switch
                    checked={toggles.useColors}
                    onCheckedChange={(c) => handleToggle("useColors", c)}
                    disabled={!brandColors}
                  />
                </div>
              </div>
              <div className={`transition-opacity ${!toggles.useColors ? 'opacity-40 grayscale pointer-events-none' : ''}`}>
                 {contextData.brandId ? (
                   <BrandColorSection
                     colors={brandColors || []}
                     maxColors={10}
                     additionalColorRef={colorPickerRef}
                     onAssignPrimary={assignAsPrimary}
                     onAssignSecondary={assignAsSecondary}
                     onRemove={removeColor}
                     onAdd={addColor}
                   />
                 ) : (
                   <span className="text-[10px] text-muted-foreground italic w-full">No brand selected.</span>
                 )}
              </div>
            </div>

            {/* Logo */}
            <div className="flex flex-col gap-2 p-2.5 rounded bg-muted/20 border border-border">
              <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-muted-foreground">Brand Logo</span>
                <Switch
                  checked={toggles.useLogo}
                  onCheckedChange={(c) => handleToggle("useLogo", c)}
                  disabled={!brandLogo}
                />
              </div>
              <div className={`transition-opacity ${!toggles.useLogo ? 'opacity-40 grayscale pointer-events-none' : ''}`}>
                 {contextData.brandId ? (
                   <BrandLogoSection
                     editBrand={contextData.brand as any}
                     logoAsset={brandLogo}
                     onLogoChanged={() => {
                       if (contextData.brandId) refreshBrand(contextData.brandId);
                     }}
                   />
                 ) : (
                   <span className="text-[10px] text-muted-foreground italic">No brand selected.</span>
                 )}
              </div>
            </div>

            {/* Reference Subjects */}
            <BrandAssetGrid
              title="Reference Subjects"
              items={referenceImages.map(img => ({ id: img.id, url: img.url }))}
              brandAssets={(contextData.brand?.assets as any) || []}
              isActive={!!toggles.useReferenceSubjects}
              onToggle={(c) => handleToggle("useReferenceSubjects", c)}
              onUpload={handleSubjectUpload}
              onPick={handleSubjectPick}
              onRemove={removeSubject}
            />

            {/* Certifications */}
            <BrandAssetGrid
              title="Certifications / Trust Badges"
              items={certifications.map(cert => ({ id: cert.fileKey, url: cert.url }))}
              brandAssets={(contextData.brand?.assets as any) || []}
              isActive={!!toggles.useCertifications}
              isUploading={isUploadingCertification}
              onToggle={(c) => handleToggle("useCertifications", c)}
              onUpload={uploadCertification}
              onPick={handleCertificationPick}
              onRemove={removeCertification}
            />

          </div>
        </div>
      )}
    </div>
  );
}
