/**
 * DocumentItem — a Notion-style document inside an approval card.
 *
 * EDITABLE, using the Writer's shared Tiptap extension set — the owner's
 * instruction was "the Writer's editor, nothing bespoke". Before this, a
 * document had no editing path at all: the edit click was bound to
 * `type === 'copy'`, so three of the four types silently had none.
 *
 * The token comes from `ctx`. No component here reads `window.location`, which
 * is why editing previously worked on the public page and failed in wp-admin.
 */

import { useCallback, useEffect, useState } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import { toast } from 'sonner';

import { getEditorExtensions } from '@/components/shared/editorExtensions';
import { escapeAstral } from '@/lib/escapeAstral';
import { trpc } from '@/lib/trpc';

import type { ApprovalItem, ItemContext } from './registry';

export interface DocumentItemProps {
  item: ApprovalItem;
  ctx: ItemContext;
  /** Driven by the shell's Edit toggle. */
  isEditing: boolean;
  onEditingChange: (editing: boolean) => void;
}

export function DocumentItem({ item, ctx, isEditing, onEditingChange }: DocumentItemProps) {
  const [saving, setSaving] = useState(false);
  const updateMutation = trpc.approvals.updateSnapshotAsset.useMutation();

  const editor = useEditor({
    extensions: getEditorExtensions({ placeholder: 'Write the document…' }),
    content: String(item.data.content || '<p></p>'),
    editable: isEditing,
    editorProps: { attributes: { class: 'pcm-card-editor outline-none' } },
  });

  // Toggling edit mode must flip the SAME editor rather than remount it, or the
  // caret position and any in-flight typing are thrown away.
  useEffect(() => {
    editor?.setEditable(isEditing);
  }, [editor, isEditing]);

  const save = useCallback(async () => {
    if (!editor) return;
    const html = editor.getHTML();
    if (html === String(item.data.content || '')) {
      onEditingChange(false);
      return;
    }
    if (!ctx.token) {
      toast.error('Cannot save — this card has no share token.');
      return;
    }
    setSaving(true);
    updateMutation.mutate(
      {
        token: ctx.token,
        assetId: item.id,
        // Escape emoji to ASCII so a request-stripping WAF cannot drop them in
        // transit; the server decodes them before storing.
        content: escapeAstral(html),
      },
      {
        onSuccess: () => {
          setSaving(false);
          onEditingChange(false);
          ctx.onChanged?.();
          toast.success('Document saved');
        },
        onError: (err: any) => {
          setSaving(false);
          toast.error(err?.message || 'Could not save the document.');
        },
      }
    );
  }, [editor, item.id, item.data.content, ctx, updateMutation, onEditingChange]);

  return (
    <div className="pcm-doc-item">
      <h3 className="pcm-doc-item-title">{String(item.data.title || 'Untitled document')}</h3>
      <div className={`pcm-doc-item-body ${isEditing ? 'is-editing' : ''}`}>
        {editor && <EditorContent editor={editor} />}
      </div>
      {isEditing && (
        <div className="pcm-doc-item-editbar">
          <button type="button" className="pcm-item-btn" onClick={save} disabled={saving}>
            {saving ? 'Saving…' : 'Save'}
          </button>
          <button
            type="button"
            className="pcm-item-btn"
            onClick={() => { editor?.commands.setContent(String(item.data.content || '<p></p>')); onEditingChange(false); }}
            disabled={saving}
          >
            Cancel
          </button>
        </div>
      )}
    </div>
  );
}
