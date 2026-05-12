/**
 * WRITER MODULE — Diff Applicator (The Mutation Engine)
 *
 * Applies structured AI JSON diffs into the Tiptap editor via ProseMirror
 * transactions. All mutations are atomic (single transaction = single Undo step).
 *
 * Safety guarantees:
 * - If a target node was deleted by the user during AI processing, the diff is
 *   silently skipped (offset rescue).
 * - Position offsets are correct: marks target text INSIDE nodes (pos+1 to
 *   pos+nodeSize-1), not the node wrapper itself.
 * - New content is parsed via ProseMirror's DOMParser (not string injection).
 *
 * @package PowerCreatives
 * @module  Writer
 */
import { Editor } from '@tiptap/react';
import { DOMParser as ProseMirrorDOMParser } from '@tiptap/pm/model';
import type { Node as ProseMirrorNode, Schema, Fragment } from '@tiptap/pm/model';

/** A single AI diff instruction targeting a specific node by its UniqueID */
export interface AiDiffInstruction {
  /** The UniqueID of the target node (stamped by @tiptap/extension-unique-id) */
  id: string;
  /** The mutation to perform */
  action: 'replace' | 'insert_after' | 'delete';
  /** HTML string for the replacement/insertion content (required for replace & insert_after) */
  content?: string;
}

/**
 * Parses an HTML string into a ProseMirror Fragment using the editor's schema.
 * Uses the browser's native DOMParser under the hood — no regex, no string hacks.
 */
function parseHtmlToFragment(schema: Schema, html: string): Fragment {
  const tempDiv = document.createElement('div');
  tempDiv.innerHTML = html;
  const parsed = ProseMirrorDOMParser.fromSchema(schema).parse(tempDiv);
  return parsed.content;
}

/**
 * Finds a block node by its UniqueID attribute within a ProseMirror document.
 * Returns { pos, node } or null if not found (offset rescue).
 */
function findNodeById(
  doc: ProseMirrorNode,
  id: string,
): { pos: number; node: ProseMirrorNode } | null {
  let result: { pos: number; node: ProseMirrorNode } | null = null;

  doc.descendants((node, pos) => {
    if (result) return false; // Already found — stop traversing
    if (node.isBlock && node.attrs.id === id) {
      result = { pos, node };
      return false;
    }
  });

  return result;
}

/**
 * Safely applies a set of differential instructions returned by the AI provider.
 * Dispatches a single ProseMirror transaction so all changes are atomic
 * (one Ctrl+Z undoes everything).
 *
 * Supported actions:
 * - replace:      Mark existing text as deletion, insert new content as insertion
 * - delete:       Mark existing text as deletion (user accepts/rejects later)
 * - insert_after: Insert new content after target node, marked as insertion
 *
 * @param editor The active Tiptap editor instance
 * @param diffs  Array of instructions, each targeting a specific node by UniqueID
 */
export function applyAiDiffs(editor: Editor, diffs: AiDiffInstruction[]): void {
  const { view, state } = editor;
  const tr = state.tr;

  diffs.forEach(diff => {
    // Find node in the CURRENT transaction document (reflects accumulated changes)
    const found = findNodeById(tr.doc, diff.id);

    if (!found) {
      // Offset Rescue: user deleted the block while AI was thinking — skip safely
      console.warn(`[DiffApplicator] Node ID "${diff.id}" not found in document. Skipping.`);
      return;
    }

    const { pos: targetPos, node: targetNode } = found;

    try {
      const nodeSize = targetNode.nodeSize;
      // Text content lives INSIDE the node (exclude open/close tokens)
      const contentStart = targetPos + 1;
      const contentEnd = targetPos + nodeSize - 1;

      switch (diff.action) {
        case 'replace': {
          if (!diff.content) {
            console.warn(`[DiffApplicator] replace on "${diff.id}" but no content provided. Skipping.`);
            break;
          }

          // Step 1: Mark existing node content as deletion (red strikethrough)
          if (contentEnd > contentStart) {
            tr.addMark(
              contentStart,
              contentEnd,
              state.schema.marks.aiSuggestion.create({ id: diff.id, type: 'deletion' }),
            );
          }

          // Step 2: Parse new HTML into ProseMirror nodes
          const replaceFragment = parseHtmlToFragment(state.schema, diff.content);

          // Step 3: Insert after the target node
          const replaceInsertPos = targetPos + nodeSize;
          tr.insert(replaceInsertPos, replaceFragment);

          // Step 4: Mark inserted content as insertion (green highlight)
          const replaceInsertedSize = replaceFragment.size;
          if (replaceInsertedSize > 0) {
            tr.addMark(
              replaceInsertPos,
              replaceInsertPos + replaceInsertedSize,
              state.schema.marks.aiSuggestion.create({ id: `${diff.id}-new`, type: 'insertion' }),
            );
          }
          break;
        }

        case 'delete': {
          // Mark existing content as deletion — user accepts/rejects via BubbleMenu
          if (contentEnd > contentStart) {
            tr.addMark(
              contentStart,
              contentEnd,
              state.schema.marks.aiSuggestion.create({ id: diff.id, type: 'deletion' }),
            );
          }
          break;
        }

        case 'insert_after': {
          if (!diff.content) {
            console.warn(`[DiffApplicator] insert_after on "${diff.id}" but no content provided. Skipping.`);
            break;
          }

          // Parse and insert new content after target node
          const insertFragment = parseHtmlToFragment(state.schema, diff.content);
          const insertPos = targetPos + nodeSize;
          tr.insert(insertPos, insertFragment);

          // Mark all inserted content as insertion
          const insertedSize = insertFragment.size;
          if (insertedSize > 0) {
            tr.addMark(
              insertPos,
              insertPos + insertedSize,
              state.schema.marks.aiSuggestion.create({ id: `${diff.id}-insert`, type: 'insertion' }),
            );
          }
          break;
        }
      }
    } catch (e) {
      console.error(`[DiffApplicator] Failed to apply diff to node "${diff.id}":`, e);
    }
  });

  // Only dispatch if any steps were actually added to the transaction
  if (tr.docChanged) {
    view.dispatch(tr);
  }
}
