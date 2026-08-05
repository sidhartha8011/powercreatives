/**
 * SlashVariableMenu — type "/" in a template Value textarea to pick a variable.
 *
 * Same shape as a slash-command palette: "/" opens the list, further typing
 * filters it, ↑/↓ move, Enter or Tab inserts, Esc dismisses. The typed "/query"
 * is REPLACED by the token, so nothing has to be cleaned up by hand.
 *
 * Vocabulary comes from templateVarsFor() — the same source the chips use — so
 * the two can never drift apart.
 *
 * POSITIONING — the list floats directly under the "/" that opened it.
 * Two constraints shape how:
 *   - The inline editor lives in `<TableCell className="max-w-0 overflow-hidden">`,
 *     so an absolutely-positioned child would be CLIPPED by the cell. The menu is
 *     therefore portalled to <body> and positioned `fixed`, escaping every
 *     ancestor's overflow.
 *   - A textarea exposes no caret geometry, so the "/" offset is measured with a
 *     hidden mirror div that copies the textarea's text-layout styles — the
 *     standard technique, kept local rather than pulling in a dependency.
 * Being a plain portalled div (not a Radix Popover) it never steals focus, which
 * a typeahead cannot afford.
 *
 * "/" is a legitimate character in prompt text, so the trigger only fires at the
 * start of a line or after whitespace, and closes as soon as the query contains
 * whitespace. Typing "and/or" never opens the menu.
 */

import { useCallback, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';
import type { TemplateVar } from './templateVars';

/** Menu box, used for viewport clamping before the real height is known. */
const MENU_WIDTH = 460;
const MENU_MAX_HEIGHT = 200;
const VIEWPORT_PAD = 8;

/**
 * Pixel offset of a character index inside a textarea, relative to its padding box.
 *
 * A textarea gives no way to ask "where is index N on screen", so the text is
 * re-laid-out in an off-screen div carrying the same font/padding/width/wrapping,
 * and the position of a marker span at that index is read back.
 */
function caretOffset(ta: HTMLTextAreaElement, index: number): { left: number; top: number; lineHeight: number } {
  const cs = window.getComputedStyle(ta);
  const mirror = document.createElement('div');

  // Only the properties that affect where a glyph lands.
  const copy = [
    'boxSizing', 'width', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
    'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
    'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'letterSpacing',
    'lineHeight', 'textTransform', 'textIndent', 'tabSize',
  ] as const;
  for (const prop of copy) {
    mirror.style[prop as any] = cs[prop as any];
  }
  mirror.style.position = 'absolute';
  mirror.style.visibility = 'hidden';
  mirror.style.top = '0';
  mirror.style.left = '-9999px';
  mirror.style.whiteSpace = 'pre-wrap';
  mirror.style.wordWrap = 'break-word';
  mirror.style.overflow = 'hidden';

  mirror.textContent = ta.value.slice(0, index);
  const marker = document.createElement('span');
  // A non-empty marker so it is laid out; the following char keeps wrapping honest.
  marker.textContent = ta.value.slice(index) || '.';
  mirror.appendChild(marker);

  document.body.appendChild(mirror);
  const left = marker.offsetLeft;
  const top = marker.offsetTop;
  const lineHeight = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.2 || 16;
  document.body.removeChild(mirror);

  return { left, top, lineHeight };
}

/** `{{ post_title }}` / `{{business.name}}` → `post_title` / `business.name`. */
function tokenName(token: string): string {
  return token.replace(/[{}]/g, '').trim().toLowerCase();
}

export interface SlashVariables {
  /** Whether the menu is currently showing. */
  open: boolean;
  /** Variables matching what has been typed after "/". */
  matches: TemplateVar[];
  /** Index of the highlighted match. */
  active: number;
  /** Wire to the textarea's onChange — updates the value AND the trigger state. */
  onChange: (e: React.ChangeEvent<HTMLTextAreaElement>) => void;
  /** Wire to the textarea's onKeyDown. Returns true when it consumed the key. */
  onKeyDown: (e: React.KeyboardEvent<HTMLTextAreaElement>) => boolean;
  /** Insert a token, replacing the typed "/query". */
  insert: (token: string) => void;
  setActive: (i: number) => void;
  /** The textarea, so the menu can measure where the "/" sits. */
  textareaRef: React.RefObject<HTMLTextAreaElement | null>;
  /** Index of the triggering "/" — the anchor the menu hangs from. */
  start: number;
}

interface UseSlashVariablesArgs {
  /** Variables on offer (empty disables the feature entirely). */
  vars: TemplateVar[];
  /** Current textarea value. */
  value: string;
  /** Setter for the textarea value. */
  setValue: (next: string) => void;
  textareaRef: React.RefObject<HTMLTextAreaElement | null>;
}

export function useSlashVariables({
  vars,
  value,
  setValue,
  textareaRef,
}: UseSlashVariablesArgs): SlashVariables {
  // null = closed. '' = "/" typed with nothing after it yet.
  const [query, setQuery] = useState<string | null>(null);
  // Index of the triggering "/" so insert() knows what to replace.
  const [start, setStart] = useState(0);
  const [active, setActive] = useState(0);

  const matches = useMemo(() => {
    if (query === null || vars.length === 0) return [];
    const q = query.toLowerCase();
    // Match the token NAME only. Descriptions are prose and would make short
    // queries match almost everything.
    return q === '' ? vars : vars.filter((v) => tokenName(v.token).includes(q));
  }, [vars, query]);

  const open = query !== null && matches.length > 0;

  const close = useCallback(() => setQuery(null), []);

  const onChange = useCallback(
    (e: React.ChangeEvent<HTMLTextAreaElement>) => {
      const next = e.target.value;
      setValue(next);

      if (vars.length === 0) return;

      const caret = e.target.selectionStart ?? next.length;
      const upto = next.slice(0, caret);
      const slash = upto.lastIndexOf('/');

      if (slash === -1) {
        close();
        return;
      }

      const prev = slash === 0 ? '' : upto[slash - 1];
      const typed = upto.slice(slash + 1);

      // Start of line / after whitespace only, and abandon once the query runs
      // into whitespace — otherwise "and/or" or a URL would open the menu.
      if ((slash === 0 || /\s/.test(prev)) && !/\s/.test(typed)) {
        setStart(slash);
        setQuery(typed);
        setActive(0);
      } else {
        close();
      }
    },
    [vars.length, setValue, close],
  );

  const insert = useCallback(
    (token: string) => {
      const ta = textareaRef.current;
      const caret = ta?.selectionStart ?? value.length;
      const next = value.slice(0, start) + token + value.slice(caret);
      setValue(next);
      close();

      // Restore the caret after React has committed the new value, otherwise the
      // browser drops it to the end of the textarea.
      const pos = start + token.length;
      requestAnimationFrame(() => {
        ta?.focus();
        ta?.setSelectionRange(pos, pos);
      });
    },
    [textareaRef, value, start, setValue, close],
  );

  const onKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLTextAreaElement>): boolean => {
      if (!open) return false;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setActive((i) => (i + 1) % matches.length);
        return true;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        setActive((i) => (i - 1 + matches.length) % matches.length);
        return true;
      }
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        insert((matches[active] ?? matches[0]).token);
        return true;
      }
      if (e.key === 'Escape') {
        // Consume it: the caller's Escape cancels the whole edit, and dismissing
        // the menu must not also throw away what the author has written.
        e.preventDefault();
        e.stopPropagation();
        close();
        return true;
      }
      return false;
    },
    [open, matches, active, insert, close],
  );

  return { open, matches, active, onChange, onKeyDown, insert, setActive, textareaRef, start };
}

export function SlashVariableMenu({ state }: { state: SlashVariables }) {
  const { open, textareaRef, start } = state;
  const boxRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const [pos, setPos] = useState<{ left: number; top: number } | null>(null);

  // Measure before paint so the menu never shows at the wrong spot for a frame.
  useLayoutEffect(() => {
    if (!open) { setPos(null); return; }
    const ta = textareaRef.current;
    if (!ta) return;

    const place = () => {
      const rect = ta.getBoundingClientRect();
      const { left, top, lineHeight } = caretOffset(ta, start);

      // Viewport coords of the "/" itself, allowing for a scrolled textarea.
      const slashX = rect.left + left - ta.scrollLeft;
      const slashY = rect.top + top - ta.scrollTop;

      const height = boxRef.current?.offsetHeight ?? MENU_MAX_HEIGHT;
      const width = boxRef.current?.offsetWidth ?? MENU_WIDTH;

      // Default: hang just under the "/" line. Flip above when there is no room.
      let y = slashY + lineHeight + 2;
      if (y + height > window.innerHeight - VIEWPORT_PAD) {
        const above = slashY - height - 2;
        if (above > VIEWPORT_PAD) y = above;
        else y = Math.max(VIEWPORT_PAD, window.innerHeight - height - VIEWPORT_PAD);
      }

      const x = Math.max(
        VIEWPORT_PAD,
        Math.min(slashX, window.innerWidth - width - VIEWPORT_PAD),
      );

      setPos({ left: x, top: y });
    };

    place();
    // Any scroll (the page, the table, an ancestor) moves the textarea under the
    // menu, so re-measure on capture rather than letting the two drift apart.
    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', place);
    return () => {
      window.removeEventListener('scroll', place, true);
      window.removeEventListener('resize', place);
    };
  }, [open, start, textareaRef, state.matches.length]);

  // Keep the highlighted row visible. The list is capped at max-h-40 and scrolls,
  // but ↑/↓ only move an index — without this the selection walks off the bottom
  // and the menu looks frozen on the last visible item.
  // 'nearest' scrolls the minimum needed and, critically, does NOT scroll the page
  // or the table behind the portalled menu.
  useLayoutEffect(() => {
    if (!open) return;
    const item = listRef.current?.children[state.active] as HTMLElement | undefined;
    item?.scrollIntoView({ block: 'nearest' });
  }, [open, state.active]);

  if (!open) return null;

  return createPortal(
    <div
      ref={boxRef}
      // Fixed + portalled: the editor sits in an overflow-hidden table cell that
      // would otherwise clip this. z-50 clears the dialog/table stacking contexts.
      style={{
        position: 'fixed',
        left: pos?.left ?? -9999,
        top: pos?.top ?? -9999,
        width: MENU_WIDTH,
        visibility: pos ? 'visible' : 'hidden',
      }}
      className="z-50 rounded-md border border-border bg-popover shadow-md"
    >
      <p className="border-b border-border px-2 py-1 text-[10px] text-muted-foreground">
        ↑↓ to move · Enter to insert · Esc to dismiss
      </p>
      {/* overflow-auto (not just -y): descriptions are a full sentence and are
          kept on ONE line, so a long one is read by scrolling sideways rather
          than by wrapping every row to three lines and burying the list.
          w-max min-w-full on the inner track makes every row as wide as the
          widest one, so the highlight is a clean full-width bar instead of a
          ragged edge. */}
      <div className="max-h-40 overflow-auto">
        <ul ref={listRef} className="w-max min-w-full py-0.5">
          {state.matches.map(({ token, description }, i) => (
            <li key={token}>
            <button
              type="button"
              // Keep focus in the textarea: a blur would move the caret and the
              // insert would land in the wrong place.
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => state.insert(token)}
              onMouseEnter={() => state.setActive(i)}
              title={description}
              className={cn(
                'flex w-full items-baseline gap-2 whitespace-nowrap px-2 py-1 text-left transition-colors',
                i === state.active
                  ? 'bg-accent text-accent-foreground'
                  : 'text-muted-foreground hover:bg-muted',
              )}
            >
              <span className="font-mono text-[11px]">{token}</span>
              <span
                className={cn(
                  'text-[10px]',
                  i === state.active ? 'text-accent-foreground/80' : 'text-muted-foreground/70',
                )}
              >
                {description}
              </span>
            </button>
            </li>
          ))}
        </ul>
      </div>
    </div>,
    document.body,
  );
}
