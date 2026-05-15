import { useState, useRef } from "react";
import { ChevronDown, ChevronRight, X, Image as ImageIcon } from "lucide-react";
import { Switch } from "@/components/ui/switch";
import { LANGUAGES } from "@shared/brandTypes";
import { useBrandAssets } from "./hooks/useBrandAssets";
import type { ContextData } from "./ContextPanel/types";
import type { SessionReferenceImage } from "@shared/referenceImageIntents";

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

const baseInputStyle = {
  borderColor: "#e5e7eb",
  background: "#fff",
  color: "#1a1a1a",
};

export function EnhancedBrandSection({
  contextData,
  onContextChange,
  formValues,
  onFormChange,
  referenceImages = [],
  onReferenceImagesChange,
}: EnhancedBrandSectionProps) {
  const [isCollapsed, setIsCollapsed] = useState(true);
  const [tempColor, setTempColor] = useState("#000000");
  const { addColor, removeColor, uploadLogo, removeLogo, isUploadingLogo } = useBrandAssets(contextData, onContextChange);

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
  const brandLogo = (Array.isArray(brand?.assets) && brand.assets.length > 0) ? brand.assets[0] : null;

  const toggles = contextData.brandToggles || { useSummary: true, useColors: true, useLogo: true };

  const handleToggle = (key: keyof typeof toggles, checked: boolean) => {
    onContextChange({
      ...contextData,
      brandToggles: {
        ...toggles,
        [key]: checked,
      },
    });
  };

  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleSubjectUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
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
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  const removeSubject = (id: string) => {
    if (onReferenceImagesChange) {
      onReferenceImagesChange(referenceImages.filter(img => img.id !== id));
    }
  };

  // Count filled fields + active assets for the header counter
  const filledTextCount = textFields.filter(f => !!formValues[f.id]).length;
  const activeAssets = (toggles.useColors && brandColors ? 1 : 0) + (toggles.useLogo && brandLogo ? 1 : 0) + (referenceImages.length > 0 ? 1 : 0);
  const totalCount = filledTextCount + activeAssets;
  const totalPossible = textFields.length + 3; // 3 asset blocks

  return (
    <div className="rounded-lg border mb-3" style={{ borderColor: "#e5e7eb" }}>
      {/* Section Header */}
      <button
        type="button"
        onClick={() => setIsCollapsed(!isCollapsed)}
        className="flex w-full items-center justify-between px-3 py-2 text-left transition-colors hover:bg-muted/30"
        style={{
          background: "#fff",
          borderRadius: isCollapsed ? "0.5rem" : "0.5rem 0.5rem 0 0",
        }}
      >
        <span className="text-xs font-semibold uppercase tracking-wide" style={{ color: "#555" }}>
          Brand Info & Assets
        </span>
        <div className="flex items-center gap-2">
          <span className="text-[10px]" style={{ color: "#999" }}>
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
                <label className="block text-xs font-medium mb-1" style={{ color: "#555" }}>
                  {field.label}
                </label>
                
                {field.type === "text" || field.type === "url" ? (
                  <input
                    type={field.type}
                    value={(formValues[field.id] as string) ?? ""}
                    onChange={(e) => onFormChange(field.id, e.target.value)}
                    placeholder={field.placeholder}
                    className="w-full rounded-md border px-3 py-2 text-sm outline-none transition-colors focus:border-primary"
                    style={baseInputStyle}
                  />
                ) : field.type === "textarea" ? (
                  <textarea
                    value={(formValues[field.id] as string) ?? ""}
                    onChange={(e) => onFormChange(field.id, e.target.value)}
                    placeholder={field.placeholder}
                    rows={3}
                    className="w-full rounded-md border px-3 py-2 text-sm outline-none resize-y transition-colors focus:border-primary"
                    style={baseInputStyle}
                  />
                ) : field.type === "select" ? (
                  <select
                    value={(formValues[field.id] as string) ?? ""}
                    onChange={(e) => onFormChange(field.id, e.target.value)}
                    className="w-full rounded-md border px-3 py-2 text-sm outline-none transition-colors focus:border-primary"
                    style={baseInputStyle}
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
                  <p className="text-[10px] mt-1" style={{ color: "#999" }}>
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
              <div className={`transition-opacity ${!toggles.useColors ? 'opacity-40 grayscale' : ''}`}>
                 <div className="flex flex-wrap gap-3 mt-1 items-start">
                   {brandColors ? brandColors.map((color, index) => {
                     const role = index === 0 ? "Main" : index === 1 ? "Secondary" : "Additional";
                     return (
                       <div key={`${color}-${index}`} className="flex flex-col items-center gap-1 group relative">
                         <div
                           className="w-6 h-6 rounded-full border shadow-sm relative overflow-hidden flex items-center justify-center cursor-pointer group-hover:border-destructive transition-colors"
                           style={{ backgroundColor: color, borderColor: "#e5e7eb" }}
                         >
                            <div 
                              onClick={() => removeColor(index)}
                              className="absolute inset-0 bg-destructive/80 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                            >
                              <X className="w-3 h-3" />
                            </div>
                         </div>
                         <span className="text-[9px] text-muted-foreground uppercase">{role}</span>
                       </div>
                     );
                   }) : (
                     <span className="text-[10px] text-muted-foreground italic w-full">No colors found. Fetch URL above.</span>
                   )}
                   
                   {(contextData.brandId) && (
                     <div className="flex flex-col items-center gap-1">
                       <div className="flex items-center gap-1.5 p-1 rounded-full border bg-white shadow-sm">
                         <label className="w-5 h-5 rounded-full border cursor-pointer hover:opacity-80 transition-opacity overflow-hidden relative">
                           <div className="absolute inset-0" style={{ backgroundColor: tempColor }} />
                           <input 
                             type="color" 
                             className="absolute opacity-0 w-0 h-0"
                             value={tempColor}
                             onChange={(e) => setTempColor(e.target.value)}
                           />
                         </label>
                         <button 
                           onClick={() => addColor(tempColor)}
                           className="w-5 h-5 rounded-full bg-primary text-primary-foreground flex items-center justify-center hover:bg-primary/90 transition-colors text-[10px] font-bold"
                           title="Save Color"
                         >
                           +
                         </button>
                       </div>
                       <span className="text-[9px] text-muted-foreground uppercase mt-0.5">Add</span>
                     </div>
                   )}
                 </div>
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
              <div className={`transition-opacity ${!toggles.useLogo ? 'opacity-40 grayscale' : ''}`}>
                 {brandLogo ? (
                   <div className="flex items-end gap-3">
                     <div className="relative inline-block">
                       <img
                         src={brandLogo.url}
                         alt="Brand logo"
                         className="h-10 w-auto object-contain rounded border border-border bg-white"
                       />
                       <button
                         onClick={removeLogo}
                         className="absolute -top-1.5 -right-1.5 w-4 h-4 bg-destructive text-destructive-foreground rounded-full flex items-center justify-center shadow-sm hover:scale-110 transition-transform"
                         title="Remove Logo"
                       >
                         <X className="w-2.5 h-2.5" />
                       </button>
                     </div>
                     <label className="flex items-center justify-center h-6 px-2 border border-dashed border-muted-foreground/50 rounded cursor-pointer hover:bg-muted/50 transition-colors mb-1">
                       {isUploadingLogo ? (
                         <span className="text-[10px] text-muted-foreground animate-pulse">Uploading...</span>
                       ) : (
                         <span className="text-[10px] text-muted-foreground font-medium hover:underline">Replace</span>
                       )}
                       <input
                         type="file"
                         accept="image/*"
                         className="hidden"
                         onChange={(e) => {
                           if (e.target.files?.[0]) {
                             uploadLogo(e.target.files[0]);
                             e.target.value = ''; // Reset input
                           }
                         }}
                       />
                     </label>
                   </div>
                 ) : contextData.brandId ? (
                   <div className="relative">
                     <label className="flex items-center justify-center w-full h-10 border border-dashed border-muted-foreground/50 rounded cursor-pointer hover:bg-muted/50 transition-colors">
                       {isUploadingLogo ? (
                         <span className="text-[10px] text-muted-foreground animate-pulse">Uploading...</span>
                       ) : (
                         <span className="text-[10px] text-muted-foreground font-medium hover:underline">+ Upload Logo</span>
                       )}
                       <input
                         type="file"
                         accept="image/*"
                         className="hidden"
                         onChange={(e) => {
                           if (e.target.files?.[0]) {
                             uploadLogo(e.target.files[0]);
                             e.target.value = ''; // Reset input
                           }
                         }}
                       />
                     </label>
                   </div>
                 ) : (
                   <span className="text-[10px] text-muted-foreground italic">No logo found. Select a brand.</span>
                 )}
              </div>
            </div>

            {/* Reference Subjects */}
            <div className="flex flex-col gap-2 p-2.5 rounded bg-muted/20 border border-border">
              <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-muted-foreground">Reference Subjects</span>
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  className="text-[10px] font-medium text-primary hover:underline"
                >
                  + Add Reference
                </button>
              </div>
              <input
                ref={fileInputRef}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={handleSubjectUpload}
              />
              
              {referenceImages.length > 0 ? (
                <div className="flex flex-wrap gap-2 mt-1">
                  {referenceImages.map((img) => (
                    <div key={img.id} className="relative group">
                      <img
                        src={img.url}
                        alt="Reference"
                        className="w-10 h-10 object-cover rounded border border-border"
                      />
                      <button
                        onClick={() => removeSubject(img.id)}
                        className="absolute -top-1.5 -right-1.5 w-4 h-4 bg-destructive text-destructive-foreground rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                        title="Remove"
                      >
                        <X className="w-2.5 h-2.5" />
                      </button>
                    </div>
                  ))}
                </div>
              ) : (
                <span className="text-[10px] text-muted-foreground italic">Select images to reference in prompt.</span>
              )}
            </div>

          </div>
        </div>
      )}
    </div>
  );
}
