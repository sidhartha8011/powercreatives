/**
 * THE SAVE BAR (decomposition final pair, gap d0d063c): Save & close →
 * Save (page mode) → Undo → Remove (added sections). JSX moved verbatim
 * from SectionModal.tsx; every action flows back through the contract.
 */

import { Check, Loader2, Save, Trash2, Undo2 } from 'lucide-react';
import { PillButton } from '@/components/shared';

export interface EditorFooterProps {
  isPage: boolean;
  isInsert: boolean;
  /** An existing added section — the Remove action's gate. */
  insertHasRule: boolean;
  docLoaded: boolean;
  reviewOpen: boolean;
  busyAction: string | null;
  onSaveClose: () => void;
  onSave: () => void;
  onUndo: () => void;
  onRemove: () => void;
}

export function EditorFooter({
  isPage, isInsert, insertHasRule, docLoaded, reviewOpen, busyAction,
  onSaveClose, onSave, onUndo, onRemove,
}: EditorFooterProps) {
  return (
    <div className="flex items-center gap-1.5 border-t border-slate-200 bg-white px-2.5 py-1.5">
      {/* Order (owner): Save & close → Save (page mode, stays open) → Undo.
          The pill family (owner 2026-07-13): Save & close = the SHARED
          green success pill; the rest are rounded siblings, same height. */}
      <PillButton
        variant="success"
        icon={<Check />}
        loading={busyAction === 'saveClose'}
        disabled={isPage && (!docLoaded || reviewOpen)}
        onClick={onSaveClose}
      >
        {isPage ? 'Save & close' : 'Save'}
      </PillButton>
      {isPage && (
        <button
          type="button"
          onClick={onSave}
          disabled={!docLoaded || reviewOpen}
          title="Save — the window stays open"
          className="inline-flex items-center gap-1 rounded-full border border-green-600 bg-white px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-60"
        >
          {busyAction === 'save' ? <Loader2 className="h-3 w-3 animate-spin" /> : <Save className="h-3 w-3" />} Save
        </button>
      )}
      <button
        type="button"
        onClick={onUndo}
        disabled={isPage && (!docLoaded || reviewOpen)}
        title="Restore the last saved state"
        className="inline-flex items-center gap-1 rounded-full border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-60"
      >
        <Undo2 className="h-3 w-3" /> Undo
      </button>
      <div className="flex-1" />
      {isInsert && insertHasRule && (
        <button
          type="button"
          onClick={onRemove}
          title="Remove this added section from the live page"
          className="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] text-slate-600 hover:bg-slate-50 hover:text-destructive"
        >
          {busyAction === 'remove' ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />} Remove
        </button>
      )}
    </div>
  );
}
