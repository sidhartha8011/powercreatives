/**
 * WRITER MODULE — AST Context Serializer
 *
 * This module strips down Tiptap's HTML representation into a structured AST 
 * (Abstract Syntax Tree) format specifically designed for AI processing.
 * Instead of feeding raw HTML to LLMs, we feed a clean JSON array of blocks.
 */
import { Editor } from '@tiptap/react';

export interface ASTContextObject {
  id: string;
  type: string;
  content: string; // The text content or relevant data of the block
}

/**
 * Iterates through the Tiptap document and extracts nodes that have a unique ID.
 * Returns a linear array of context objects matching the AI backend's schema.
 * 
 * Target types configured in UniqueID: heading, paragraph, table, blockquote, image
 * 
 * @param editor The active Tiptap editor instance
 * @returns Array of AST objects
 */
export function serializeAst(editor: Editor): ASTContextObject[] {
  const ast: ASTContextObject[] = [];
  const { doc } = editor.state;

  doc.descendants((node) => {
    // We only care about nodes that have an ID (stamped by UniqueID extension)
    if (node.isBlock && node.attrs.id) {
      
      let extractionContent = '';
      
      if (node.type.name === 'image') {
        // For images, we might want to extract alt text or src URL
        extractionContent = node.attrs.alt || node.attrs.src || '[Image]';
      } else if (node.type.name === 'table') {
        // A minimal text rendering of the table for context
        extractionContent = node.textContent;
      } else {
        // Headings, paragraphs, blockquotes
        extractionContent = node.textContent;
      }

      ast.push({
        id: node.attrs.id,
        type: node.type.name,
        content: extractionContent,
      });

      // Returning false would stop descent, but we want to make sure 
      // we don't extract children if the parent already covers the context we need.
      // E.g., we probably don't need paragraph IDs inside a table if we extracted the table.
      // But for this MVP, we let it descend unless it's a table where we might want to stop.
      if (node.type.name === 'table') {
        return false; // don't descend into table cells for individual tracking in MVP
      }
    }
  });

  return ast;
}
