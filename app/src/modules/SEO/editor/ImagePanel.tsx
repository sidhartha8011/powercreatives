/**
 * THE IMAGE METADATA PANEL (decomposition final pair, gap d0d063c): the
 * outside-rail aside over useImagePanel — alt/title rule form, save/hide/
 * revert. JSX moved verbatim from SectionModal.tsx; its contract is the
 * hook's return + the composer's busy verb.
 */

import { Check, Loader2, Trash2, Undo2 } from 'lucide-react';
import type { useImagePanel } from './useImagePanel';

export interface ImagePanelProps {
  img: ReturnType<typeof useImagePanel>;
  busyAction: string | null;
}

export function ImagePanel({ img, busyAction }: ImagePanelProps) {
  const { imgSel, imgForm, setImgForm, imgHasRule, saveImageMeta } = img;
  if (!imgSel) return null;
  return (
    <aside className="flex h-full w-[250px] shrink-0 flex-col bg-slate-50/60">
      <div className="border-b border-slate-200 px-2.5 py-1.5">
        <div className="text-[11px] font-medium text-slate-700">Image metadata</div>
        <div className="truncate text-[10px] text-slate-400" title={imgSel.src}>{imgSel.src}</div>
      </div>
      <div className="space-y-2 px-2.5 py-2">
        <label className="block">
          <span className="text-[10px] font-medium text-slate-500">Alt text</span>
          <input
            value={imgForm.alt}
            onChange={(e) => setImgForm((f) => ({ ...f, alt: e.target.value }))}
            placeholder="Describe the image"
            className="mt-0.5 h-6 w-full rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-800 outline-none focus:border-primary"
          />
        </label>
        <label className="block">
          <span className="text-[10px] font-medium text-slate-500">Title</span>
          <input
            value={imgForm.title}
            onChange={(e) => setImgForm((f) => ({ ...f, title: e.target.value }))}
            placeholder="Tooltip title (optional)"
            className="mt-0.5 h-6 w-full rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-800 outline-none focus:border-primary"
          />
        </label>
        <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
          <button
            type="button"
            onClick={() => { void saveImageMeta('save'); }}
            className="inline-flex items-center gap-1 rounded bg-success px-2 py-1 text-[10px] font-medium text-success-foreground hover:bg-success/85"
          >
            {busyAction === 'imageSave' ? <Loader2 className="h-3 w-3 animate-spin" /> : <Check className="h-3 w-3" />} Save
          </button>
          <button
            type="button"
            onClick={() => { void saveImageMeta('hide'); }}
            title="Stop serving this image — it stays in the media library; restore it via the versions dropdown"
            className="inline-flex items-center gap-1 rounded border border-destructive/30 bg-card px-2 py-1 text-[10px] text-destructive hover:bg-destructive/10"
          >
            {busyAction === 'imageHide' ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />} Hide image
          </button>
          {imgHasRule && (
            <button
              type="button"
              onClick={() => { void saveImageMeta('revert'); }}
              title="Delete this image's metadata rule — the original alt/title serve again"
              className="inline-flex items-center gap-1 rounded border border-slate-200 bg-white px-2 py-1 text-[10px] text-slate-600 hover:bg-slate-50"
            >
              {busyAction === 'imageRevert' ? <Loader2 className="h-3 w-3 animate-spin" /> : <Undo2 className="h-3 w-3" />} Revert to original
            </button>
          )}
        </div>
        <p className="text-[10px] leading-relaxed text-slate-400">
          Served dynamically — the image file is never touched. Hiding stops it serving; deleting it in the text (or with its section) does the same on save.
        </p>
      </div>
    </aside>
  );
}
