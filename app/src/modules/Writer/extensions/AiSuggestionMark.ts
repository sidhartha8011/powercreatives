/**
 * WRITER MODULE — AI Suggestion Mark
 *
 * Native Tiptap Mark for "Track Changes" inline diff previews.
 * Renders text as either 'insertion' (green) or 'deletion' (red strikethrough).
 *
 * Styling is handled entirely by CSS classes in index.css:
 *   .ai-diff-deletion  — red background + strikethrough
 *   .ai-diff-insertion  — green background + underline
 *
 * No inline styles — all visual tokens come from CSS custom properties
 * (--ai-diff-deletion-bg, --ai-diff-insertion-fg, etc.) for theming.
 *
 * @package PowerCreatives
 * @module  Writer
 */
import { Mark, mergeAttributes } from '@tiptap/core';

export interface AiSuggestionOptions {
  HTMLAttributes: Record<string, any>;
}

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    aiSuggestion: {
      /**
       * Wrap the current selection with an AI Suggestion mark (diff preview).
       * @param attributes.id - Unique identifier linking this mark to its AiDiffInstruction
       * @param attributes.type - 'insertion' for new text, 'deletion' for removed text
       */
      setAiSuggestion: (attributes: { id: string; type: 'insertion' | 'deletion' }) => ReturnType;

      /**
       * Remove AI Suggestion mark from current selection.
       * Used by Accept/Reject logic in the BubbleMenu.
       */
      unsetAiSuggestion: () => ReturnType;
    };
  }
}

export const AiSuggestionMark = Mark.create<AiSuggestionOptions>({
  name: 'aiSuggestion',

  addOptions() {
    return {
      HTMLAttributes: {},
    };
  },

  addAttributes() {
    return {
      /** Links this mark back to the originating AI diff instruction */
      id: {
        default: null,
        parseHTML: element => element.getAttribute('data-suggestion-id'),
        renderHTML: attributes => {
          if (!attributes.id) return {};
          return { 'data-suggestion-id': attributes.id };
        },
      },
      /** Visual mode: 'insertion' (green) or 'deletion' (red strikethrough) */
      type: {
        default: 'insertion',
        parseHTML: element => element.getAttribute('data-suggestion-type'),
        renderHTML: attributes => {
          if (!attributes.type) return {};
          return { 'data-suggestion-type': attributes.type };
        },
      },
    };
  },

  parseHTML() {
    return [
      {
        tag: 'span[data-suggestion-id]',
      },
    ];
  },

  renderHTML({ HTMLAttributes }) {
    const isDeletion = HTMLAttributes['data-suggestion-type'] === 'deletion';

    // CSS classes defined in index.css under #pcm-root .writer-editor
    // No inline styles — design tokens handled via CSS custom properties
    const typeClass = isDeletion ? 'ai-diff-deletion' : 'ai-diff-insertion';

    return [
      'span',
      mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
        class: `ai-suggestion ${typeClass}`,
      }),
      0,
    ];
  },

  addCommands() {
    return {
      setAiSuggestion:
        attributes =>
        ({ commands }) => {
          return commands.setMark(this.name, attributes);
        },

      unsetAiSuggestion:
        () =>
        ({ commands }) => {
          return commands.unsetMark(this.name);
        },
    };
  },
});
