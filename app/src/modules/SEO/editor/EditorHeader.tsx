/**
 * THE EDITOR HEADER (decomposition final pair, gap d0d063c): page mode's
 * two rows — the document row (identity corner, title, status, escape
 * hatches, versions, close) and the workbench row (Business ▸ Page type ▸
 * Keywords · Insert ▸ model ▸ Optimize) — plus section mode's draggable
 * single row, the Ask-AI instruction box, and the new-section placement.
 * JSX moved verbatim from SectionModal.tsx. Self-contained logic lives
 * HERE (status save, brand link, page-type save, insert menu); everything
 * else flows through the explicit contract.
 */

import { useEffect, useState } from 'react';
import {
  CloudOff, ExternalLink, Eye, ImagePlus, KeyRound, Loader2,
  MessageCircleQuestion, MessageSquarePlus, Plus, RefreshCw, Sparkles,
} from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { ModelDropdown, PillSplitButton } from '@/components/shared';
import { DROPDOWN_TRIGGER_STYLE } from '@/components/shared/ModelDropdown';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger } from '@/components/ui/select';
import { Pill } from '@/components/ui/pill';
import { statusPillVariant } from '../types';
import type { SectionAnchor } from './types';

const PAGE_TYPE_OPTIONS = [
  { id: 'general', name: 'General' },
  { id: 'local', name: 'Local search' },
  { id: 'blog', name: 'Blog article' },
  { id: 'product', name: 'Product' },
  { id: 'service', name: 'Service' },
  { id: 'landing', name: 'Landing page' },
];

export interface EditorHeaderProps {
  isPage: boolean;
  isInsert: boolean;
  readOnly: boolean;
  docLoaded: boolean;
  reviewOpen: boolean;
  siteId: number | 'local';
  postId: number;
  type: 'post' | 'page';
  page?: {
    title: string; editUrl?: string; date?: string; permalink?: string; onPreview?: () => void;
    primaryKeyword?: string; supportingKeyword?: string; status?: string;
  };
  /** Section mode's title line + served dot. */
  title: string;
  served: boolean;
  /** Section-mode drag (the header is the handle). */
  onDragStart: (e: React.PointerEvent) => void;
  onDragMove: (e: React.PointerEvent) => void;
  onDragEnd: () => void;
  /** THE IDENTITY-CORNER STATUS (gap d4aa30b) — the composer's page-state
   *  check feeds four true states; no verdict = the quiet neutral square. */
  cornerSaving: boolean;
  stateFetching: boolean;
  pageDrifted: boolean;
  stateUnreachable: boolean;
  stateErrorDetail: string;
  stateLive: boolean;
  onLoadLiveView: () => void;
  onRetryState: () => void;
  /** Shared nodes (one definition, two placements — the composer's). */
  versionsControl: React.ReactNode;
  closeButton: React.ReactNode;
  aiModelSelect: React.ReactNode;
  /** Page type — state lives in the composer (the analyze rail reads it). */
  pageType: string;
  onPageTypeChange: (t: string) => void;
  /** The composer's page context (featherweight check OR the inventory
   *  fallback) — the brand link's two-source truth (addendum 3). */
  brandContext: any;
  /** The smart keywords button's live values. */
  primaryKw: string;
  kwExtraCount: number;
  primaryDensity: number;
  onToggleKeywords: () => void;
  /** Insert menu actions. */
  busyImageAdd: boolean;
  onAddImage: () => void;
  onInsertFaq: () => void;
  /** The one AI entry point. */
  tickedCount: number;
  hasSelection: boolean;
  runQuickOptimize: () => void;
  onOpenAnalyze: () => void;
  onOpenAsk: () => void;
  /** Section mode's AI row + the instruction box. */
  askOpen: boolean;
  onToggleAsk: () => void;
  busyAi: boolean;
  onRunAi: () => void;
  instruction: string;
  onInstructionChange: (v: string) => void;
  onRunInstruction: () => void;
  /** New-section placement (create mode). */
  showPlacement: boolean;
  position: 'before' | 'after';
  onPositionChange: (p: 'before' | 'after') => void;
  anchorIdx: number;
  onAnchorIdxChange: (i: number) => void;
  anchors?: SectionAnchor[];
}

export function EditorHeader(p: EditorHeaderProps) {
  // ── Status dropdown (owner order 2026-07-15): the SAME control + save
  //    path as the table's status cell — optimistic by law, failure
  //    reverts AND says so. ──
  const [pageStatus, setPageStatus] = useState(p.page?.status ?? '');
  useEffect(() => { setPageStatus(p.page?.status ?? ''); }, [p.page?.status]);
  const statusMutation = trpc.seo.remoteSaveCell.useMutation();
  const pickStatus = (v: string) => {
    const prev = pageStatus;
    setPageStatus(v); // the UI moves NOW
    statusMutation.mutateAsync({ siteId: p.siteId as number, postId: p.postId, field: 'status', value: v, type: p.type })
      .then(() => toast.success(v === 'publish' ? 'Published — saved changes now render on the live page.' : `Status: ${v}`))
      .catch((e: unknown) => {
        setPageStatus(prev); // revert to the truth
        toast.error(`Could not change the status — ${e instanceof Error ? e.message : 'the save failed'}`);
      });
  };

  // ── Business + page type (owner order 2026-07-13): the linked brand
  //    feeds real business details into every AI run; the page type tells
  //    the AI WHAT it's optimizing. Both visible — you SEE the context. ──
  const brandsQuery = trpc.brands.list.useQuery(undefined, { enabled: p.isPage && !p.readOnly });
  const brands = Array.isArray(brandsQuery.data)
    ? (brandsQuery.data as any[]).map((b) => ({ id: Number(b.id), name: String(b.name) }))
    : [];
  const [brandId, setBrandId] = useState(0);
  // brandId arrives via the COMPOSER's context (featherweight check OR the
  // inventory fallback — whichever answers first, the original two-source
  // law); the composer stays the ONE page-state query owner (addendum 3).
  useEffect(() => {
    const src: any = p.brandContext;
    if (!src) return;
    setBrandId(Number(src.brandId ?? 0));
  }, [p.brandContext]);
  const brandMutation = trpc.sites.update.useMutation();
  const pickBrand = (id: string) => {
    const n = Number(id);
    setBrandId(n);
    brandMutation.mutateAsync({ id: p.siteId, brandId: n })
      .then(() => toast.success(n > 0 ? 'Business linked — the AI now uses its details' : 'Business unlinked'))
      .catch((err: unknown) => toast.error(err instanceof Error ? err.message : 'Failed to link the business'));
  };
  const pageTypeMutation = trpc.seo.remoteSavePageType.useMutation();
  const pickPageType = (t: string) => {
    p.onPageTypeChange(t);
    pageTypeMutation.mutateAsync({ siteId: p.siteId, postId: p.postId, type: t === 'general' ? '' : t })
      .catch((err: unknown) => toast.error(err instanceof Error ? err.message : 'Failed to save the page type'));
  };
  const [insertOpen, setInsertOpen] = useState(false);

  return (
    <>
      {/* ── Header. PAGE mode (owner UX 2026-07-13): TWO rows — row 1 = the
             document (identity, escape hatches, versions, close), row 2 = the
             workbench (AI context left, tools right). SECTION mode: the
             original draggable single row. ── */}
      <div
        className={`select-none border-b bg-white ${p.isPage ? 'border-slate-100' : 'flex items-center gap-1.5 border-slate-200 px-2.5 py-1.5 cursor-grab active:cursor-grabbing'}`}
        onPointerDown={p.isPage ? undefined : p.onDragStart}
        onPointerMove={p.isPage ? undefined : p.onDragMove}
        onPointerUp={p.isPage ? undefined : p.onDragEnd}
      >
        {p.isPage && (
          <div className="flex items-center gap-2 px-5 pb-2 pt-3">
            {/* THE IDENTITY-CORNER STATUS (gap d4aa30b, Jony DoD): four true
                states; no verdict = the quiet neutral square. Hover tells
                the truth, click acts. */}
            {!p.readOnly && p.cornerSaving ? (
              <span title="Saving — pushing to your site…" className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-50">
                <RefreshCw className="h-3.5 w-3.5 animate-spin text-slate-400" />
              </span>
            ) : !p.readOnly && p.stateFetching ? (
              <span title="Checking the site connection…" className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-50">
                <RefreshCw className="h-3.5 w-3.5 animate-spin text-slate-400" />
              </span>
            ) : !p.readOnly && p.pageDrifted ? (
              <button
                type="button"
                onClick={p.onLoadLiveView}
                title="The live page differs from your saved version — click to load the live view"
                className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-amber-50 text-amber-500 hover:bg-amber-100"
              >
                <RefreshCw className="h-3.5 w-3.5" />
              </button>
            ) : !p.readOnly && p.stateUnreachable ? (
              <button
                type="button"
                onClick={p.onRetryState}
                title={`Couldn't reach the site to verify — click to retry${p.stateErrorDetail ? ` (${p.stateErrorDetail})` : ''}`}
                className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-red-50 text-red-400 hover:bg-red-100"
              >
                <CloudOff className="h-3.5 w-3.5" />
              </button>
            ) : !p.readOnly && p.stateLive ? (
              <span title="Live — the site serves your saved version" className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-green-50">
                <span className="h-2 w-2 rounded-full bg-green-500" />
              </span>
            ) : (
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-50">
                <span className="h-2 w-2 rounded-full bg-slate-300" />
              </span>
            )}
            <span className="min-w-0">
              <span className="block truncate text-[13px] font-medium leading-[1.4] text-slate-800" title={p.page?.title ?? 'Page'}>
                {p.page?.title ?? 'Page'}
              </span>
              {p.page?.date && (
                <span className="mt-0.5 block truncate text-[10px] leading-none text-slate-400">{String(p.page.date).slice(0, 10)}</span>
              )}
            </span>
            {/* Status — the table's exact control, same save path (owner
                order 2026-07-15). Sits right of the title by design. */}
            {!p.readOnly && pageStatus !== '' && (
              <Select value={pageStatus} onValueChange={pickStatus}>
                <SelectTrigger variant="ghost" size="auto" className="h-auto w-auto shrink-0">
                  <Pill variant={statusPillVariant(pageStatus)} className="capitalize">{pageStatus}</Pill>
                </SelectTrigger>
                <SelectContent>
                  {['publish', 'draft', 'pending', 'private', 'future'].map((s) => (
                    <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            {p.page?.editUrl && (
              <a
                href={p.page.editUrl}
                target="_blank"
                rel="noopener noreferrer"
                title="Open this page in the site’s WP editor (source editing)"
                className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-slate-500 hover:bg-slate-100 hover:text-slate-800"
              >
                <ExternalLink className="h-3 w-3" /> Edit
              </a>
            )}
            {p.page?.permalink && (
              <a
                // A DRAFT has no public URL (WP hands drafts a ?page_id= link
                // that shows nothing to a visitor) — Open carries preview=true
                // so the REAL page renders as a logged-in preview.
                href={p.page.status && p.page.status !== 'publish'
                  ? `${p.page.permalink}${p.page.permalink.includes('?') ? '&' : '?'}preview=true`
                  : p.page.permalink}
                target="_blank"
                rel="noopener noreferrer"
                title={p.page.status && p.page.status !== 'publish' ? 'Open this draft as a preview in a new tab' : 'Open the live page in a new tab'}
                className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-slate-500 hover:bg-slate-100 hover:text-slate-800"
              >
                <ExternalLink className="h-3 w-3" /> Open
              </a>
            )}
            {p.page?.onPreview && (
              <button
                type="button"
                onClick={p.page.onPreview}
                title="Preview the live page here, in the inline preview window"
                className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-slate-500 hover:bg-slate-100 hover:text-slate-800"
              >
                <Eye className="h-3 w-3" /> Preview
              </button>
            )}
            <div className="flex-1" />
            {p.versionsControl}
            {p.closeButton}
          </div>
        )}
        {/* Row 2 — the workbench: the AI's context left (business, page
            type), the tools right (Insert ▸ model ▸ Optimize — reads like a
            sentence: insert things; optimize with this model). */}
        {p.isPage && !p.readOnly && p.docLoaded && !p.reviewOpen && (
          <div className="flex items-center gap-1.5 border-t border-slate-100 bg-white px-5 py-1.5">
            <ModelDropdown
              modelGroups={[{
                label: 'Business',
                models: [{ id: '0', name: 'No business' }, ...brands.map((b) => ({ id: String(b.id), name: b.name }))],
              }]}
              selectedModel={String(brandId)}
              onModelChange={pickBrand}
            />
            <ModelDropdown
              modelGroups={[{ label: 'Page type', models: PAGE_TYPE_OPTIONS }]}
              selectedModel={p.pageType}
              onModelChange={pickPageType}
            />
            {/* THE SMART KEYWORDS BUTTON (owner 2026-07-14): the hierarchy's
                third value — Business → Page type → Keywords. A LIVE display
                in the dropdown-family look: primary · +count · density%. */}
            <button
              type="button"
              onClick={p.onToggleKeywords}
              title="The page's keywords — they ride every optimization; click to manage"
              style={DROPDOWN_TRIGGER_STYLE}
              className="flex items-center gap-1.5"
            >
              <KeyRound className="h-3 w-3 shrink-0" />
              <span className="max-w-[140px] truncate">{p.primaryKw.trim() || 'Keywords'}</span>
              {p.kwExtraCount > 0 && <span className="shrink-0 text-slate-400">+{p.kwExtraCount}</span>}
              {p.primaryKw.trim() !== '' && (
                <span className="shrink-0 font-semibold text-primary">{p.primaryDensity}%</span>
              )}
            </button>
            <div className="flex-1" />
            <div className="relative shrink-0">
              <button
                type="button"
                onClick={() => setInsertOpen((v) => !v)}
                title="Insert content at the cursor"
                className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium hover:bg-slate-100 ${insertOpen ? 'bg-slate-100 text-slate-800' : 'text-slate-500 hover:text-slate-800'}`}
              >
                {p.busyImageAdd
                  ? <Loader2 className="h-3 w-3 animate-spin text-primary" />
                  : <Plus className="h-3 w-3" />} Insert <span className="text-slate-400">▾</span>
              </button>
              {insertOpen && (
                <div className="absolute right-0 top-full z-10 mt-1 w-[150px] overflow-hidden rounded-md border border-slate-200 bg-white py-0.5 shadow-md">
                  <button
                    type="button"
                    onClick={() => { setInsertOpen(false); p.onAddImage(); }}
                    className="flex w-full items-center gap-1.5 px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-50"
                  >
                    <ImagePlus className="h-3 w-3 text-slate-400" /> Image
                  </button>
                  <button
                    type="button"
                    onClick={() => { setInsertOpen(false); p.onInsertFaq(); }}
                    className="flex w-full items-center gap-1.5 px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-50"
                  >
                    <MessageCircleQuestion className="h-3 w-3 text-slate-400" /> FAQ
                  </button>
                </div>
              )}
            </div>
            {p.aiModelSelect}
            {/* ONE AI entry point (gap eeec6b9 D1 — the Analyze pill died
                into this menu): EVERY run goes through the red/green review.
                Main click = Quick (the action matrix decides its shape);
                the caret menu holds the three modes. */}
            <PillSplitButton
              icon={<Sparkles />}
              onClick={p.runQuickOptimize}
              title={p.tickedCount > 0
                ? (p.hasSelection
                  ? 'Weave the ticked keywords into the selected sections by their roles — changes show as red/green'
                  : 'Weave the ticked keywords into the content by their roles — changes show as red/green')
                : (p.hasSelection
                  ? 'Rewrite the selected sections with AI — changes show as red/green for you to accept or reject'
                  : 'Rewrite the whole page with AI — every change shows as red/green for you to accept or reject')}
              caretTitle="Optimization modes"
              menu={
                <>
                  <DropdownMenuItem onClick={p.runQuickOptimize}>
                    Quick optimize
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={p.onOpenAnalyze}>
                    Super optimize…
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={p.onOpenAsk}>
                    Custom instruction…
                  </DropdownMenuItem>
                </>
              }
            >
              {p.tickedCount > 0
                ? `Insert keywords (${p.tickedCount})`
                : p.hasSelection ? 'Optimize (selected text)' : 'Optimize page'}
            </PillSplitButton>
          </div>
        )}
        {!p.isPage && (
          <>
            <div className="flex min-w-0 flex-1 items-center gap-2">
              <span className="min-w-0 truncate text-xs font-medium text-slate-800" title={p.title}>
                {p.served && <span className="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-primary align-middle" title="Optimized — a section rule serves this content" />}
                {p.title}
              </span>
            </div>
            {p.versionsControl}
            {!p.readOnly && (
              <>
                {p.aiModelSelect}
                <button
                  type="button"
                  onClick={p.onToggleAsk}
                  title="Tell the AI what to do with this section"
                  className={`inline-flex shrink-0 items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[11px] hover:bg-slate-50 ${p.askOpen ? 'text-primary border-primary/40' : 'text-slate-600'}`}
                >
                  <MessageSquarePlus className="h-3 w-3" /> Ask AI
                </button>
                <button
                  type="button"
                  onClick={p.onRunAi}
                  title={p.isInsert ? 'Draft this section with AI' : 'Rewrite this section with AI'}
                  className="inline-flex shrink-0 items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[11px] text-slate-600 hover:bg-slate-50 hover:text-primary"
                >
                  {p.busyAi ? <Loader2 className="h-3 w-3 animate-spin text-primary" /> : <Sparkles className="h-3 w-3" />}
                  {p.isInsert ? 'Generate' : 'Re-write'}
                </button>
              </>
            )}
            {p.closeButton}
          </>
        )}
      </div>

      {/* ── Ask-AI instruction (Enter runs it) ── */}
      {p.askOpen && !p.readOnly && (
        <div className="border-b border-slate-200 px-2.5 py-1.5">
          <input
            autoFocus
            value={p.instruction}
            onChange={(e) => p.onInstructionChange(e.target.value)}
            onKeyDown={(e) => {
              if (e.key !== 'Enter' || !p.instruction.trim()) return;
              p.onRunInstruction();
            }}
            placeholder="e.g. “optimize for keyword X” or “inject keyword Y five times” — Enter to run"
            className="h-6 w-full rounded border border-slate-200 bg-white px-2 text-[11px] text-slate-800 outline-none focus:border-primary"
          />
        </div>
      )}

      {/* ── New-section placement (create mode only) ── */}
      {p.showPlacement && (
        <div className="flex items-center gap-1.5 border-b border-slate-200 px-2.5 py-1.5 text-[11px]">
          <span className="shrink-0 text-slate-500">Place</span>
          <select value={p.position} onChange={(e) => p.onPositionChange(e.target.value === 'before' ? 'before' : 'after')} className="h-6 rounded border border-slate-200 bg-white px-1 text-[11px]">
            <option value="after">after</option>
            <option value="before">before</option>
          </select>
          <select value={p.anchorIdx} onChange={(e) => p.onAnchorIdxChange(Number(e.target.value))} className="h-6 min-w-0 flex-1 truncate rounded border border-slate-200 bg-white px-1 text-[11px]">
            {(p.anchors ?? []).map((a, i) => (
              <option key={`${a.text}-${i}`} value={i}>{`H${a.level}: ${a.text}`}</option>
            ))}
          </select>
        </div>
      )}
    </>
  );
}
