/**
 * CreateProjectDialog — create a project on the SHARED EntityCard primitive
 * (the exact create-mode anatomy the delivery card uses: inline title, quiet
 * sections, Cancel/Create footer). Configure the shared component — never
 * copy it (PO 2026-07-08).
 *
 * Optional image step: pick/upload one or many images via the WordPress
 * media library (`wp.media` — the same frame the Approvals card editor
 * uses); on create they're registered as project image assets
 * (POST /assets/projects/{id}/images).
 *
 * `onCreated` lets the caller chain context: the delivery projects surface
 * uses it to connect the new project to its delivery immediately.
 */

import { useEffect, useState } from 'react';
import { ImagePlus, X } from 'lucide-react';
import { toast } from 'sonner';

import {
  CARD_TYPE,
  EntityCard,
  EntityCardSection,
  EntityCardTitle,
} from '@/components/shared/EntityCard';
import { Button } from '@/components/ui/button';
import { trpc } from '@/lib/trpc';

declare const wp: any;

export interface CreateProjectDialogProps {
  open: boolean;
  onClose: () => void;
  /** Called after the project (and its images) are created. */
  onCreated?: (projectId: number, name: string) => void | Promise<void>;
}

export function CreateProjectDialog({ open, onClose, onCreated }: CreateProjectDialogProps) {
  const [name, setName] = useState('');
  const [images, setImages] = useState<{ url: string; alt: string }[]>([]);
  const [submitting, setSubmitting] = useState(false);

  // Reset whenever the dialog opens.
  useEffect(() => {
    if (!open) return;
    setName('');
    setImages([]);
    setSubmitting(false);
  }, [open]);

  const createMutation = trpc.assets.createProject.useMutation() as any;
  const addImagesMutation = trpc.assets.addProjectImages.useMutation() as any;
  const projectsUtils = trpc.assets.getProjects.useUtils();

  /** WordPress media frame — upload or pick, multiple allowed. */
  const pickImages = () => {
    if (typeof wp === 'undefined' || !wp?.media) {
      toast.error('The WordPress media library is not available here');
      return;
    }
    const frame = wp.media({
      title: 'Select or upload images',
      button: { text: 'Use images' },
      multiple: true,
    });
    frame.on('select', () => {
      const items = frame.state().get('selection').toJSON();
      const picked = (Array.isArray(items) ? items : [])
        .filter((a: any) => a?.url)
        .map((a: any) => ({ url: String(a.url), alt: String(a.alt || '') }));
      if (picked.length) {
        setImages((prev) => {
          const known = new Set(prev.map((i) => i.url));
          return [...prev, ...picked.filter((i) => !known.has(i.url))];
        });
      }
    });
    frame.open();
  };

  const canSubmit = name.trim().length > 0 && !submitting;
  const handleCreate = async () => {
    if (!canSubmit) return;
    setSubmitting(true);
    try {
      const created = await createMutation.mutateAsync({ name: name.trim() });
      const projectId = Number(created?.id);
      if (!Number.isFinite(projectId) || projectId <= 0) {
        throw new Error('Project was not created');
      }
      if (images.length > 0) {
        await addImagesMutation.mutateAsync({ id: projectId, urls: images.map((i) => i.url) });
      }
      await onCreated?.(projectId, name.trim());
      projectsUtils.invalidate();
      toast.success(
        images.length > 0
          ? `“${name.trim()}” created with ${images.length} image${images.length === 1 ? '' : 's'}`
          : `“${name.trim()}” created`
      );
      onClose();
    } catch (e: any) {
      toast.error(e?.message || 'Failed to create the project');
      setSubmitting(false);
    }
  };

  return (
    <EntityCard
      open={open}
      onClose={onClose}
      ariaTitle="New project"
      ariaDescription="Create a project — optionally attach images from the media library."
    >
      <EntityCardTitle
        value={name}
        placeholder="Untitled project"
        autoFocus
        onSave={setName}
      />

      <EntityCardSection
        title="Images"
        action={
          images.length > 0 ? (
            <Button type="button" variant="ghost" size="sm" className={`h-7 gap-1 ${CARD_TYPE.LABEL}`} onClick={pickImages}>
              <ImagePlus className="h-3.5 w-3.5" /> Add images
            </Button>
          ) : undefined
        }
      >
        {images.length === 0 ? (
          // The empty state IS the action — same convention as the delivery card.
          <Button
            type="button"
            variant="ghost"
            onClick={pickImages}
            className={`h-auto w-full justify-center gap-1 rounded-md border border-dashed px-3 py-4 ${CARD_TYPE.LABEL}`}
          >
            <ImagePlus className="h-3.5 w-3.5" /> Add one or many images (optional)
          </Button>
        ) : (
          <div className="flex flex-wrap gap-2">
            {images.map((img) => (
              <div key={img.url} className="group relative h-16 w-16 overflow-hidden rounded-md border">
                <img src={img.url} alt={img.alt} className="h-full w-full object-cover" />
                <button
                  type="button"
                  title="Remove"
                  aria-label={`Remove image`}
                  onClick={() => setImages((prev) => prev.filter((i) => i.url !== img.url))}
                  className="absolute right-0.5 top-0.5 rounded bg-white/90 p-0.5 text-muted-foreground opacity-0 transition-opacity hover:text-destructive group-hover:opacity-100"
                >
                  <X className="h-3 w-3" />
                </button>
              </div>
            ))}
          </div>
        )}
      </EntityCardSection>

      <div className="mt-6 flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button type="button" onClick={() => void handleCreate()} disabled={!canSubmit}>
          {submitting ? 'Creating…' : 'Create project'}
        </Button>
      </div>
    </EntityCard>
  );
}
