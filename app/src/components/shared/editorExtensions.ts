/**
 * SHARED — Tiptap Editor Extensions Registry
 *
 * Single source of truth for all Tiptap extensions used across modules.
 * Originally lived in Writer/extensions — moved to shared so both
 * Writer (editable) and Approvals (read-only viewer) can consume it.
 *
 * Architecture:
 * - StarterKit v3 provides the core + Link + Underline (configured inline).
 * - Individual extensions are added for functionality not in StarterKit.
 * - Image extension is configured as block-level (not inline) for article layouts.
 * - Link is configured via StarterKit with SEO-safe defaults (rel, target, autolink).
 * - Table is registered with all sub-nodes (Row, Cell, Header).
 * - FileHandler intercepts paste/drop of images — upload logic is injected via options.
 *
 * @package PowerCreatives
 * @module  Shared
 */

import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import Image from '@tiptap/extension-image';
import { Table, TableRow, TableCell, TableHeader } from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';
import Superscript from '@tiptap/extension-superscript';
import Subscript from '@tiptap/extension-subscript';
import Highlight from '@tiptap/extension-highlight';
import { TextStyle } from '@tiptap/extension-text-style';
import Typography from '@tiptap/extension-typography';
import { FileHandler } from '@tiptap/extension-file-handler';
import UniqueID from '@tiptap/extension-unique-id';
import { ReactNodeViewRenderer } from '@tiptap/react';
import { AiSuggestionMark } from './AiSuggestionMark';
import { ImageNodeViewComponent } from './ImageNodeView';
import type { Extensions } from '@tiptap/react';

/** Options for customizing the editor extension set */
export interface EditorExtensionOptions {
  /** Placeholder text shown when editor is empty */
  placeholder?: string;

  /**
   * Callback invoked when image files are pasted or dropped into the editor.
   * Receives the file array — caller is responsible for uploading and inserting.
   * If not provided, pasted images are logged to console (dev mode).
   */
  onImageFiles?: (files: File[]) => void;
}

/** Default placeholder text for an empty editor */
const DEFAULT_PLACEHOLDER = 'Start drafting your content...';

/**
 * Returns the full, configured Tiptap extension array for article editing/viewing.
 *
 * All extensions needed for a professional article editor are included:
 * - Rich text formatting (bold, italic, underline, strike, code)
 * - Headings (H1–H4)
 * - Lists (bullet, ordered)
 * - Links (SEO-safe with autolink)
 * - Images (block-level, base64 until upload pipeline)
 * - Tables (table, row, cell, header)
 * - Text alignment (left, center, right, justify)
 * - Superscript, subscript, highlight
 * - Smart typography (quotes, dashes, arrows)
 * - File handler (paste/drop images)
 */
export function getEditorExtensions(options?: EditorExtensionOptions): Extensions {
  const placeholder = options?.placeholder ?? DEFAULT_PLACEHOLDER;

  return [
    // ── Core: StarterKit v3 ───────────────────────────────────
    // Bundles Document, Paragraph, Text, Bold, Italic, Strike, Code,
    // CodeBlock, Heading, BulletList, OrderedList, ListItem, Blockquote,
    // HardBreak, HorizontalRule, History, Dropcursor, Gapcursor,
    // Link (v3), Underline (v3)
    StarterKit.configure({
      heading: { levels: [1, 2, 3, 4] },
      // Link — SEO-safe configuration (bundled in StarterKit v3)
      link: {
        openOnClick: false,     // Don't navigate away when editing
        autolink: true,         // Auto-detect URLs while typing
        defaultProtocol: 'https',
        HTMLAttributes: {
          rel: 'noopener noreferrer',
          target: '_blank',
        },
      },
      // Underline — included in StarterKit v3, no extra config needed
    }),

    // ── Marks (not in StarterKit) ─────────────────────────────
    Superscript,
    Subscript,
    TextStyle, // Required by Highlight and potential future Color extension
    Highlight.configure({
      multicolor: false, // Single highlight color for simplicity
    }),

    // ── Images — block-level for article layouts ──────────────
    // IMPORTANT: We extend Image (not just configure) to register 'data-media-id'
    // as a proper schema attribute. Without this, Tiptap drops the attribute
    // during HTML→ProseMirror parsing, and useImageOrchestration can never
    // find the skeleton nodes to replace them with generated images.
    Image.extend({
      addAttributes() {
        return {
          ...this.parent?.(),
          'data-media-id': {
            default: null,
            parseHTML: (element) => element.getAttribute('data-media-id'),
            renderHTML: (attributes) => {
              if (!attributes['data-media-id']) return {};
              return { 'data-media-id': attributes['data-media-id'] };
            },
          },
          'data-original-prompt': {
            default: null,
            parseHTML: (element) => element.getAttribute('data-original-prompt'),
            renderHTML: (attributes) => {
              if (!attributes['data-original-prompt']) return {};
              return { 'data-original-prompt': attributes['data-original-prompt'] };
            },
          },
        };
      },
      // Custom React NodeView — renders ImageNodeViewComponent with hover overlay
      addNodeView() {
        return ReactNodeViewRenderer(ImageNodeViewComponent);
      },
    }).configure({
      inline: false,          // Render as block element (own paragraph)
      allowBase64: true,      // Required for copy-paste until upload pipeline exists
    }),

    // ── Tables — full support with header rows ────────────────
    Table.configure({
      resizable: false,       // Keep simple for v1 — resizable in future
    }),
    TableRow,
    TableCell,
    TableHeader,

    // ── Text Alignment — paragraph and heading support ────────
    TextAlign.configure({
      types: ['heading', 'paragraph'],
    }),

    // ── Smart Typography — auto-correct quotes, dashes, etc. ──
    Typography,

    // ── Placeholder — shown when editor is empty ──────────────
    Placeholder.configure({
      placeholder,
    }),

    // ── UniqueID — assign permanent IDs to nodes for AI AST matching ──
    UniqueID.configure({
      types: ['heading', 'paragraph', 'table', 'blockquote', 'image'],
    }),

    // ── AI Track Changes — inline diff marks ────────────────
    // AiSuggestionDecoration (block-level) will be added in Phase 2
    // when the decoration command API is implemented.
    AiSuggestionMark,

    // ── File Handler — intercept paste/drop of images ─────────
    // In v1 this logs files; Steg 3 will add actual WP Media upload
    FileHandler.configure({
      allowedMimeTypes: ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'],
      onPaste: (_editor, files) => {
        if (options?.onImageFiles) {
          options.onImageFiles(files);
        } else {
          // eslint-disable-next-line no-console
          console.log('[Editor] Image pasted — upload handler not configured yet', files);
        }
      },
      onDrop: (_editor, files) => {
        if (options?.onImageFiles) {
          options.onImageFiles(files);
        } else {
          // eslint-disable-next-line no-console
          console.log('[Editor] Image dropped — upload handler not configured yet', files);
        }
      },
    }),
  ];
}
