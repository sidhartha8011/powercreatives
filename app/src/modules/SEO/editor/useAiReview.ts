/**
 * THE RUN ENGINE (decomposition S3, gap 325d280/10a0233): every AI run —
 * quick, super, custom, keyword insert, revise — and the whole review
 * lifecycle live HERE, one owner. The composer passes the editor + the
 * live inputs; the engine returns the full surface the UI consumes.
 * Moved verbatim from SectionModal.tsx; a run bug lives in THIS file.
 *
 * THE LEAK FIX (owner report 2026-07-19, blueprint §10 fix 4): the run's
 * compiled order now DIES with its review — closing a review clears
 * `runDirectives`, so a later custom edit can never wear a stale
 * "super optimize" identity.
 */

import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import type { Editor } from '@tiptap/core';

import { trpc } from '@/lib/trpc';
import {
  diffBlocksHtml, splitDocSections, splitReviewSections, stripDiffHtml,
} from '../word-diff';
import type { CompiledDirective, TeacherMeta } from '../optimizer/types';
import type { TickedKeyword } from '../optimizer/KeywordsDrawer';
import { LANGUAGE_LAW } from './layout';
import { canonicalAiHtml, htmlText, restateOrigin, stampReviewId } from './content-laws';
import type { ReviewSection, ReviewStatus } from './types';

export interface UseAiReviewArgs {
  editor: Editor | null;
  isPage: boolean;
  docLoaded: boolean;
  /** The composer's one-long-action-at-a-time guard. */
  busy: boolean;
  siteId: number | 'local';
  postId: number;
  type: 'post' | 'page';
  model?: string;
  provider?: string;
  aiPick: { id: string; provider?: string } | null;
  /** The instruction box's live text (custom orders ride the main click). */
  instruction: string;
  hasSelection: boolean;
  primaryKw: string;
  supportingKw: string;
  bucketKeywords: string[];
  tickedKw: TickedKeyword[];
  /** Close the instruction box (a starting run always closes it). */
  closeAsk: () => void;
  /** runAi's busy bracket — the composer names the running control. */
  beginAiAction: () => void;
  endAiAction: () => void;
}

export function useAiReview(args: UseAiReviewArgs) {
  const {
    editor, isPage, docLoaded, busy, siteId, postId, type, model, provider,
    aiPick, instruction, hasSelection, primaryKw, supportingKw,
    bucketKeywords, tickedKw, closeAsk, beginAiAction, endAiAction,
  } = args;

  const optimizeMutation = trpc.seo.remoteOptimizeSection.useMutation();

  // ── AI: Re-write = whole-section rewrite into the editor; Ask AI adds an instruction. ──
  const runAi = async (withInstruction: string) => {
    if (busy) return;
    beginAiAction();
    try {
      const current = editor?.getText().trim() ? (editor?.getHTML() ?? '') : '';
      const res: any = await optimizeMutation.mutateAsync({
        siteId: siteId as number, postId, type,
        html: current, topic: withInstruction + LANGUAGE_LAW,
        model: aiPick?.id ?? model, provider: aiPick?.provider ?? provider,
      });
      const value = String(res?.value ?? '').trim();
      if (value) { editor?.commands.setContent(value); closeAsk(); }
      else toast.info('The section already looks optimized.');
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not run the AI on this section');
    } finally {
      endAiAction();
    }
  };

  // ── AI review (page mode, V2): per-section rewrites shown as an inline
  //    red/green diff; Accept applies the AI's clean HTML, Reject restores the
  //    original — the diff view itself is never what gets kept. ──
  const [review, setReview] = useState<ReviewSection[] | null>(null);
  // Live mirror for async workers (their results must be dropped when the
  // user already decided a section — e.g. OK'd the review mid-flight).
  const reviewRef = useRef<ReviewSection[] | null>(null);
  /** The compiled order behind the CURRENT review (basket runs only) — the
   *  purpose bullets + pills the user verifies suggestions against. Plain
   *  Optimize runs carry none, honestly. */
  const [runDirectives, setRunDirectives] = useState<CompiledDirective[] | null>(null);
  // Purpose → group/plain-name map for the review pills (same cached query
  // the rail uses; enabled only while a directive run is showing).
  const teachersMetaQuery = trpc.optimizer.teachers.useQuery(undefined, {
    staleTime: 60_000,
    enabled: isPage && runDirectives !== null,
  });
  const teacherById: Record<string, TeacherMeta> = Array.isArray((teachersMetaQuery.data as any)?.teachers)
    ? Object.fromEntries(((teachersMetaQuery.data as any).teachers as TeacherMeta[]).map((t) => [t.id, t]))
    : {};
  const historyStampMutation = trpc.optimizer.historyStamp.useMutation();

  // ── SURGERY ENGINE (review-edit-revise, 2026-07-13): during a review the
  //    DOCUMENT is the single source of truth for content; review state owns
  //    only statuses + originals. ONE writer touches the doc — every
  //    transition (suggestion lands / accept / reject / revise result) goes
  //    through applySection on its OWN range. ──
  /** Section i's live range BY ANCHOR (identity law 2026-07-14): from its
   *  id-stamped heading to the NEXT id-carrying heading — id-LESS headings
   *  (sections the AI added mid-review) belong to the section that produced
   *  them. Counting headings is banned here: the count changes mid-review. */
  const sectionRange = (i: number): { from: number; to: number } | null => {
    if (!editor) return null;
    const id = String(i);
    let from = -1;
    let to = -1;
    editor.state.doc.forEach((node, pos) => {
      if (node.type.name !== 'heading') return;
      const rid = (node.attrs['data-pcm-review-id'] as string | null) ?? null;
      if (from < 0) {
        if (rid === id) from = pos;
      } else if (to < 0 && rid !== null) {
        to = pos;
      }
    });
    if (from < 0) return null;
    return { from, to: to < 0 ? editor.state.doc.content.size : to };
  };
  const applySection = (i: number, html: string) => {
    const r = sectionRange(i);
    if (!editor || !r) return;
    // No .focus(): a landing suggestion must never steal the user's caret.
    // The anchor rides EVERY write — identity survives by construction.
    editor.chain().insertContentAt({ from: r.from, to: r.to }, stampReviewId(html, i)).run();
  };
  /** THE content oracle (review-integrity, 2026-07-13): what a section's
   *  decision keeps. UNTOUCHED since the suggestion landed (live == baseline,
   *  exact compare, same serializer both sides) → the AI's stored CLEAN
   *  `s.ai`. EDITED → the stripped live content: the user's words trump the
   *  AI's formatting. Accept AND the Revise draft consume this one function
   *  — no second content path exists. Images ride separately. */
  const effectiveContent = (i: number): { html: string; imgs: string } => {
    const s = reviewRef.current?.[i];
    const live = editor && s ? splitReviewSections(editor.getHTML())[String(i)] : undefined;
    const imgs = (live && live.imgs.length > 0 ? live.imgs : s?.imgs ?? []).join('');
    if (!s || !live) return { html: s?.ai ?? s?.html ?? '', imgs };
    const untouched = s.baseline !== undefined && live.html === s.baseline;
    return { html: untouched ? (s.ai ?? s.html) : stripDiffHtml(live.html), imgs };
  };

  const startAiReview = async (
    topic: string,
    scope?: { from: number; to: number } | null,
    directives?: CompiledDirective[],
    opts?: { suppressKeywordRide?: boolean; strict?: boolean },
  ) => {
    if (!editor || !docLoaded || busy || review) return;
    setRunDirectives(directives && directives.length > 0 ? directives : null);
    // THE KEYWORD RIDE (owner law 2026-07-15): ALL the page's keywords —
    // primary + supporting + additional — join EVERY run as context,
    // never as stuffing orders. An injection run suppresses the ride:
    // its topic IS the keyword order (one order per prompt, never two).
    const kwTargets = [...new Set([
      primaryKw.trim(),
      ...supportingKw.split(',').map((s) => s.trim()),
      ...bucketKeywords,
    ].filter((k) => k !== ''))];
    const kwLine = !opts?.suppressKeywordRide && kwTargets.length > 0
      ? `\n\nTarget keywords — incorporate them naturally where they genuinely fit, never force or stuff: ${kwTargets.join(', ')}`
      : '';
    const { sections } = splitDocSections(editor.getHTML());
    if (sections.length === 0) {
      toast.info('No sections to optimize on this page.');
      return;
    }
    // SCOPE (owner order 2026-07-13): a text selection narrows the SAME
    // review to the sections it touches — out-of-scope sections resolve
    // 'clean' up front (never sent to the AI, invisible in the rail). One
    // pipeline: rail, red/green, Accept/Reject/OK are shared by construction.
    const inScope = new Set<number>();
    if (scope) {
      let idx = -1;
      editor.state.doc.forEach((node, pos) => {
        if (node.type.name === 'heading') idx++;
        if (idx >= 0 && pos < scope.to && pos + node.nodeSize > scope.from) inScope.add(idx);
      });
      if (inScope.size === 0) {
        toast.info('Select text inside a section to optimize it.');
        return;
      }
    }
    // THE ROUTER (gap eeec6b9): a directive with targets is delivered ONLY
    // to its sections; unrouted directives broadcast (the honest floor —
    // never a dropped intent). With routing live, a section nobody ordered
    // work on is never sent at all — the rewrite-everything cause dies.
    const routedDirectives = (directives ?? []).filter((d) => (d.targets?.length ?? 0) > 0);
    const broadcastDirectives = (directives ?? []).filter((d) => (d.targets?.length ?? 0) === 0);
    const routingActive = routedDirectives.length > 0;
    const sectionDirectives = (i: number): CompiledDirective[] => [
      ...routedDirectives.filter((d) => (d.targets ?? []).includes(i)),
      ...broadcastDirectives,
    ];
    const skip = (i: number): boolean =>
      (!!scope && !inScope.has(i)) || (routingActive && sectionDirectives(i).length === 0);
    // IDENTITY ANCHORS (2026-07-14): stamp every section heading with its
    // review id in ONE transaction — the heading ORDINAL is trusted only
    // HERE, at t0, where it still equals the captured section index. From
    // now on the AI may add sections freely; identity never counts again.
    {
      const tr = editor.state.tr;
      let h = -1;
      editor.state.doc.forEach((node, pos) => {
        if (node.type.name !== 'heading') return;
        h++;
        tr.setNodeMarkup(pos, undefined, { ...node.attrs, 'data-pcm-review-id': String(h) });
      });
      editor.view.dispatch(tr);
    }
    closeAsk();
    const initial = sections.map((s, i) => ({
      ...s,
      status: (skip(i) ? 'clean' : 'pending') as ReviewStatus,
      // The section's OWN orders (F4): routed + broadcast for routed runs,
      // every directive for unrouted ones, none for plain quick runs.
      directives: !skip(i) && (directives?.length ?? 0) > 0 ? sectionDirectives(i) : undefined,
    }));
    reviewRef.current = initial; // workers may resolve before the sync effect runs
    setReview(initial);
    // The editor stays EDITABLE (owner F1): the doc is the source of truth,
    // suggestions land by surgery — nothing rebuilds, nothing locks.
    // Bounded pool: 4 sections in flight; a slow/failed section fails ALONE.
    // THE CHANGE CARDS (gap 0a0a3c3): every run asks for the envelope; the
    // run's purposes are the only legal `why` ids (server-verified).
    const runPurposes = Array.from(new Set((directives ?? []).flatMap((d) => d.purposes)));
    // THE PAGE MAP (gap eeec6b9 D4): every call names where it edits —
    // the server appends it so the model never duplicates other sections.
    const outline = sections.map((s) => s.heading || '(untitled section)');
    let next = 0;
    const worker = async () => {
      while (next < sections.length) {
        const i = next++;
        if (skip(i)) continue;
        // A routed section receives ONLY its own orders (+ broadcasts);
        // THE LANGUAGE LAW rides last on every order.
        const own = routingActive ? sectionDirectives(i) : null;
        const sectionTopic = (own
          ? `Apply exactly these optimizations to this section:\n${own.map((d, k) => `${k + 1}. ${d.text}`).join('\n')}`
          : topic) + kwLine + LANGUAGE_LAW;
        const sectionPurposes = own
          ? Array.from(new Set(own.flatMap((d) => d.purposes)))
          : runPurposes;
        try {
          const res: any = await optimizeMutation.mutateAsync({
            siteId: siteId as number, postId, type,
            html: sections[i].html, topic: sectionTopic,
            model: aiPick?.id ?? model, provider: aiPick?.provider ?? provider,
            reportChanges: true, purposes: sectionPurposes,
            outline, sectionIndex: i, strict: !!opts?.strict,
          });
          const value = canonicalAiHtml(editor, String(res?.value ?? '').trim());
          const genModel = String(res?.model ?? '');
          const changes = Array.isArray(res?.changes) ? res.changes : [];
          const rewritten = res?.rewritten === true;
          const changed = value !== '' && htmlText(value) !== htmlText(sections[i].html);
          if (reviewRef.current?.[i]?.status !== 'pending') continue; // user already finished — drop the result
          if (changed) {
            applySection(i, diffBlocksHtml(sections[i].html, value, { consolidated: rewritten }) + sections[i].imgs.join(''));
          }
          // Baseline = the landed view read back from the editor (surgery is
          // synchronous) — effectiveContent's untouched-detector.
          const baseline = changed ? splitReviewSections(editor.getHTML())[String(i)]?.html : undefined;
          setReview((cur) => cur?.map((s, k) => (k === i && s.status === 'pending'
            ? (changed ? { ...s, status: 'diff' as ReviewStatus, ai: value, genModel, baseline, changes, rewritten, kept: changes.map(() => true) } : { ...s, status: 'clean' as ReviewStatus })
            : s)) ?? cur);
        } catch (e: any) {
          setReview((cur) => cur?.map((s, k) => (k === i && s.status === 'pending'
            ? { ...s, status: 'failed' as ReviewStatus, error: e?.message ?? 'AI failed on this section' }
            : s)) ?? cur);
        }
      }
    };
    await Promise.all(Array.from({ length: Math.min(4, sections.length) }, worker));
  };

  /** QUICK OPTIMIZE (gap eeec6b9 D1) — the main click, exactly the
   *  behavior the owner likes: the action matrix decides the shape, and a
   *  TYPED instruction makes the run strict (a custom order is surgical —
   *  change only what it says). */
  const runQuickOptimize = () => {
    if (tickedKw.length > 0) {
      runKeywordInsert();
      return;
    }
    void startAiReview(
      instruction.trim(),
      hasSelection && editor ? { from: editor.state.selection.from, to: editor.state.selection.to } : null,
      undefined,
      instruction.trim() !== '' ? { strict: true } : undefined,
    );
  };

  /** THE INJECTION RUN (owner spec d135e3c + the action matrix 2026-07-15):
   *  weave EXACTLY the ticked keywords into the existing content per THE
   *  HIERARCHY LAW — the frequent light touch, red/green like every run.
   *  A text selection narrows it (the matrix); the generic ride is
   *  suppressed because this topic IS the keyword order. */
  const runKeywordInsert = () => {
    const byRole = (role: TickedKeyword['role']): string[] =>
      tickedKw.filter((t) => t.role === role).map((t) => t.kw);
    const lines = ([
      ['primary', "PRIMARY — thread straight through the page (headings + body, the page's spine)"],
      ['supporting', 'SUPPORTING — present in some headers and some text (structural, not everywhere)'],
      ['additional', 'ADDITIONAL — light touch, mentioned naturally, roughly ONE paragraph each, never more'],
    ] as const).flatMap(([role, law]) => {
      const kws = byRole(role);
      return kws.length > 0 ? [`${law}: ${kws.join(', ')}`] : [];
    });
    const topic = 'Weave the following keywords into the existing content — keep the page\'s structure '
      + 'and message, no full rework. Placement follows each keyword\'s ROLE; natural inclusion always, '
      + `keyword stuffing never.\n${lines.join('\n')}`;
    void startAiReview(
      topic,
      hasSelection && editor ? { from: editor.state.selection.from, to: editor.state.selection.to } : null,
      [{ text: `Insert the selected keywords by role: ${tickedKw.map((t) => t.kw).join(', ')}`, purposes: ['keywords'], sources: [0] }],
      { suppressKeywordRide: true },
    );
  };

  /** THE single decision path (chips, rail rows, Accept all, OK — all of
   *  them). Accept = the oracle's answer (untouched → the AI's clean
   *  formatted HTML; edited → the user's live words, marks stripped) with
   *  the lane identity restated — a changed section reads amber the moment
   *  it's accepted. Reject = the original back, byte-identical. */
  const resolveSection = (i: number, action: 'accept' | 'reject') => {
    const s = reviewRef.current?.[i];
    if (!s || s.status !== 'diff') return;
    if (sectionRange(i) === null) {
      // The user deleted the section (its anchor is gone): resolve honestly
      // — never write to a guessed range.
      toast.info('That section no longer exists in the document — nothing to apply.');
      setReview((cur) => cur?.map((x, k) => (k === i && x.status === 'diff'
        ? { ...x, status: 'rejected' as ReviewStatus }
        : x)) ?? cur);
      return;
    }
    const { html: kept, imgs } = effectiveContent(i);
    if (action === 'accept') {
      applySection(i, restateOrigin(kept, s.html, htmlText(kept) !== htmlText(s.html)) + imgs);
    } else {
      applySection(i, s.html + imgs);
    }
    setReview((cur) => cur?.map((x, k) => (k === i && x.status === 'diff'
      ? { ...x, status: (action === 'accept' ? 'accepted' : 'rejected') as ReviewStatus }
      : x)) ?? cur);
    // A resolve unmounts the hovered card — the quote highlight must not
    // linger (T1 flaw, gap e1eb677 A4).
    if (editor) {
      (editor.storage as any).pcmSectionBlocks.quote = null;
      editor.view.dispatch(editor.state.tr);
    }
  };
  const acceptAllDiffs = () =>
    (reviewRef.current ?? []).forEach((s, i) => { if (s.status === 'diff') resolveSection(i, 'accept'); });
  /** OK — finish the review NOW: decisions already made stay, every undecided
   *  section keeps its original (still-generating ones too — late results are
   *  dropped by the workers' status guard). */
  const finishReview = () => {
    (reviewRef.current ?? []).forEach((s, i) => { if (s.status === 'diff') resolveSection(i, 'reject'); });
    setReview((cur) => cur?.map((s) => (s.status === 'pending' ? { ...s, status: 'rejected' as ReviewStatus } : s)) ?? cur);
    // THE RESULTS LOOP stamp (gap e8fcae5 D5): a review that ends with at
    // least one ACCEPTED section = an optimization event — the before/after
    // measurement anchors here. Failure is stated, never silent.
    const accepted = (reviewRef.current ?? []).filter((s) => s.status === 'accepted').length;
    if (accepted > 0 && typeof siteId === 'number') {
      historyStampMutation
        .mutateAsync({ siteId, postId, purposes: Array.from(new Set((runDirectives ?? []).flatMap((d) => d.purposes))) })
        .catch((e: unknown) => toast.error(`The optimization was applied but could not be logged for results tracking — ${e instanceof Error ? e.message : 'save failed'}`));
    }
  };

  // ── REVISE (owner F2): send a section BACK to the AI with an adjustment
  //    note. The AI receives the ORIGINAL (as the optimize target), the
  //    CURRENT draft — including the user's manual edits — and the note.
  //    Revise-all runs the same path for every still-undecided section. ──
  const [reviseTarget, setReviseTarget] = useState<number | 'all' | null>(null);
  const [reviseNote, setReviseNote] = useState('');
  const reviseSection = async (i: number, note: string) => {
    const s = reviewRef.current?.[i];
    if (!editor || !s || s.status !== 'diff') return;
    if (sectionRange(i) === null) {
      toast.info('That section no longer exists in the document — nothing to revise.');
      setReview((cur) => cur?.map((x, k) => (k === i && x.status === 'diff'
        ? { ...x, status: 'rejected' as ReviewStatus }
        : x)) ?? cur);
      return;
    }
    // The draft the AI builds on = the SAME oracle Accept uses: clean
    // formatted for untouched sections, the user's words for edited ones.
    const draft = effectiveContent(i).html;
    setReview((cur) => cur?.map((x, k) => (k === i ? { ...x, status: 'pending' as ReviewStatus } : x)) ?? cur);
    try {
      // REVISE FIDELITY (gap e8fcae5 D3): the note and the draft travel
      // SEPARATELY — the server enforces the human-editor contract and a
      // retention check against the draft (a targeted note may never
      // silently rewrite the whole text).
      const res: any = await optimizeMutation.mutateAsync({
        siteId: siteId as number, postId, type,
        html: s.html,
        topic: note + LANGUAGE_LAW,
        draft,
        model: aiPick?.id ?? model, provider: aiPick?.provider ?? provider,
        reportChanges: true,
        purposes: Array.from(new Set((runDirectives ?? []).flatMap((d) => d.purposes))),
      });
      const value = canonicalAiHtml(editor, String(res?.value ?? '').trim());
      const genModel = String(res?.model ?? '');
      const changes = Array.isArray(res?.changes) ? res.changes : [];
      const rewritten = res?.rewritten === true;
      if (reviewRef.current?.[i]?.status !== 'pending') return; // decided meanwhile — drop
      if (value && htmlText(value) !== htmlText(s.html)) {
        applySection(i, diffBlocksHtml(s.html, value, { consolidated: rewritten }) + s.imgs.join(''));
        // Every landing re-arms the untouched-detector (initial + each revise).
        const baseline = splitReviewSections(editor.getHTML())[String(i)]?.html;
        setReview((cur) => cur?.map((x, k) => (k === i ? { ...x, status: 'diff' as ReviewStatus, ai: value, genModel, baseline, changes, rewritten, kept: changes.map(() => true) } : x)) ?? cur);
      } else {
        applySection(i, s.html + s.imgs.join(''));
        setReview((cur) => cur?.map((x, k) => (k === i ? { ...x, status: 'clean' as ReviewStatus } : x)) ?? cur);
      }
    } catch (e: any) {
      // The draft stays on screen — only the status returns to reviewable.
      setReview((cur) => cur?.map((x, k) => (k === i && x.status === 'pending' ? { ...x, status: 'diff' as ReviewStatus } : x)) ?? cur);
      toast.error(e?.message ?? 'Revise failed — the current draft was kept.');
    }
  };
  const reviseAll = async (note: string) => {
    const targets = (reviewRef.current ?? []).map((s, i) => (s.status === 'diff' ? i : -1)).filter((i) => i >= 0);
    let next = 0;
    const worker = async () => {
      while (next < targets.length) {
        await reviseSection(targets[next++], note);
      }
    };
    await Promise.all(Array.from({ length: Math.min(4, targets.length) }, worker));
  };
  const runRevise = () => {
    const note = reviseNote.trim();
    const target = reviseTarget;
    if (!note || target === null) return;
    setReviseTarget(null);
    setReviseNote('');
    if (target === 'all') void reviseAll(note);
    else void reviseSection(target, note);
  };

  // ── Inline chips + focus flash (owner-picked combo 2026-07-13): the chips
  //    ride the SAME resolveSection as the rail (one machinery); clicking a
  //    rail card scrolls to its section and pulses it for ~2s. ──
  const [flashIdx, setFlashIdx] = useState<number | null>(null);
  useEffect(() => {
    if (!editor || !isPage) return;
    (editor.storage as any).pcmReviewControls.sections = review?.map((s) => s.status) ?? [];
    (editor.storage as any).pcmReviewControls.resolve = resolveSection;
    (editor.storage as any).pcmReviewControls.revise = (i: number) => { setReviseTarget(i); setReviseNote(''); };
    editor.view.dispatch(editor.state.tr); // refresh widget decorations
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editor, isPage, review]);
  useEffect(() => {
    if (!editor || !isPage) return;
    (editor.storage as any).pcmSectionBlocks.flash = flashIdx;
    editor.view.dispatch(editor.state.tr); // refresh block decorations
  }, [editor, isPage, flashIdx]);
  const focusSection = (i: number) => {
    if (!editor) return;
    // Locate by ANCHOR (identity law), then translate to the heading's
    // CURRENT ordinal — the flash decoration indexes every heading.
    const id = String(i);
    let target: number | null = null;
    let ordinal = -1;
    let flashOrdinal: number | null = null;
    editor.state.doc.forEach((node, pos) => {
      if (node.type.name !== 'heading') return;
      ordinal++;
      if (target === null && (((node.attrs['data-pcm-review-id'] as string | null) ?? null) === id)) {
        target = pos;
        flashOrdinal = ordinal;
      }
    });
    if (target === null || flashOrdinal === null) return;
    (editor.view.nodeDOM(target) as HTMLElement | null)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const f = flashOrdinal;
    setFlashIdx(f);
    window.setTimeout(() => setFlashIdx((cur) => (cur === f ? null : cur)), 2000);
  };

  // ── Quote highlight (gap 0a0a3c3): hovering a change card lights the
  //    exact words that change produced — claim-to-text verification.
  //    Char-accurate normalized search inside the section's range; a quote
  //    the user has since edited away falls back to the section flash —
  //    honest, never a fake highlight. ──
  const setQuoteRange = (range: { from: number; to: number } | null) => {
    if (!editor) return;
    (editor.storage as any).pcmSectionBlocks.quote = range;
    editor.view.dispatch(editor.state.tr); // refresh decorations
  };
  const highlightQuote = (i: number, quote: string) => {
    if (!editor) return;
    const range = sectionRange(i);
    if (!range) return;
    // Build the section's text with a position for every character, then
    // search whitespace-collapsed + case-folded — exact mapping back.
    const chars: Array<{ ch: string; pos: number }> = [];
    editor.state.doc.nodesBetween(range.from, range.to, (node, pos) => {
      if (!node.isText || !node.text) return;
      for (let k = 0; k < node.text.length; k++) chars.push({ ch: node.text[k], pos: pos + k });
    });
    const kept: Array<{ ch: string; pos: number }> = [];
    for (const c of chars) {
      if (/\s/.test(c.ch)) {
        if (kept.length > 0 && kept[kept.length - 1].ch === ' ') continue;
        kept.push({ ch: ' ', pos: c.pos });
      } else {
        kept.push({ ch: c.ch.toLowerCase(), pos: c.pos });
      }
    }
    const haystack = kept.map((c) => c.ch).join('');
    const needle = quote.toLowerCase().replace(/\s+/g, ' ').trim();
    const at = needle ? haystack.indexOf(needle) : -1;
    if (at < 0) {
      focusSection(i); // quote edited away — flash the section instead
      return;
    }
    const from = kept[at].pos;
    const last = kept[at + needle.length - 1];
    setQuoteRange({ from, to: last.pos + 1 });
    const dom: globalThis.Node | null = editor.view.domAtPos(from).node;
    ((dom instanceof HTMLElement ? dom : dom?.parentElement) ?? null)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  // Completion watcher (the surgery engine replaced the old whole-doc
  // rebuilder here — the doc is already correct at every moment): keep the
  // workers' live mirror in sync and close the review when every section is
  // resolved. Editing was never locked, so nothing to unlock.
  useEffect(() => {
    reviewRef.current = review;
    if (!editor || !review) return;
    if (review.every((s) => s.status !== 'pending' && s.status !== 'diff')) {
      // The anchors die WITH the review (one transaction) — a save can never
      // carry them; the server strip is only the belt.
      const tr = editor.state.tr;
      let stamped = false;
      editor.state.doc.forEach((node, pos) => {
        if (node.type.name === 'heading' && node.attrs['data-pcm-review-id'] != null) {
          tr.setNodeMarkup(pos, undefined, { ...node.attrs, 'data-pcm-review-id': null });
          stamped = true;
        }
      });
      if (stamped) editor.view.dispatch(tr);
      const accepted = review.filter((s) => s.status === 'accepted').length;
      setReview(null);
      // THE LEAK FIX (blueprint §10 fix 4): the run's order dies WITH its
      // review — no later run can wear a stale super-optimize identity.
      setRunDirectives(null);
      if (accepted > 0) toast.success(`AI review done — ${accepted} section${accepted === 1 ? '' : 's'} updated. Press Save to make it live.`);
      else if (review.some((s) => s.status === 'rejected' || s.status === 'failed')) toast.info('AI review closed — nothing was changed.');
      else toast.info('The page already looks optimized.');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [review]);

  return {
    review, setReview, runDirectives, teacherById,
    reviseTarget, setReviseTarget, reviseNote, setReviseNote,
    runAi, startAiReview, runQuickOptimize, runKeywordInsert,
    resolveSection, acceptAllDiffs, finishReview,
    reviseSection, runRevise, effectiveContent,
    focusSection, highlightQuote, setQuoteRange,
  };
}
