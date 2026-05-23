import { useState, useCallback, useEffect } from 'react';
import { Share2, Copy, Check, Loader2 } from 'lucide-react';
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
import { colors, typography, shadows } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';
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
}: CreateApprovalSetDialogProps) {
  const [setName, setSetName] = useState('');
  const [shareableLink, setShareableLink] = useState('');
  const [copied, setCopied] = useState(false);

  // Set default name with campaign info & date
  useEffect(() => {
    if (isOpen) {
      const dateStr = new Date().toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
      });
      setSetName(`Ad Campaign Set — ${brandName || 'Draft'} (${dateStr})`);
      setShareableLink('');
      setCopied(false);
    }
  }, [isOpen, brandName]);

  // tRPC Mutation to create set
  const createMutation = trpc.approvals.createSet.useMutation({
    onSuccess: (data: any) => {
      // Build the absolute client review URL
      const host = window.location.origin;
      const publicLink = `${host}/public/approval/${data.token}`;
      setShareableLink(publicLink);
      toast.success('Client sharing board created successfully!');
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to generate approval set.');
    },
  });

  const handleGenerateLink = useCallback(() => {
    if (!setName.trim()) {
      toast.error('Please enter an approval set name.');
      return;
    }

    // Freeze snapshot data
    const selectedMedia = mediaSlots
      .filter((m) => selectedVisualIds.includes(m.id))
      .map((m) => ({
        id: m.id,
        type: m.type,
        url: m.url || '',
        prompt: m.prompt,
        provider: m.provider,
        modelId: m.modelId,
      }));

    const selectedCopy = textSlots
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

    createMutation.mutate({
      name: setName.trim(),
      brandId: brandId || null,
      projectId: projectId || null,
      snapshot: {
        media: selectedMedia,
        copy: selectedCopy,
        brandName: brandName || 'PowerCreatives',
        brandLogoUrl: brandLogoUrl || null,
      },
    });
  }, [setName, mediaSlots, selectedVisualIds, textSlots, selectedCopyIds, brandId, projectId, brandName, brandLogoUrl, createMutation]);

  const handleCopyLink = useCallback(() => {
    if (!shareableLink) return;
    navigator.clipboard.writeText(shareableLink);
    setCopied(true);
    toast.success('Link copied to clipboard!');
    setTimeout(() => setCopied(false), 2000);
  }, [shareableLink]);

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
            </div>
          ) : (
            <div className="space-y-3 rounded-lg p-4" style={{ background: colors.bgPage, border: `1px solid ${colors.border}` }}>
              <span style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}>
                Generated Shareable Client Board Link
              </span>
              <div className="flex items-center gap-2">
                <Input
                  value={shareableLink}
                  readOnly
                  className="font-mono text-xs select-all shrink"
                  style={{ background: colors.bgSurface }}
                />
                <Button size="icon" onClick={handleCopyLink} className="shrink-0" style={{ background: colors.primary }}>
                  {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                </Button>
              </div>
              <p style={{ fontSize: typography.xs, color: colors.textMuted, marginTop: '8px' }}>
                Your client can open this link in any browser, see dynamic platform mockups, granularly comment, and click "Submit" to trigger your webhooks!
              </p>
            </div>
          )}
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          {!shareableLink ? (
            <>
              <Button variant="ghost" onClick={onClose} disabled={createMutation.isLoading}>
                Cancel
              </Button>
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
