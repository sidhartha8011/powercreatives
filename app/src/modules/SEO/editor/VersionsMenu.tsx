/**
 * THE VERSIONS DROPDOWN (decomposition S4b, gap d0d063c): the trigger +
 * menu UI over useSectionVersions — Draft state, THE ORIGINAL ROW THAT
 * ALWAYS EXISTS (loading / unavailable-with-retry states), bulk cleanup,
 * per-row pick/delete. JSX moved verbatim from SectionModal.tsx; its
 * contract IS the hook's return — one source, zero drift.
 */

import { Check, Loader2, Trash2 } from 'lucide-react';
import type { useSectionVersions } from './useSectionVersions';

export interface VersionsMenuProps {
  isPage: boolean;
  pageDirty: boolean;
  versionLabel: string;
  v: ReturnType<typeof useSectionVersions>;
}

export function VersionsMenu({ isPage, pageDirty, versionLabel, v }: VersionsMenuProps) {
  const {
    versionsOpen, setVersionsOpen, originalHtml, pageOriginalQuery,
    versions, versionPick, setVersionPick, pickVersion,
    pageRowLabel, pageOriginalLabel,
    deleteVersion, versionSel, setVersionSel, bulkDeleting, toggleVersionSel,
    deletableVersionIds, deleteSelectedVersions,
  } = v;
  return (
    <div className="relative shrink-0">
      <button
        type="button"
        onClick={() => setVersionsOpen((open) => !open)}
        title="Versions — pick one to view it — saving makes it live"
        className="inline-flex max-w-[190px] items-center gap-1.5 truncate rounded-full border border-slate-200 bg-white px-2.5 py-[3px] text-xs font-medium text-slate-500 hover:bg-slate-50"
      >
        <span className="truncate">{versionLabel}</span>
        <span className="text-slate-400">▾</span>
      </button>
      {versionsOpen && (
        <div className="absolute right-0 top-full z-10 mt-1 w-[230px] overflow-hidden rounded-md border border-slate-200 bg-white py-0.5 shadow-md">
          {isPage && pageDirty && (
            <button
              type="button"
              onClick={() => { setVersionPick(''); setVersionsOpen(false); }}
              className="block w-full px-2 py-1 text-left text-[11px] font-medium text-slate-800 hover:bg-slate-50"
            >
              {versionPick === '' && <Check className="mr-1 inline h-3 w-3 text-primary" />}
              Draft (unsaved)
            </button>
          )}
          {/* THE ORIGINAL ROW ALWAYS EXISTS (owner law 2026-07-19, gap
              e533bc5 C7): the original is the original on the site — an
              in-flight or failed fetch is a STATED state with a retry,
              never a vanishing row (the versions-delete "fix" was a race). */}
          {!isPage ? (
            <button
              type="button"
              onClick={() => pickVersion('original')}
              className="block w-full px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-50"
            >
              {versionPick === 'original' && <Check className="mr-1 inline h-3 w-3 text-primary" />}
              Original
            </button>
          ) : originalHtml !== '' ? (
            <button
              type="button"
              onClick={() => pickVersion('original')}
              className="block w-full px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-50"
            >
              {versionPick === 'original' && <Check className="mr-1 inline h-3 w-3 text-primary" />}
              {pageOriginalLabel}
            </button>
          ) : pageOriginalQuery.isFetching ? (
            <div className="flex w-full items-center gap-1 px-2 py-1 text-left text-[11px] text-slate-400">
              <Loader2 className="h-3 w-3 animate-spin text-primary" /> {pageOriginalLabel} — loading…
            </div>
          ) : (
            <button
              type="button"
              onClick={() => void pageOriginalQuery.refetch()}
              title="The site didn't answer with the original — press to retry"
              className="block w-full px-2 py-1 text-left text-[11px] text-amber-600 hover:bg-amber-50"
            >
              {pageOriginalLabel} — unavailable, retry
            </button>
          )}
          {/* Bulk cleanup bar — shown when there is history to clean. The
              Current version never joins select-all (W0 opens from it). */}
          {deletableVersionIds.length > 0 && (
            <div className="flex items-center gap-1.5 border-b border-slate-100 px-2 py-1">
              <input
                type="checkbox"
                checked={versionSel.size > 0 && versionSel.size === deletableVersionIds.length}
                onChange={() => setVersionSel(versionSel.size === deletableVersionIds.length ? new Set() : new Set(deletableVersionIds))}
                title="Select all versions except the current one"
                className="h-3 w-3 shrink-0 accent-[#007bff]"
              />
              <span className="min-w-0 flex-1 truncate text-[10px] text-slate-400">
                {versionSel.size > 0 ? `${versionSel.size} selected` : 'Select versions'}
              </span>
              {versionSel.size > 0 && (
                <button
                  type="button"
                  onClick={() => { void deleteSelectedVersions(); }}
                  disabled={bulkDeleting}
                  className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium text-destructive hover:bg-red-50 disabled:opacity-50"
                >
                  {bulkDeleting ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />}
                  Delete ({versionSel.size})
                </button>
              )}
            </div>
          )}
          {versions.map((row, idx) => (
            <div key={row.id} className="flex items-center hover:bg-slate-50">
              {/* idx 0 = Current — deliberately no checkbox (single delete stays). */}
              {idx > 0 ? (
                <input
                  type="checkbox"
                  checked={versionSel.has(Number(row.id))}
                  onChange={() => toggleVersionSel(Number(row.id))}
                  className="ml-2 h-3 w-3 shrink-0 accent-[#007bff]"
                />
              ) : (
                <span className="ml-2 h-3 w-3 shrink-0" />
              )}
              <button
                type="button"
                onClick={() => pickVersion(String(row.id))}
                className="min-w-0 flex-1 truncate px-2 py-1 text-left text-[11px] text-slate-700"
              >
                {(versionPick === String(row.id) || (versionPick === '' && !pageDirty && isPage && idx === 0)) && (
                  <Check className="mr-1 inline h-3 w-3 text-primary" />
                )}
                {isPage ? pageRowLabel(row, idx) : row.createdAt.slice(0, 16)}
              </button>
              <button
                type="button"
                onClick={() => { void deleteVersion(row.id); }}
                title="Delete this version"
                className="shrink-0 rounded p-1 text-slate-400 hover:text-destructive"
              >
                <Trash2 className="h-3 w-3" />
              </button>
            </div>
          ))}
          {versions.length === 0 && (
            <div className="px-2 py-1 text-[11px] text-slate-400">No saved versions yet</div>
          )}
        </div>
      )}
    </div>
  );
}
