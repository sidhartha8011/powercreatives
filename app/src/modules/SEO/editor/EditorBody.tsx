/**
 * THE EDITOR BODY (decomposition final squeeze, gap d0d063c): the one
 * fixed-shape editable surface — the staged-open overlay (a blank editor
 * is never silent), the honest page error, the select-text bubble
 * toolbar, and the content itself. JSX moved verbatim from
 * SectionModal.tsx.
 */

import { EditorContent } from '@tiptap/react';
import { BubbleMenu } from '@tiptap/react/menus';
import type { Editor } from '@tiptap/core';
import {
  BoldIcon, Heading1, Heading2, ItalicIcon, Link as LinkIcon, List, Loader2, UnderlineIcon,
} from 'lucide-react';

import { BLOCK_STYLES, PAGE_TYPE_SCALE, TYPE_SCALE } from './layout';
import { ToolButton } from './ToolButton';

export interface EditorBodyProps {
  editor: Editor | null;
  isPage: boolean;
  readOnly: boolean;
  docLoaded: boolean;
  pageError: string | null;
  pageVersionsSettled: boolean;
}

export function EditorBody({ editor, isPage, readOnly, docLoaded, pageError, pageVersionsSettled }: EditorBodyProps) {
  return (
    <div
      className={`${isPage ? 'min-h-0 flex-1 px-8 py-4' : 'h-[280px] px-3 py-2'} overflow-auto bg-white`}
      title={readOnly ? 'Read-only here — section editing runs via dynamic rules on connected sites.' : undefined}
    >
      {/* THE STAGED OPEN OVERLAY (gap 02d3cb7 D2): every pre-content phase
          SAYS what it is doing — a blank editor is never silent again. */}
      {isPage && !readOnly && !docLoaded && !pageError && (
        <div className="flex h-full flex-col items-center justify-center gap-2 text-xs text-slate-500">
          <Loader2 className="h-4 w-4 animate-spin text-primary" />
          {!pageVersionsSettled
            ? 'Loading your saved version…'
            : 'Pulling the page from your site — the first open takes longer while the local copy is built…'}
        </div>
      )}
      {isPage && pageError && (
        <div className="flex h-full items-center justify-center px-8 text-center text-xs text-slate-500">{pageError}</div>
      )}
      {!readOnly && editor && (
        <BubbleMenu
          editor={editor}
          shouldShow={({ state }: { state: any }) => !state.selection.empty && !state.selection.node}
          className="flex items-center gap-0.5 rounded-md border border-slate-200 bg-white p-0.5 shadow-md"
        >
          <ToolButton title="Bold" active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}><BoldIcon className="h-3.5 w-3.5" /></ToolButton>
          <ToolButton title="Italic" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}><ItalicIcon className="h-3.5 w-3.5" /></ToolButton>
          <ToolButton title="Underline" active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()}><UnderlineIcon className="h-3.5 w-3.5" /></ToolButton>
          <ToolButton
            title={editor.isActive('link') ? 'Remove link' : 'Add link'}
            active={editor.isActive('link')}
            onClick={() => {
              if (editor.isActive('link')) { editor.chain().focus().unsetLink().run(); return; }
              // eslint-disable-next-line no-alert
              const url = window.prompt('Link URL');
              if (url) editor.chain().focus().setLink({ href: url }).run();
            }}
          ><LinkIcon className="h-3.5 w-3.5" /></ToolButton>
          <div className="mx-0.5 h-4 w-px bg-slate-200" />
          <ToolButton title="Heading 1" active={editor.isActive('heading', { level: 1 })} onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}><Heading1 className="h-3.5 w-3.5" /></ToolButton>
          <ToolButton title="Heading 2" active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}><Heading2 className="h-3.5 w-3.5" /></ToolButton>
          <ToolButton title="Bullet list" active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}><List className="h-3.5 w-3.5" /></ToolButton>
        </BubbleMenu>
      )}
      {(!isPage || docLoaded) && (
        <div className={isPage ? 'mx-auto w-full max-w-[820px] px-8 pb-16 pt-6' : 'contents'}>
          <EditorContent
            editor={editor}
            className={`${isPage ? `${PAGE_TYPE_SCALE} ${BLOCK_STYLES}` : TYPE_SCALE} [&_.ProseMirror]:outline-none [&_.ProseMirror]:min-h-[250px]`
              // Locked context images: visible, clearly not editable.
              + (isPage ? ' [&_img]:my-2 [&_img]:max-w-full [&_img]:rounded [&_img[data-pcm-locked]]:cursor-not-allowed [&_img[data-pcm-locked]]:opacity-90' : '')}
          />
        </div>
      )}
    </div>
  );
}
