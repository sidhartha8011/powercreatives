import { useState, useCallback, useEffect } from 'react';
import { Share2, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { colors, typography, shadows } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';
import { escapeAstralDeep } from '@/lib/escapeAstral';
import { ApprovalSharePanel, buildDefaultInviteMessage } from '@/components/shared/ApprovalSharePanel';
import { buildPublicBoardUrl, useApprovalSetsCache } from '@/components/shared/approvalSets';
import { ApprovalSetPicker, type AppendableSet } from '@/components/shared/ApprovalSetPicker';
import {
  ProjectPicker,
  resolveProjectId,
  EMPTY_PROJECT_PICK,
  useProjectPickerData,
  type ProjectPickerValue,
} from '@/components/shared/ProjectPicker';
import type { MediaSlot, TextSlot } from '../types';

interface CreateApprovalSetDialogProps {
  isOpen: boolean;
  onClose: () => void;
  selectedVisualIds: string[];
  selectedCopyIds: string[];
  mediaSlots: MediaSlot[];
  textSlots: TextSlot[];
  brandId?: number | null;
  projectId?: number | null;
  brandName?: string | null;
  brandLogoUrl?: string | null;
  /** Pre-fills the "send invite" email from the brand's saved client email. */
  brandClientEmail?: string | null;
}

export function CreateApprovalSetDialog({
  isOpen,
  onClose,
  selectedVisualIds,
  selectedCopyIds,
  mediaSlots,
  textSlots,
  brandId,
  projectId,
  brandName,
  brandLogoUrl,
  brandClientEmail,
}: CreateApprovalSetDialogProps) {
  const [setName, setSetName] = useState('');
  const [shareableLink, setShareableLink] = useState('');
  const [setId, setSetId] = useState<number | null>(null);
  // Seed values for the share panel; it owns copy/send state itself.
  const [clientEmail, setClientEmail] = useState('');
  const [clientMessage, setClientMessage] = useState('');
  // The set's ONE mapping: a project (existing, or created on submit).
  const [project, setProject] = useState<ProjectPickerValue>(EMPTY_PROJECT_PICK);
  // 'create' = new set (existing flow); 'append' = add to an open set.
  const [mode, setMode] = useState<'create' | 'append'>('create');
  const [targetSet, setTargetSet] = useState<AppendableSet | null>(null);

  // Projects the set can belong to; deliveries feed the project→delivery link.
  const { projects: projectOptions, deliveries, brands } = useProjectPickerData();

  const createProjectMutation = trpc.assets.createProject.useMutation();
  const setProjectDeliveryMutation = trpc.assets.setProjectDelivery.useMutation();
  const approvalSetsCache = useApprovalSetsCache();

  // Reset to defaults ONLY when the dialog opens — depending on the brand props
  // here would re-run the effect if async brand context resolves while open,
  // wiping the user's in-progress name/email/message edits.
  useEffect(() => {
    if (isOpen) {
      const dateStr = new Date().toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
      });
      setSetName(`Ad Campaign Set — ${brandName || 'Draft'} (${dateStr})`);
      setShareableLink('');
      setSetId(null);
      setClientEmail(brandClientEmail || '');
      setClientMessage(buildDefaultInviteMessage(brandName));
      setMode('create');
      setTargetSet(null);
      setProject({
        ...EMPTY_PROJECT_PICK,
        projectId: projectId != null ? Number(projectId) : null,
        brandId: brandId != null ? Number(brandId) : null,
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  // Moves the set into the "Awaiting Client Approval" lane (status 'client').
  const moveStatusMutation = trpc.approvals.updateSetStatus.useMutation({
    onError: (err: any) => {
      toast.error(err.message || 'Set created, but moving it to the client lane failed — drag it on the Approvals board.');
    },
  });

  // tRPC Mutation to create set
  const createMutation = trpc.approvals.createSet.useMutation({
    onSuccess: (data: any) => {
      setShareableLink(buildPublicBoardUrl(data.token));
      setSetId(Number(data.id));

      // Put the new card on the board NOW (the response is the full row), then
      // reconcile — without this the board shows nothing until a page reload.
      approvalSetsCache.registerCreated(data);

      // Sharing the link == the set is now out for client review. Move it to the
      // "Awaiting Client Approval" lane immediately.
      moveStatusMutation.mutate({ id: Number(data.id), status: 'client' });
      toast.success('Client sharing board created successfully!');
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to generate approval set.');
    },
  });

  // Freeze the selected slots into snapshot buckets (shared by both the
  // create and append paths).
  const buildBuckets = useCallback(() => {
    const media = mediaSlots
      .filter((m) => selectedVisualIds.includes(m.id))
      .map((m) => ({
        id: m.id,
        type: m.type,
        url: m.url || '',
        prompt: m.prompt,
        provider: m.provider,
        modelId: m.modelId,
      }));

    const copy = textSlots
      .filter((t) => selectedCopyIds.includes(t.id))
      .map((t) => ({
        id: t.id,
        headline: t.headline,
        body: t.body,
        cta: t.cta || '',
        description: t.description || '',
        hashtags: t.hashtags || [],
        audienceName: t.audienceName || '',
        angleName: t.angleName || '',
        modelUsed: t.modelUsed,
      }));

    return { media, copy };
  }, [mediaSlots, selectedVisualIds, textSlots, selectedCopyIds]);

  const appendMutation = trpc.approvals.appendToSet.useMutation({
    onSuccess: () => {
      toast.success(`Added ${selectedCopyIds.length + selectedVisualIds.length} item(s) to “${targetSet?.name ?? 'the set'}”.`);
      onClose();
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to add items to the approval set.');
    },
  });

  const handleAppend = useCallback(() => {
    if (!targetSet) {
      toast.error('Pick the approval set to add to.');
      return;
    }
    const { media, copy } = buildBuckets();
    if (media.length === 0 && copy.length === 0) {
      toast.error('Select at least one item to send for approval.');
      return;
    }
    // escapeAstralDeep: ad copy is emoji-heavy; a WAF that strips non-ASCII bytes
    // must not eat them in transit (card 18). The server decodes on arrival.
    appendMutation.mutate({ id: targetSet.id, snapshot: escapeAstralDeep({ media, copy }) });
  }, [targetSet, buildBuckets, appendMutation]);

  const handleGenerateLink = useCallback(async () => {
    if (!setName.trim()) {
      toast.error('Please enter an approval set name.');
      return;
    }

    const { media: selectedMedia, copy: selectedCopy } = buildBuckets();

    // Create the project first when the user typed a new name, so the set is
    // saved with a real project id — or not saved at all if that fails.
    let effProjectId: number | null;
    try {
      effProjectId = await resolveProjectId(
        project,
        createProjectMutation.mutateAsync,
        setProjectDeliveryMutation.mutateAsync,
        projectOptions
      );
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to create the project.');
      return;
    }

    createMutation.mutate({
      name: setName.trim(),
      brandId: brandId || null,
      // The set's ONE mapping. Delivery + brand are derived live from this
      // project server-side (PCM_Hierarchy) — never stored on the set.
      projectId: effProjectId,
      snapshot: escapeAstralDeep({
        media: selectedMedia,
        copy: selectedCopy,
        brandName: brandName || 'PowerCreatives',
        brandLogoUrl: brandLogoUrl || null,
      }),
    });
  }, [setName, buildBuckets, brandId, project, brandName, brandLogoUrl, createMutation, createProjectMutation]);


  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-md" showCloseButton={!createMutation.isLoading}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Share2 className="w-5 h-5" style={{ color: colors.primary }} />
            Share Approval Set with Client
          </DialogTitle>
          <DialogDescription>
            Package your selected {selectedCopyIds.length} ad copies and {selectedVisualIds.length} media assets into a secure shareable client mockup board.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-4">
          {!shareableLink ? (
            <div className="space-y-2">
              {/* Create a new set, or append to one still in review. */}
              <label
                style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}
              >
                Destination
              </label>
              <Select
                value={mode}
                onValueChange={(v) => setMode(v as 'create' | 'append')}
                disabled={createMutation.isLoading || appendMutation.isLoading}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="create">Create a new approval set</SelectItem>
                  <SelectItem value="append">Add to an existing set</SelectItem>
                </SelectContent>
              </Select>

              {mode === 'append' ? (
                <>
                  <label
                    style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}
                  >
                    Approval set (still in review)
                  </label>
                  <ApprovalSetPicker
                    value={targetSet?.id ?? null}
                    onChange={setTargetSet}
                    disabled={appendMutation.isLoading}
                  />
                  <p style={{ fontSize: typography.xs, color: colors.textMuted }}>
                    Fully approved or launched sets can’t receive new items — create a new set for those.
                  </p>
                </>
              ) : (
                <>
                  <label
                    htmlFor="set-name"
                    style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}
                  >
                    Approval Set Name (Visible to Client)
                  </label>
                  <Input
                    id="set-name"
                    value={setName}
                    onChange={(e) => setSetName(e.target.value)}
                    placeholder="e.g., Summer Product Launch"
                    disabled={createMutation.isLoading}
                  />

                  {/* The set's ONE mapping. Search the list or type a new name to
                      create the project; delivery + brand follow from it. */}
                  <ProjectPicker
                    projects={projectOptions}
                    deliveries={deliveries}
                    value={project}
                    onChange={setProject}
                    disabled={createMutation.isLoading || createProjectMutation.isLoading}
                  />
                </>
              )}
            </div>
          ) : (
            <ApprovalSharePanel
              setId={setId ?? 0}
              shareUrl={shareableLink}
              defaultEmail={clientEmail}
              defaultMessage={clientMessage}
            />
          )}
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          {!shareableLink ? (
            <>
              <Button variant="ghost" onClick={onClose} disabled={createMutation.isLoading || appendMutation.isLoading}>
                Cancel
              </Button>
              {mode === 'append' ? (
                <Button onClick={handleAppend} disabled={appendMutation.isLoading || !targetSet} className="gap-2" style={{ background: colors.primary }}>
                  {appendMutation.isLoading ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin" />
                      Adding…
                    </>
                  ) : (
                    <>
                      <Share2 className="w-4 h-4" />
                      Add to Set
                    </>
                  )}
                </Button>
              ) : (
                <Button onClick={handleGenerateLink} disabled={createMutation.isLoading} className="gap-2" style={{ background: colors.primary }}>
                  {createMutation.isLoading ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin" />
                      Generating link...
                    </>
                  ) : (
                    <>
                      <Share2 className="w-4 h-4" />
                      Generate Share Link
                    </>
                  )}
                </Button>
              )}
            </>
          ) : (
            <Button onClick={onClose} variant="secondary" className="w-full">
              Done & Close
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
