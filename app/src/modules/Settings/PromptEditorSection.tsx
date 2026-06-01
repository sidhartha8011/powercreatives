/**
 * PROMPT EDITOR SECTION
 *
 * Settings section for editing system prompts per module.
 * Supports multiple named variants per section — displayed as clickable tabs.
 *
 * Architecture:
 *   - Module tabs (Copy / Image / Video) driven by PROMPT_SECTION_META
 *   - If module has 1 section → show variant editor directly
 *   - If module has 2+ sections → render subtabs, each with variant editor
 *   - If module has 0 sections → show empty state
 *   - Variants: each section can have N named variants (tabs within the editor)
 *   - Actions per variant: Rename, Duplicate, Delete, Set as Default (via DropdownMenu)
 *   - "+ New" button to create a blank variant
 *
 * @example
 * <PromptEditorSection />
 */

import { trpc, apiFetch } from "@/lib/trpc";
import { exportToCsv } from "@/lib/exportCsv";
import { parseCsvFile, validateCsvHeaders } from "@/lib/importCsv";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import {
  Save,
  RotateCcw,
  Loader2,
  Info,
  Pen,
  Image,
  Video,
  AlertCircle,
  Plus,
  MoreHorizontal,
  Copy,
  Trash2,
  Star,
  PencilLine,
  Download,
  Upload,
  RefreshCw,
  Briefcase,
  Palette,
  Paperclip,
  Target,
  Settings2,
  Search,
  Megaphone,
} from "lucide-react";
import { useState, useEffect, useCallback, useRef } from "react";
import { toast } from "sonner";

/** Module definitions for tabs */
const MODULES = [
  { id: "copy" as const, label: "Copy", icon: Pen },
  { id: "ads" as const, label: "Ads", icon: Megaphone },
  { id: "image" as const, label: "Image", icon: Image },
  { id: "video" as const, label: "Video", icon: Video },
];

/**
 * Available placeholders grouped by category.
 * Shown as a reference guide below the prompt editor.
 */
interface PlaceholderItem { key: string; description: string }
interface PlaceholderGroup { group: string; icon: React.ComponentType<{ className?: string }>; items: PlaceholderItem[] }

const PLACEHOLDERS: Record<string, PlaceholderGroup[]> = {
  copy: [
    {
      group: 'Business',
      icon: Briefcase,
      items: [
        { key: '{{brief}}', description: 'Business metadata: name, industry, offer, pricing, contact info (Ads/Organic prompts)' },
        { key: '{{brandName}}', description: 'Brand/business name — from Business Info → Name (Angle/Audience prompts)' },
        { key: '{{product}}', description: 'Product or service — from Business Info → Product/Service (Angle/Audience prompts)' },
        { key: '{{description}}', description: 'Business description — from Business Info → Description (Angle/Audience prompts)' },
        { key: '{{creativeBrief}}', description: 'Free-form user directions — from Creative Brief textarea. Gets its own section in the prompt for emphasis (all prompts)' },
      ],
    },
    {
      group: 'Style & Tone',
      icon: Palette,
      items: [
        { key: '{{toneInstruction}}', description: 'Resolved tone rules as full instruction text — priority: template tonality > dropdown > auto (Ads/Organic prompts)' },
        { key: '{{tone}}', description: 'Raw tone key (e.g. "professional", "casual") — from Tone dropdown (Angle/Audience prompts)' },
        { key: '{{emojiInstruction}}', description: 'Emoji usage rules — from Advanced Options → Emoji Level (Ads/Organic prompts)' },
        { key: '{{ctaInstruction}}', description: 'CTA style rules — from Advanced Options → CTA Style. Only injected for Ads prompts' },
        { key: '{{language}}', description: 'Target language (e.g. "Swedish", "English") — from Settings → Language' },
      ],
    },
    {
      group: 'Reference Material',
      icon: Paperclip,
      items: [
        { key: '{{referenceCopy}}', description: 'Reference ads with style analysis context — from Reference Ads section (Ads/Organic prompts)' },
        { key: '{{referenceAds}}', description: 'Reference ads raw text — same source as {{referenceCopy}} but for Angle/Audience prompts' },
        { key: '{{reviewsContext}}', description: 'Customer reviews — from Customer Reviews section (Ads/Organic prompts)' },
        { key: '{{researchContext}}', description: 'Market research data from the research step — auto-injected when research is enabled (Audience prompts)' },
      ],
    },
    {
      group: 'Campaign',
      icon: Target,
      items: [
        { key: '{{campaignContext}}', description: 'Season/event + campaign theme — from Theme section (all prompts)' },
        { key: '{{organicContext}}', description: 'Content pillar + post format — Organic-specific fields. Only injected for Organic prompts' },
      ],
    },
    {
      group: 'Runtime (auto-injected)',
      icon: Settings2,
      items: [
        { key: '{{angle}}', description: 'Current angle name being generated — set automatically per generation task' },
        { key: '{{audience}}', description: 'Current audience name being generated — set automatically per generation task' },
        { key: '{{count}}', description: 'Number of items to generate — from the Auto mode count selector' },
        { key: '{{audienceList}}', description: 'JSON array of all audiences — auto-injected in Angle Generation (Audience-Aware) prompt only' },
      ],
    },
  ],
  image: [
    {
      group: 'Context',
      icon: Briefcase,
      items: [
        { key: '{{brief}}', description: 'Product brief text — from the Product Brief textarea (Prompt Suggestions only)' },
        { key: '{{brandName}}', description: 'Resolves to "Brand: <name>" — from the selected brand profile' },
        { key: '{{brandSummary}}', description: 'Resolves to "Brand Summary: <text>" — from the brand business summary (Context Suggestions only)' },
        { key: '{{url}}', description: 'Resolves to "Website: <url>" — from the URL input (Context Suggestions only)' },
        { key: '{{niche}}', description: 'Resolves to "Niche/Industry: <value>" — from the Niche input' },
        { key: '{{location}}', description: 'Resolves to "Location: <value>" — from the Location input' },
        { key: '{{language}}', description: 'Resolves to "Language: <value>" — from the Language select' },
        { key: '{{phone}}', description: 'Resolves to "Phone: <value>" — from the Phone input' },
        { key: '{{brandColors}}', description: 'Resolves to "Brand Colors: primary #3A8D9A, secondary #FFFFFF, ..." — from the brand color palette' },
      ],
    },
    {
      group: 'Campaign',
      icon: Target,
      items: [
        { key: '{{seasonEvent}}', description: 'Resolves to "Season/Event: <value>" — from the Season/Event dropdown' },
        { key: '{{campaignTheme}}', description: 'Resolves to "Campaign Theme: <value>" — from the Campaign Theme input' },
      ],
    },
    {
      group: 'Runtime (auto-injected)',
      icon: Settings2,
      items: [
        { key: '{{count}}', description: 'Number of suggestions to generate — set automatically from the suggestion count' },
        { key: '{{referenceImageIntent}}', description: 'Dominant intent tag from reference images: variation, style, product, person, environment, or empty if no image — used by Angles (Scenes) prompt' },
      ],
    },
  ],
  // Ads module — same placeholders as copy since Ads delegates to Copy service.
  // These help the user understand which {{variables}} are available when editing
  // their Ads-specific prompts in Settings → Default Prompts → Ads tab.
  ads: [
    {
      group: 'Business',
      icon: Briefcase,
      items: [
        { key: '{{brief}}', description: 'Business metadata: name, industry, offer, pricing, contact info (Ads/Organic prompts)' },
        { key: '{{brandName}}', description: 'Brand/business name — from Business Info → Name (Angle/Audience prompts)' },
        { key: '{{product}}', description: 'Product or service — from Business Info → Product/Service (Angle/Audience prompts)' },
        { key: '{{description}}', description: 'Business description — from Business Info → Description (Angle/Audience prompts)' },
        { key: '{{creativeBrief}}', description: 'Free-form user directions — from Creative Brief textarea. Gets its own section in the prompt for emphasis (all prompts)' },
      ],
    },
    {
      group: 'Style & Tone',
      icon: Palette,
      items: [
        { key: '{{toneInstruction}}', description: 'Resolved tone rules as full instruction text — priority: template tonality > dropdown > auto (Ads/Organic prompts)' },
        { key: '{{tone}}', description: 'Raw tone key (e.g. "professional", "casual") — from Tone dropdown (Angle/Audience prompts)' },
        { key: '{{emojiInstruction}}', description: 'Emoji usage rules — from Advanced Options → Emoji Level (Ads/Organic prompts)' },
        { key: '{{ctaInstruction}}', description: 'CTA style rules — from Advanced Options → CTA Style. Only injected for Ads prompts' },
        { key: '{{language}}', description: 'Target language (e.g. "Swedish", "English") — from Settings → Language' },
      ],
    },
    {
      group: 'Reference Material',
      icon: Paperclip,
      items: [
        { key: '{{referenceCopy}}', description: 'Reference ads with style analysis context — from Reference Ads section (Ads/Organic prompts)' },
        { key: '{{referenceAds}}', description: 'Reference ads raw text — same source as {{referenceCopy}} but for Angle/Audience prompts' },
        { key: '{{reviewsContext}}', description: 'Customer reviews — from Customer Reviews section (Ads/Organic prompts)' },
        { key: '{{researchContext}}', description: 'Market research data from the research step — auto-injected when research is enabled (Audience prompts)' },
      ],
    },
    {
      group: 'Campaign',
      icon: Target,
      items: [
        { key: '{{campaignContext}}', description: 'Season/event + campaign theme — from Theme section (all prompts)' },
        { key: '{{organicContext}}', description: 'Content pillar + post format — Organic-specific fields. Only injected for Organic prompts' },
      ],
    },
    {
      group: 'Runtime (auto-injected)',
      icon: Settings2,
      items: [
        { key: '{{angle}}', description: 'Current angle name being generated — set automatically per generation task' },
        { key: '{{audience}}', description: 'Current audience name being generated — set automatically per generation task' },
        { key: '{{count}}', description: 'Number of items to generate — from the Auto mode count selector' },
        { key: '{{audienceList}}', description: 'JSON array of all audiences — auto-injected in Angle Generation (Audience-Aware) prompt only' },
      ],
    },
  ],
  video: [],
};

type PromptModuleId = "copy" | "ads" | "image" | "video";

/**
 * Variant editor — shows all variants as tabs with the active variant's textarea.
 * Handles create, duplicate, rename, delete, set-default, save, discard.
 */
function VariantEditor({
  module,
  section,
}: {
  module: PromptModuleId;
  section: string;
}) {
  const utils = trpc.useUtils();

  // Fetch all variants for this section
  const { data, isLoading, error } = trpc.promptOverrides.variants.useQuery({
    module,
    section,
  });

  // Mutations
  const updateMutation = trpc.promptOverrides.update.useMutation({
    onSuccess: () => {
      utils.promptOverrides.variants.invalidate({ module, section });
      toast.success("Prompt saved");
      setIsDirty(false);
    },
    onError: (err) => toast.error(`Failed to save: ${err.message}`),
  });

  const createMutation = trpc.promptOverrides.create.useMutation({
    onSuccess: (result) => {
      utils.promptOverrides.variants.invalidate({ module, section });
      toast.success("Prompt saved");
      setActiveVariantId(result.id);
      setIsDirty(false);
    },
    onError: (err) => toast.error(`Failed to save: ${err.message}`),
  });

  const duplicateMutation = trpc.promptOverrides.duplicate.useMutation({
    onSuccess: (result) => {
      utils.promptOverrides.variants.invalidate({ module, section });
      toast.success("Variant duplicated");
      setActiveVariantId(result.id);
    },
    onError: (err) => toast.error(`Failed to duplicate: ${err.message}`),
  });

  const deleteMutation = trpc.promptOverrides.delete.useMutation({
    onSuccess: () => {
      utils.promptOverrides.variants.invalidate({ module, section });
      toast.success("Variant deleted");
    },
    onError: (err) => toast.error(`Failed to delete: ${err.message}`),
  });

  const setDefaultMutation = trpc.promptOverrides.setDefault.useMutation({
    onSuccess: () => {
      utils.promptOverrides.variants.invalidate({ module, section });
      toast.success("Default variant updated");
    },
    onError: (err) => toast.error(`Failed to set default: ${err.message}`),
  });

  // Local state
  const [activeVariantId, setActiveVariantId] = useState<number | null>(null);
  const [content, setContent] = useState("");
  const [isDirty, setIsDirty] = useState(false);
  const [showPlaceholders, setShowPlaceholders] = useState(false);
  const [renameDialogOpen, setRenameDialogOpen] = useState(false);
  const [renameValue, setRenameValue] = useState("");
  const [renameTargetId, setRenameTargetId] = useState<number | null>(null);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleteTargetId, setDeleteTargetId] = useState<number | null>(null);

  const variants = data?.variants ?? [];

  // Set initial active variant
  useEffect(() => {
    if (variants.length > 0 && activeVariantId === null) {
      // Default to the active (isDefault) variant
      const defaultVariant = variants.find((v) => v.isDefault);
      setActiveVariantId(defaultVariant?.id ?? variants[0].id);
    }
  }, [variants, activeVariantId]);

  // Sync content when active variant changes
  const activeVariant = variants.find((v) => v.id === activeVariantId);
  useEffect(() => {
    if (activeVariant && !isDirty) {
      setContent(activeVariant.content);
    }
  }, [activeVariant?.id, activeVariant?.content, isDirty]);

  // Reset dirty state when switching variants
  const handleVariantSwitch = useCallback((variantId: number) => {
    setActiveVariantId(variantId);
    setIsDirty(false);
  }, []);

  // After delete, switch to another variant
  useEffect(() => {
    if (variants.length > 0 && activeVariantId !== null) {
      const stillExists = variants.find((v) => v.id === activeVariantId);
      if (!stillExists) {
        const defaultVariant = variants.find((v) => v.isDefault);
        setActiveVariantId(defaultVariant?.id ?? variants[0].id);
        setIsDirty(false);
      }
    }
  }, [variants, activeVariantId]);

  const handleSave = useCallback(() => {
    if (activeVariantId === null || !content.trim()) {
      toast.error("Prompt content cannot be empty");
      return;
    }

    if (activeVariantId === 0) {
      // Virtual built-in variant — create a new DB row (save-on-create pattern)
      // This converts the read-only default into a user-owned override
      const variantName = activeVariant?.name?.replace(' (Built-in)', '') ?? `${section} Custom`;
      createMutation.mutate({
        module,
        section,
        name: variantName,
        content: content.trim(),
      });
    } else {
      // Real DB variant — update in place
      updateMutation.mutate({ variantId: activeVariantId, content: content.trim() });
    }
  }, [activeVariantId, content, module, section, activeVariant, updateMutation, createMutation]);

  const handleDiscard = useCallback(() => {
    if (activeVariant) {
      setContent(activeVariant.content);
    }
    setIsDirty(false);
  }, [activeVariant]);

  const handleCreate = useCallback(() => {
    // New variants start with the active variant's content (duplicate behavior)
    // or a minimal placeholder if no active variant exists
    const initialContent = activeVariant?.content ?? "Enter your prompt here. Use {{placeholders}} for dynamic values.";
    createMutation.mutate({
      module,
      section,
      name: `Variant ${variants.length + 1}`,
      content: initialContent,
    });
  }, [module, section, variants.length, activeVariant?.content, createMutation]);

  const handleDuplicate = useCallback(
    (variantId: number) => {
      duplicateMutation.mutate({ variantId });
    },
    [duplicateMutation],
  );

  const handleDelete = useCallback(
    (variantId: number) => {
      setDeleteTargetId(variantId);
      setDeleteConfirmOpen(true);
    },
    [],
  );

  const confirmDelete = useCallback(() => {
    if (deleteTargetId !== null) {
      deleteMutation.mutate({ variantId: deleteTargetId });
    }
    setDeleteConfirmOpen(false);
    setDeleteTargetId(null);
  }, [deleteTargetId, deleteMutation]);

  const handleSetDefault = useCallback(
    (variantId: number) => {
      setDefaultMutation.mutate({ variantId });
    },
    [setDefaultMutation],
  );

  const openRenameDialog = useCallback(
    (variantId: number, currentName: string) => {
      setRenameTargetId(variantId);
      setRenameValue(currentName);
      setRenameDialogOpen(true);
    },
    [],
  );

  const confirmRename = useCallback(() => {
    if (renameTargetId !== null && renameValue.trim()) {
      updateMutation.mutate({ variantId: renameTargetId, name: renameValue.trim() });
    }
    setRenameDialogOpen(false);
    setRenameTargetId(null);
    setRenameValue("");
  }, [renameTargetId, renameValue, updateMutation]);

  const isSaving = updateMutation.isPending;
  const modulePlaceholders = PLACEHOLDERS[module] ?? [];

  // Error state
  if (error) {
    return (
      <div className="rounded-lg border border-destructive/50 bg-destructive/5 p-4">
        <div className="flex items-center gap-2">
          <AlertCircle className="w-4 h-4 text-destructive" />
          <p className="text-sm text-destructive font-medium">
            Failed to load prompt: {error.message}
          </p>
        </div>
      </div>
    );
  }

  // Loading state
  if (isLoading) {
    return (
      <div className="flex items-center gap-2 py-8 justify-center text-muted-foreground">
        <Loader2 className="w-4 h-4 animate-spin" />
        <span className="text-sm">Loading prompt variants...</span>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Variant tabs */}
      <div className="flex items-center gap-2 flex-wrap">
        <div className="flex items-center gap-1 flex-wrap">
          {variants.map((v) => (
            <div key={v.id} className="flex items-center group">
              <button
                type="button"
                onClick={() => handleVariantSwitch(v.id)}
                className={`
                  inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-colors
                  ${v.id === activeVariantId
                    ? "bg-primary text-primary-foreground shadow-sm"
                    : "bg-muted/60 text-muted-foreground hover:bg-muted hover:text-foreground"
                  }
                `}
              >
                {v.isDefault && <Star className="w-3 h-3 fill-current" />}
                <span className="max-w-[120px] truncate">{v.name}</span>
              </button>

              {/* Per-variant dropdown menu */}
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <button
                    type="button"
                    className={`
                      p-1 rounded-md transition-opacity ml-0.5
                      ${v.id === activeVariantId
                        ? "opacity-70 hover:opacity-100 text-primary-foreground"
                        : "opacity-0 group-hover:opacity-70 hover:!opacity-100 text-muted-foreground"
                      }
                    `}
                  >
                    <MoreHorizontal className="w-3.5 h-3.5" />
                  </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-44">
                  <DropdownMenuItem onClick={() => openRenameDialog(v.id, v.name)}>
                    <PencilLine className="w-3.5 h-3.5 mr-2" />
                    Rename
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => handleDuplicate(v.id)}>
                    <Copy className="w-3.5 h-3.5 mr-2" />
                    Duplicate
                  </DropdownMenuItem>
                  {!v.isDefault && (
                    <DropdownMenuItem onClick={() => handleSetDefault(v.id)}>
                      <Star className="w-3.5 h-3.5 mr-2" />
                      Set as Default
                    </DropdownMenuItem>
                  )}
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={() => handleDelete(v.id)}
                    disabled={variants.length <= 1}
                    className="text-destructive focus:text-destructive"
                  >
                    <Trash2 className="w-3.5 h-3.5 mr-2" />
                    Delete
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          ))}
        </div>

        {/* + New button */}
        <Button
          variant="ghost"
          size="sm"
          onClick={handleCreate}
          className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground gap-1"
          disabled={createMutation.isPending}
        >
          <Plus className="w-3.5 h-3.5" />
          New
        </Button>
      </div>

      {/* Active variant editor */}
      {activeVariant && (
        <>
          {/* Status bar */}
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              {activeVariant.isDefault ? (
                <span className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                  <Star className="w-3 h-3 fill-current" />
                  Active
                </span>
              ) : (
                <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                  Inactive
                </span>
              )}
              {isDirty && (
                <span className="text-xs text-amber-600 font-medium">
                  Unsaved changes
                </span>
              )}
            </div>

            <div className="flex items-center gap-2">
              {isDirty && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={handleDiscard}
                  disabled={isSaving}
                >
                  Discard
                </Button>
              )}

              <Button
                size="sm"
                onClick={handleSave}
                disabled={!isDirty || isSaving}
                className="gap-1.5"
              >
                {isSaving ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <Save className="w-3.5 h-3.5" />
                )}
                Save
              </Button>

              {/* Revert to last saved version */}
              {isDirty && activeVariant && (
                <Button
                  variant="outline"
                  size="sm"
                  className="gap-1.5"
                  onClick={() => {
                    setContent(activeVariant.content);
                    setIsDirty(false);
                  }}
                >
                  <RotateCcw className="w-3.5 h-3.5" />
                  Revert Changes
                </Button>
              )}
            </div>
          </div>

          {/* Textarea */}
          <Textarea
            value={content}
            onChange={(e) => {
              setContent(e.target.value);
              setIsDirty(true);
            }}
            className="min-h-[400px] font-mono text-xs leading-relaxed resize-y"
            placeholder="Enter your system prompt..."
            disabled={isSaving}
          />
        </>
      )}

      {/* Placeholder reference */}
      {modulePlaceholders.length > 0 && (
        <div className="rounded-lg border bg-muted/30 p-3">
          <button
            type="button"
            onClick={() => setShowPlaceholders(!showPlaceholders)}
            className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors w-full text-left"
          >
            <Info className="w-3.5 h-3.5" />
            Available placeholders — injected automatically at runtime
            <span className="ml-auto text-[10px]">
              {showPlaceholders ? "Hide" : "Show"}
            </span>
          </button>

          {showPlaceholders && (
            <div className="mt-3 space-y-3">
              {modulePlaceholders.map((g) => (
                <div key={g.group}>
                  <h4 className="flex items-center gap-1.5 text-[11px] font-semibold text-muted-foreground mb-1">
                    <g.icon className="w-3 h-3" />
                    {g.group}
                  </h4>
                  <div className="space-y-1 pl-1">
                    {g.items.map((p) => (
                      <div key={p.key} className="flex items-start gap-2 text-xs">
                        <code className="shrink-0 rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground">
                          {p.key}
                        </code>
                        <span className="text-muted-foreground">{p.description}</span>
                      </div>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Rename dialog */}
      <Dialog open={renameDialogOpen} onOpenChange={setRenameDialogOpen}>
        <DialogContent className="sm:max-w-[400px]">
          <DialogHeader>
            <DialogTitle>Rename Variant</DialogTitle>
          </DialogHeader>
          <Input
            value={renameValue}
            onChange={(e) => setRenameValue(e.target.value)}
            placeholder="Variant name..."
            onKeyDown={(e) => {
              if (e.key === "Enter") confirmRename();
            }}
            autoFocus
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setRenameDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={confirmRename} disabled={!renameValue.trim()}>
              Rename
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete confirmation */}
      <AlertDialog open={deleteConfirmOpen} onOpenChange={setDeleteConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete variant?</AlertDialogTitle>
            <AlertDialogDescription>
              This will permanently delete this prompt variant. This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={confirmDelete}>Delete</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

/**
 * Module content — renders either:
 * - Empty state (0 sections)
 * - Variant editor directly (1 section, no subtabs)
 * - Subtabs with a variant editor per section (2+ sections)
 */
function ModuleContent({
  module,
  sections,
}: {
  module: PromptModuleId;
  sections: Array<{ section: string; label: string; description: string }>;
}) {
  // 0 sections → empty state
  if (sections.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-12 text-muted-foreground">
        <p className="text-sm">No prompts configured for this module yet.</p>
        <p className="text-xs mt-1">Prompts will appear here when added.</p>
      </div>
    );
  }

  // 1 section → show variant editor directly (no subtabs)
  if (sections.length === 1) {
    return <VariantEditor module={module} section={sections[0].section} />;
  }

  // 2+ sections → subtabs
  return (
    <Tabs defaultValue={sections[0].section} className="w-full">
      <TabsList className="mb-4">
        {sections.map((s) => (
          <TabsTrigger key={s.section} value={s.section} className="text-xs">
            {s.label}
          </TabsTrigger>
        ))}
      </TabsList>
      {sections.map((s) => (
        <TabsContent key={s.section} value={s.section}>
          {s.description && (
            <p className="text-xs text-muted-foreground mb-3">{s.description}</p>
          )}
          <VariantEditor module={module} section={s.section} />
        </TabsContent>
      ))}
    </Tabs>
  );
}

/**
 * Main Prompt Editor component.
 * Module tabs at the top, content area below.
 */
export function PromptEditorSection() {
  const utils = trpc.useUtils();

  // Fetch section metadata for all modules
  const copyList = trpc.promptOverrides.list.useQuery({ module: "copy" });
  const adsList = trpc.promptOverrides.list.useQuery({ module: "ads" });
  const imageList = trpc.promptOverrides.list.useQuery({ module: "image" });
  const videoList = trpc.promptOverrides.list.useQuery({ module: "video" });

  /** Helper to map section response to UI-friendly shape */
  const mapSections = (data: typeof copyList.data) =>
    data?.sections.map((s) => ({
      section: s.section,
      label: s.label,
      description: s.description,
    })) ?? [];

  const sectionsByModule: Record<PromptModuleId, Array<{ section: string; label: string; description: string }>> = {
    copy: mapSections(copyList.data),
    ads: mapSections(adsList.data),
    image: mapSections(imageList.data),
    video: mapSections(videoList.data),
  };

  /** Export state to prevent double-click */
  const [isExporting, setIsExporting] = useState(false);

  /** Export all prompts across all modules as CSV. */
  const handleExportCsv = async () => {
    if (isExporting) return;
    setIsExporting(true);

    try {
      const headers = ["Module", "Section", "Variant Name", "Content", "Default", "Created"];
      const rows: (string | number | boolean | null | undefined)[][] = [];

      // Iterate all modules and their sections, fetching variants via REST API
      for (const mod of MODULES) {
        const sections = sectionsByModule[mod.id] ?? [];
        for (const sec of sections) {
          try {
            const res = await apiFetch<{ variants: Array<{ name: string; content: string; isDefault: boolean; createdAt: string }> }>(
              `prompts/${mod.id}/${sec.section}/variants`
            );
            const variants = res?.variants ?? [];
            for (const v of variants) {
              rows.push([
                mod.label,
                sec.label,
                v.name,
                v.content,
                v.isDefault ? "Yes" : "No",
                v.createdAt ? new Date(v.createdAt).toISOString().slice(0, 10) : "",
              ]);
            }
          } catch {
            // Skip sections that fail to load
          }
        }
      }

      if (rows.length === 0) {
        toast.error("No prompts to export");
        return;
      }

      const date = new Date().toISOString().slice(0, 10);
      exportToCsv(`prompts-export-${date}.csv`, headers, rows);
      toast.success(`Exported ${rows.length} prompt variant${rows.length !== 1 ? "s" : ""}`);
    } catch {
      toast.error("Failed to export prompts");
    } finally {
      setIsExporting(false);
    }
  };

  /** Hidden file input ref for CSV import. */
  const importFileRef = useRef<HTMLInputElement>(null);
  const [isImporting, setIsImporting] = useState(false);

  /** Import prompt variants from a CSV file. */
  const handleImportCsv = async (file: File) => {
    if (isImporting) return;
    setIsImporting(true);

    try {
      const rows = await parseCsvFile(file);
      if (rows.length < 2) {
        toast.error("CSV file is empty or has no data rows");
        return;
      }

      // Validate headers
      const expectedHeaders = ["Module", "Section", "Variant Name", "Content"];
      const { valid, missing } = validateCsvHeaders(rows[0], expectedHeaders);
      if (!valid) {
        toast.error(`Missing CSV columns: ${missing.join(", ")}`);
        return;
      }

      // Build header index map (case-insensitive)
      const headerMap = new Map(rows[0].map((h, i) => [h.trim().toLowerCase(), i]));
      const dataRows = rows.slice(1).filter((r) => r.some((cell) => cell.trim()));

      // Build a label→ID lookup for modules and sections
      const moduleLabelToId = new Map(MODULES.map((m) => [m.label.toLowerCase(), m.id]));
      const sectionLabelToKey = new Map<string, { module: PromptModuleId; section: string }>();
      for (const mod of MODULES) {
        for (const sec of sectionsByModule[mod.id] ?? []) {
          sectionLabelToKey.set(`${mod.label.toLowerCase()}|${sec.label.toLowerCase()}`, {
            module: mod.id,
            section: sec.section,
          });
        }
      }

      let successCount = 0;
      let errorCount = 0;

      for (const row of dataRows) {
        const moduleLabel = row[headerMap.get("module") ?? 0]?.trim();
        const sectionLabel = row[headerMap.get("section") ?? 1]?.trim();
        const variantName = row[headerMap.get("variant name") ?? 2]?.trim();
        const content = row[headerMap.get("content") ?? 3] ?? "";

        if (!moduleLabel || !sectionLabel || !variantName) {
          errorCount++;
          continue;
        }

        // Resolve module + section from labels
        const moduleId = moduleLabelToId.get(moduleLabel.toLowerCase());
        const sectionInfo = sectionLabelToKey.get(
          `${moduleLabel.toLowerCase()}|${sectionLabel.toLowerCase()}`
        );

        if (!moduleId || !sectionInfo) {
          errorCount++;
          continue;
        }

        try {
          await apiFetch("prompts", {
            method: "POST",
            body: JSON.stringify({
              module: sectionInfo.module,
              section: sectionInfo.section,
              name: variantName,
              content,
            }),
          });
          successCount++;
        } catch {
          errorCount++;
        }
      }

      // Invalidate all prompt queries so the editor refreshes
      for (const mod of MODULES) {
        for (const sec of sectionsByModule[mod.id] ?? []) {
          utils.promptOverrides.variants.invalidate({ module: mod.id, section: sec.section });
        }
      }

      if (errorCount === 0) {
        toast.success(`Imported ${successCount} prompt variant${successCount !== 1 ? "s" : ""}`);
      } else {
        toast.warning(`Imported ${successCount}, failed ${errorCount}`);
      }
    } catch {
      toast.error("Failed to parse CSV file");
    } finally {
      setIsImporting(false);
      if (importFileRef.current) importFileRef.current.value = "";
    }
  };

  /** Sync state to prevent double-trigger. */
  const [isSyncing, setIsSyncing] = useState(false);

  /**
   * Inject any newly-shipped placeholders (e.g. {{creativeBrief}}) into the
   * user's saved prompt overrides. Idempotent + customization-safe on the
   * backend — see PCM_Prompt_Placeholders::sync_all().
   */
  const handleSyncPlaceholders = async () => {
    if (isSyncing) return;
    setIsSyncing(true);

    try {
      const res = await apiFetch<{
        patched: number;
        skipped: number;
        failed: number;
        errors: string[];
        locked?: boolean;
      }>("prompts/sync-placeholders", { method: "POST" });

      if (res.locked) {
        toast.warning("Sync already in progress — try again in a moment.");
        return;
      }

      const hadIssues = res.failed > 0 || (res.errors?.length ?? 0) > 0;
      if (hadIssues) {
        toast.warning(
          `Synced ${res.patched} prompt${res.patched !== 1 ? "s" : ""}` +
            (res.failed > 0 ? `, ${res.failed} failed` : "") +
            (res.errors?.[0] ? ` — ${res.errors[0]}` : ""),
        );
      } else if (res.patched === 0) {
        toast.success("All prompts are already up to date");
      } else {
        toast.success(
          `Synced ${res.patched} prompt${res.patched !== 1 ? "s" : ""} to the latest schema`,
        );
      }

      // Refresh the editor so any injected placeholders show immediately.
      for (const mod of MODULES) {
        for (const sec of sectionsByModule[mod.id] ?? []) {
          utils.promptOverrides.variants.invalidate({ module: mod.id, section: sec.section });
        }
      }
    } catch {
      toast.error("Failed to sync prompts");
    } finally {
      setIsSyncing(false);
    }
  };

  return (
    <Tabs defaultValue="copy" className="w-full">
      {/* Header with export button */}
      <div className="flex items-center justify-between mb-2">
        <TabsList>
          {MODULES.map((mod) => {
            const Icon = mod.icon;
            const count = sectionsByModule[mod.id]?.length ?? 0;
            return (
              <TabsTrigger
                key={mod.id}
                value={mod.id}
                className="gap-1.5 text-xs"
                disabled={count === 0}
              >
                <Icon className="w-3.5 h-3.5" />
                {mod.label}
              </TabsTrigger>
            );
          })}
        </TabsList>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={handleSyncPlaceholders}
            disabled={isSyncing}
            title="Inject newly-added placeholders (e.g. {{creativeBrief}}) into your saved prompts — safe to run anytime"
            className="gap-1.5"
          >
            {isSyncing ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <RefreshCw className="w-4 h-4" />
            )}
            Sync to latest
          </Button>
          <Button variant="outline" size="icon" onClick={handleExportCsv} disabled={isExporting} title="Export all prompts as CSV">
            {isExporting ? <Loader2 className="w-4 h-4 animate-spin" /> : <Download className="w-4 h-4" />}
          </Button>
          <Button
            variant="outline"
            size="icon"
            onClick={() => importFileRef.current?.click()}
            disabled={isImporting}
            title="Import prompts from CSV"
          >
            {isImporting ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
          </Button>
          <input
            ref={importFileRef}
            type="file"
            accept=".csv"
            className="hidden"
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file) handleImportCsv(file);
            }}
          />
        </div>
      </div>

      {MODULES.map((mod) => (
        <TabsContent key={mod.id} value={mod.id} className="mt-4">
          <ModuleContent
            module={mod.id}
            sections={sectionsByModule[mod.id] ?? []}
          />
        </TabsContent>
      ))}
    </Tabs>
  );
}
