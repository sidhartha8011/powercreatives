/**
 * PARENT SETTINGS MODAL (Task G1)
 *
 * Edit an existing strategy's hierarchy + parent-link anchor configuration
 * after creation (CreateStrategyDialog only sets these at create time). Saves
 * in ONE mutation: hierarchyMode goes top-level (a whitelisted PATCH field),
 * while the parent-link keys ride in `config` (backend-merged onto the existing
 * config, so unrelated keys survive). Follows CreateStrategyDialog idioms.
 */

import React, { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import { trpc } from '@/lib/trpc';

interface ParentSettingsModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  strategyId: number;
  strategyName: string;
  hierarchyMode: string;
  /** Parsed strategy config (parentTargetUrl / parentKeyword / parentAnchorKeyword / allowAnchorVariations). */
  config: Record<string, any>;
  /** Called after a successful save (parent refetches). */
  onDone: () => void;
}

// Only 'children_only', 'parent_and_children', and 'parent_only' are offered
// in the select. Legacy strategies may still have 'standalone' (or another
// stale value) stored — in that case, default the select to
// 'parent_and_children' without writing anything back until the user
// actually hits Save.
function normalizeHierarchyMode(mode: string | undefined): string {
  return mode === 'children_only' || mode === 'parent_and_children' || mode === 'parent_only'
    ? mode
    : 'parent_and_children';
}

export function ParentSettingsModal({
  open,
  onOpenChange,
  strategyId,
  strategyName,
  hierarchyMode: initialHierarchyMode,
  config,
  onDone,
}: ParentSettingsModalProps) {
  const [hierarchyMode, setHierarchyMode] = useState(normalizeHierarchyMode(initialHierarchyMode));
  const [parentTargetUrl, setParentTargetUrl] = useState<string>(config.parentTargetUrl ?? '');
  const [parentKeyword, setParentKeyword] = useState<string>(config.parentKeyword ?? '');
  const [parentAnchorKeyword, setParentAnchorKeyword] = useState<string>(config.parentAnchorKeyword ?? '');
  // Default true (matches AutoPress) unless the strategy explicitly stored false.
  const [allowAnchorVariations, setAllowAnchorVariations] = useState<boolean>(
    config.allowAnchorVariations !== false,
  );

  // Re-seed from the strategy each time the modal opens (a different row may be
  // edited without unmounting) — mirrors CreateStrategyDialog's open-reset effect.
  React.useEffect(() => {
    if (!open) return;
    setHierarchyMode(normalizeHierarchyMode(initialHierarchyMode));
    setParentTargetUrl(config.parentTargetUrl ?? '');
    setParentKeyword(config.parentKeyword ?? '');
    setParentAnchorKeyword(config.parentAnchorKeyword ?? '');
    setAllowAnchorVariations(config.allowAnchorVariations !== false);
  }, [open, initialHierarchyMode, config]);

  // After the settings save, re-apply the parent link across the strategy's
  // ALREADY-GENERATED articles — without this, editing the parent after
  // generation only affected future items (the link is baked into content at
  // generation time). A re-apply failure is surfaced but doesn't undo the save.
  const reapplyMutation = trpc.strategy.reapplyParent.useMutation({
    onSuccess: (r: any) => {
      const updated = Number(r?.updated ?? 0);
      const cleared = Number(r?.cleared ?? 0);
      if (updated > 0 || cleared > 0) {
        toast.success(
          `Parent settings updated — ${updated} article${updated === 1 ? '' : 's'} relinked` +
          (cleared > 0 ? `, ${cleared} cleared` : ''),
        );
      } else {
        toast.success('Parent settings updated');
      }
      onDone();
      onOpenChange(false);
    },
    onError: (err: any) => {
      toast.warning(err.message ?? 'Settings saved, but existing articles could not be relinked.');
      onDone();
      onOpenChange(false);
    },
  }) as any;

  const updateMutation = trpc.strategy.update.useMutation({
    onSuccess: () => {
      reapplyMutation.mutate({ id: strategyId });
    },
    onError: (err: any) => toast.error(err.message ?? 'Failed to update parent settings'),
  }) as any;

  const handleSave = () => {
    // One call: hierarchyMode top-level (whitelisted PATCH field) + the parent-link
    // keys nested in config (backend-merged, so other config keys are untouched).
    updateMutation.mutate({
      id: strategyId,
      hierarchyMode,
      config: {
        parentTargetUrl,
        parentKeyword,
        parentAnchorKeyword,
        allowAnchorVariations,
      },
    });
  };

  const isSaving = !!updateMutation.isPending || !!reapplyMutation.isPending;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[480px] max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Parent Settings — {strategyName}</DialogTitle>
        </DialogHeader>

        <div className="py-2 space-y-6">
          <div className="space-y-2">
            <Label>Content Hierarchy</Label>
            <Select value={hierarchyMode} onValueChange={setHierarchyMode}>
              <SelectTrigger className="w-full bg-background">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="children_only">Children of an Existing Page</SelectItem>
                <SelectItem value="parent_and_children">Parent + Children</SelectItem>
                <SelectItem value="parent_only">Parent (this is the pillar)</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {hierarchyMode === 'children_only' && (
            <div className="space-y-2">
              <Label>Target Parent URL</Label>
              <p className="text-[0.8rem] text-muted-foreground">
                All generated content links to this existing parent page.
              </p>
              <Input
                type="url"
                value={parentTargetUrl}
                onChange={(e) => setParentTargetUrl(e.target.value)}
                placeholder="https://example.com/parent-page"
                className="w-full bg-background"
              />
            </div>
          )}

          {hierarchyMode === 'parent_and_children' && (
            <div className="space-y-2">
              <Label>Parent Keyword</Label>
              <p className="text-[0.8rem] text-muted-foreground">
                The item (by keyword) that generates first and becomes the hub; others link to it.
              </p>
              <Input
                value={parentKeyword}
                onChange={(e) => setParentKeyword(e.target.value)}
                placeholder="e.g. best running shoes"
                className="w-full bg-background"
              />
            </div>
          )}

          <div className="space-y-2">
            <Label>Anchor Keyword (optional)</Label>
            <p className="text-[0.8rem] text-muted-foreground">
              Exact anchor text for the injected parent link. Leave blank to use the parent's topic.
            </p>
            <Input
              value={parentAnchorKeyword}
              onChange={(e) => setParentAnchorKeyword(e.target.value)}
              placeholder="e.g. our complete guide"
              className="w-full bg-background"
            />
          </div>

          <div className="flex flex-col space-y-2">
            <div className="flex items-center space-x-2">
              <Checkbox
                id="allow-anchor-variations"
                checked={allowAnchorVariations}
                onCheckedChange={(c) => setAllowAnchorVariations(c as boolean)}
              />
              <Label htmlFor="allow-anchor-variations" className="cursor-pointer font-medium leading-none">
                Allow anchor variations
              </Label>
            </div>
            <p className="text-[0.8rem] text-muted-foreground pl-6">
              When off, the anchor keyword is used verbatim.
            </p>
          </div>
        </div>

        <DialogFooter className="pt-4 border-t">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isSaving}>
            Cancel
          </Button>
          <Button onClick={handleSave} disabled={isSaving}>
            {isSaving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
            Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
