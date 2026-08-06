import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { Editor } from '@tiptap/react';
import { FloatingMenu, type FloatingMenuProps } from '@tiptap/react/menus';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import type { LucideIcon } from 'lucide-react';
import {
  Brush,
  Heading1,
  Heading2,
  Heading3,
  Heading4,
  Image as ImageIcon,
  List,
  ListChecks,
  ListOrdered,
  Minus,
  PenLine,
  Quote,
  Type,
} from 'lucide-react';

import { useApprovalEditorMenuPortal } from './useApprovalEditorMenuPortal';

interface ApprovalSlashMenuProps {
  editor: Editor;
  onInsertImage: () => void;
  onAnnotateImage: () => void;
  onStartDrawing: () => void;
}

interface SlashMatch {
  from: number;
  to: number;
  query: string;
}

interface SlashCommand {
  id: string;
  label: string;
  description: string;
  group: 'Basic blocks' | 'Media';
  keywords: string[];
  icon: LucideIcon;
  run: (match: SlashMatch) => void;
}

function filterSlashCommands(commands: SlashCommand[], query: string): SlashCommand[] {
  if (!query) return commands;
  return commands.filter((command) => (
    [command.label, command.description, ...command.keywords]
      .join(' ')
      .toLocaleLowerCase()
      .includes(query)
  ));
}

/**
 * Slash commands are deliberately constrained to a slash at the beginning of
 * the current text block. This prevents ordinary URLs and prose such as
 * “and/or” from opening editor chrome.
 */
function findSlashMatch(editor: Editor): SlashMatch | null {
  const { selection } = editor.state;
  const { $from, empty } = selection;
  if (!empty || !$from.parent.isTextblock) return null;

  // A command query only owns the block while the caret is at its end. Moving
  // back into existing prose must never let a command delete trailing content.
  if ($from.parentOffset !== $from.parent.content.size) return null;

  const textBeforeCaret = $from.parent.textBetween(0, $from.parentOffset, '\0', '\0');
  const match = /^\/([^\s/]*)$/.exec(textBeforeCaret);
  if (!match) return null;

  return {
    from: $from.start(),
    to: $from.pos,
    query: match[1].toLocaleLowerCase(),
  };
}

export function ApprovalSlashMenu({
  editor,
  onInsertImage,
  onAnnotateImage,
  onStartDrawing,
}: ApprovalSlashMenuProps) {
  const { appendTo, scrollTarget } = useApprovalEditorMenuPortal(editor);
  const [query, setQuery] = useState('');
  const [selectedIndex, setSelectedIndex] = useState(0);
  const itemRefs = useRef<Array<HTMLButtonElement | null>>([]);

  const commands = useMemo<SlashCommand[]>(() => {
    const withDeletedQuery = (match: SlashMatch) => editor.chain().focus().deleteRange(match);

    return [
      {
        id: 'text', label: 'Text', description: 'Start writing with plain text',
        group: 'Basic blocks', keywords: ['paragraph', 'plain'], icon: Type,
        run: (match) => { withDeletedQuery(match).setParagraph().run(); },
      },
      {
        id: 'heading-1', label: 'Heading 1', description: 'Large section heading',
        group: 'Basic blocks', keywords: ['h1', 'title'], icon: Heading1,
        run: (match) => { withDeletedQuery(match).setHeading({ level: 1 }).run(); },
      },
      {
        id: 'heading-2', label: 'Heading 2', description: 'Medium section heading',
        group: 'Basic blocks', keywords: ['h2', 'subtitle'], icon: Heading2,
        run: (match) => { withDeletedQuery(match).setHeading({ level: 2 }).run(); },
      },
      {
        id: 'heading-3', label: 'Heading 3', description: 'Small section heading',
        group: 'Basic blocks', keywords: ['h3'], icon: Heading3,
        run: (match) => { withDeletedQuery(match).setHeading({ level: 3 }).run(); },
      },
      {
        id: 'heading-4', label: 'Heading 4', description: 'Compact section heading',
        group: 'Basic blocks', keywords: ['h4'], icon: Heading4,
        run: (match) => { withDeletedQuery(match).setHeading({ level: 4 }).run(); },
      },
      {
        id: 'bullet-list', label: 'Bullet list', description: 'Create a simple bulleted list',
        group: 'Basic blocks', keywords: ['unordered', 'ul'], icon: List,
        run: (match) => { withDeletedQuery(match).toggleBulletList().run(); },
      },
      {
        id: 'numbered-list', label: 'Numbered list', description: 'Create a numbered list',
        group: 'Basic blocks', keywords: ['ordered', 'ol'], icon: ListOrdered,
        run: (match) => { withDeletedQuery(match).toggleOrderedList().run(); },
      },
      {
        id: 'checklist', label: 'Checklist', description: 'Track actionable items',
        group: 'Basic blocks', keywords: ['task', 'todo', 'checkbox'], icon: ListChecks,
        run: (match) => { withDeletedQuery(match).toggleTaskList().run(); },
      },
      {
        id: 'quote', label: 'Quote', description: 'Capture a quotation',
        group: 'Basic blocks', keywords: ['blockquote'], icon: Quote,
        run: (match) => { withDeletedQuery(match).toggleBlockquote().run(); },
      },
      {
        id: 'divider', label: 'Divider', description: 'Separate sections visually',
        group: 'Basic blocks', keywords: ['line', 'horizontal rule', 'hr'], icon: Minus,
        run: (match) => { withDeletedQuery(match).setHorizontalRule().run(); },
      },
      {
        id: 'image', label: 'Image', description: 'Upload or select images',
        group: 'Media', keywords: ['photo', 'picture', 'media'], icon: ImageIcon,
        run: (match) => { withDeletedQuery(match).run(); onInsertImage(); },
      },
      {
        id: 'annotate-image', label: 'Annotate image', description: 'Paint directly on an image',
        group: 'Media', keywords: ['draw', 'paint', 'mark up'], icon: PenLine,
        run: (match) => { withDeletedQuery(match).run(); onAnnotateImage(); },
      },
      {
        id: 'draw-card', label: 'Draw on card', description: 'Add a drawing layer over this card',
        group: 'Media', keywords: ['brush', 'paint', 'sketch'], icon: Brush,
        run: (match) => { withDeletedQuery(match).run(); onStartDrawing(); },
      },
    ];
  }, [editor, onAnnotateImage, onInsertImage, onStartDrawing]);

  const filteredCommands = useMemo(
    () => filterSlashCommands(commands, query),
    [commands, query],
  );

  const refresh = useCallback(() => {
    const match = findSlashMatch(editor);
    setQuery(match?.query ?? '');
  }, [editor]);

  useEffect(() => {
    refresh();
    editor.on('transaction', refresh);
    return () => { editor.off('transaction', refresh); };
  }, [editor, refresh]);

  useEffect(() => { setSelectedIndex(0); }, [query]);
  useEffect(() => {
    itemRefs.current[selectedIndex]?.scrollIntoView({ block: 'nearest' });
  }, [selectedIndex]);

  const runCommand = useCallback((command: SlashCommand) => {
    const match = findSlashMatch(editor);
    if (match) command.run(match);
  }, [editor]);

  // Keep the keyboard path inside ProseMirror's own event pipeline. A document-
  // level listener races the editor's Enter keymap and can fall through to a
  // normal paragraph split even while the slash palette is visibly open.
  const commandsRef = useRef(commands);
  const selectedIndexRef = useRef(selectedIndex);
  commandsRef.current = commands;
  selectedIndexRef.current = selectedIndex;

  useEffect(() => {
    const keyboardPluginKey = new PluginKey('approvalSlashCommandsKeyboard');
    const keyboardPlugin = new Plugin({
      key: keyboardPluginKey,
      props: {
        handleKeyDown: (_view, event) => {
          const match = findSlashMatch(editor);
          if (!match || event.isComposing) return false;

          const matchingCommands = filterSlashCommands(commandsRef.current, match.query);
          const safeIndex = matchingCommands.length
            ? Math.min(selectedIndexRef.current, matchingCommands.length - 1)
            : 0;

          if (event.key === 'ArrowDown') {
            setSelectedIndex((safeIndex + 1) % Math.max(matchingCommands.length, 1));
            return true;
          }
          if (event.key === 'ArrowUp') {
            setSelectedIndex(
              (safeIndex - 1 + Math.max(matchingCommands.length, 1))
                % Math.max(matchingCommands.length, 1),
            );
            return true;
          }
          if (event.key === 'Enter' && matchingCommands[safeIndex]) {
            matchingCommands[safeIndex].run(match);
            return true;
          }
          if (event.key === 'Escape') {
            // Keep the user's query as normal text while removing the trigger.
            editor.chain().focus().deleteRange({ from: match.from, to: match.from + 1 }).run();
            return true;
          }
          return false;
        },
      },
    });

    // Slash navigation must run before the editor's ordinary Enter/keymap
    // handlers whenever the slash query owns the current text block.
    editor.registerPlugin(keyboardPlugin, (plugin, plugins) => [plugin, ...plugins]);
    return () => { editor.unregisterPlugin(keyboardPluginKey); };
  }, [editor]);

  const shouldShow = useCallback(() => Boolean(findSlashMatch(editor)), [editor]);

  const floatingOptions = useMemo<FloatingMenuProps['options']>(() => ({
    strategy: 'fixed',
    placement: 'bottom-start',
    offset: 8,
    flip: { padding: 12 },
    shift: { padding: 12 },
    scrollTarget,
  }), [scrollTarget]);

  return (
    <FloatingMenu
      editor={editor}
      pluginKey="approvalSlashCommands"
      shouldShow={shouldShow}
      appendTo={appendTo}
      options={floatingOptions}
      className="pcm-slash-menu"
      role="listbox"
      aria-label="Insert a block"
    >
      <div className="pcm-slash-menu__header">Add a block</div>
      <div className="pcm-slash-menu__items">
        {filteredCommands.length === 0 ? (
          <div className="pcm-slash-menu__empty">No matching blocks</div>
        ) : filteredCommands.map((command, index) => {
          const Icon = command.icon;
          const showGroup = index === 0 || filteredCommands[index - 1].group !== command.group;
          return (
            <div key={command.id}>
              {showGroup && <div className="pcm-slash-menu__group">{command.group}</div>}
              <button
                type="button"
                role="option"
                aria-selected={index === selectedIndex}
                className={`pcm-slash-menu__item${index === selectedIndex ? ' is-selected' : ''}`}
                ref={(node) => { itemRefs.current[index] = node; }}
                onMouseEnter={() => setSelectedIndex(index)}
                onMouseDown={(event) => {
                  event.preventDefault();
                  runCommand(command);
                }}
              >
                <span className="pcm-slash-menu__icon"><Icon aria-hidden /></span>
                <span className="pcm-slash-menu__copy">
                  <span className="pcm-slash-menu__label">{command.label}</span>
                  <span className="pcm-slash-menu__description">{command.description}</span>
                </span>
              </button>
            </div>
          );
        })}
      </div>
    </FloatingMenu>
  );
}
