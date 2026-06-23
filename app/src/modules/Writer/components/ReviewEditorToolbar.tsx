import { Editor } from '@tiptap/react';
import {
  BoldIcon, ItalicIcon, UnderlineIcon, StrikethroughIcon,
  Heading1, Heading2, Heading3,
  List, ListOrdered, Quote, Code, Minus,
  Undo2, Redo2,
  Sparkles, Loader2,
} from 'lucide-react';
import { colors, typography } from '@/components/shared';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import React from 'react';

export interface ReviewEditorToolbarProps {
  editor: Editor | null;
  selectedModelId: string;
  setSelectedModelId: (val: string) => void;
  textModels: any[];
  handleGenerate: () => void;
  isPending: boolean;
  canGenerate: boolean;
}

// ── Toolbar button styles — derived from design tokens ──

/** Base styles shared by all toolbar formatting buttons */
const toolbarButtonBase: React.CSSProperties = {
  padding: '4px 6px',
  borderRadius: 4,
  display: 'inline-flex',
  alignItems: 'center',
  justifyContent: 'center',
  border: 'none',
  cursor: 'pointer',
  transition: 'background-color 0.15s, color 0.15s',
};

/** Default (inactive) state */
const toolbarButtonInactive: React.CSSProperties = {
  ...toolbarButtonBase,
  background: 'transparent',
  color: colors.textMuted,
};

/** Active state matching the primary color scale */
const toolbarButtonActive: React.CSSProperties = {
  ...toolbarButtonBase,
  background: colors.primaryLight,
  color: colors.primary,
};

/** Thin, subtle separator line */
const toolbarDivider: React.CSSProperties = {
  width: 1,
  height: 16,
  background: colors.borderLight,
  margin: '0 4px',
};

const iconSize = { width: 16, height: 16 };

export function ReviewEditorToolbar({
  editor,
  selectedModelId,
  setSelectedModelId,
  textModels,
  handleGenerate,
  isPending,
  canGenerate,
}: ReviewEditorToolbarProps) {
  if (!editor) return null;

  // ── Helper: get button style based on active state ──
  const btnStyle = (isActive: boolean) => isActive ? toolbarButtonActive : toolbarButtonInactive;

  return (
    <div
      className="px-4 py-2 shrink-0 flex items-center justify-between"
      style={{ borderBottom: `1px solid ${colors.borderLight}`, background: colors.bgSurface }}
    >
      {/* Left: formatting tools */}
      <div className="flex items-center gap-0.5">
        {/* Heading toggles */}
        <button
          onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}
          style={btnStyle(editor.isActive('heading', { level: 1 }))}
          title="Heading 1"
        >
          <Heading1 style={iconSize} />
        </button>
        <button
          onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
          style={btnStyle(editor.isActive('heading', { level: 2 }))}
          title="Heading 2"
        >
          <Heading2 style={iconSize} />
        </button>
        <button
          onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}
          style={btnStyle(editor.isActive('heading', { level: 3 }))}
          title="Heading 3"
        >
          <Heading3 style={iconSize} />
        </button>

        <div style={toolbarDivider} />

        {/* Inline formatting */}
        <button
          onClick={() => editor.chain().focus().toggleBold().run()}
          style={btnStyle(editor.isActive('bold'))}
          title="Bold (Ctrl+B)"
        >
          <BoldIcon style={iconSize} />
        </button>
        <button
          onClick={() => editor.chain().focus().toggleItalic().run()}
          style={btnStyle(editor.isActive('italic'))}
          title="Italic (Ctrl+I)"
        >
          <ItalicIcon style={iconSize} />
        </button>
        <button
          onClick={() => editor.chain().focus().toggleUnderline().run()}
          style={btnStyle(editor.isActive('underline'))}
          title="Underline (Ctrl+U)"
        >
          <UnderlineIcon style={iconSize} />
        </button>
        <button
          onClick={() => editor.chain().focus().toggleStrike().run()}
          style={btnStyle(editor.isActive('strike'))}
          title="Strikethrough"
        >
          <StrikethroughIcon style={iconSize} />
        </button>

        <div style={toolbarDivider} />

        {/* Block formatting */}
        <button
          onClick={() => editor.chain().focus().toggleBulletList().run()}
          style={btnStyle(editor.isActive('bulletList'))}
          title="Bullet List"
        >
          <List style={iconSize} />
        </button>
        <button
          onClick={() => editor.chain().focus().toggleOrderedList().run()}
          style={btnStyle(editor.isActive('orderedList'))}
          title="Ordered List"
        >
          <ListOrdered style={iconSize} />
        </button>
        <button
          onClick={() => editor.chain().focus().toggleBlockquote().run()}
          style={btnStyle(editor.isActive('blockquote'))}
          title="Blockquote"
        >
          <Quote style={iconSize} />
        </button>
        <button
          onClick={() => editor.chain().focus().toggleCode().run()}
          style={btnStyle(editor.isActive('code'))}
          title="Inline Code"
        >
          <Code style={iconSize} />
        </button>
        <button
          onClick={() => editor.chain().focus().setHorizontalRule().run()}
          style={toolbarButtonInactive}
          title="Horizontal Rule"
        >
          <Minus style={iconSize} />
        </button>

        <div style={toolbarDivider} />

        {/* History (undo/redo) */}
        <button
          onClick={() => editor.chain().focus().undo().run()}
          disabled={!editor.can().undo()}
          style={{
            ...toolbarButtonInactive,
            opacity: editor.can().undo() ? 1 : 0.35,
            cursor: editor.can().undo() ? 'pointer' : 'default',
          }}
          title="Undo (Ctrl+Z)"
        >
          <Undo2 style={iconSize} />
        </button>
        <button
          onClick={() => editor.chain().focus().redo().run()}
          disabled={!editor.can().redo()}
          style={{
            ...toolbarButtonInactive,
            opacity: editor.can().redo() ? 1 : 0.35,
            cursor: editor.can().redo() ? 'pointer' : 'default',
          }}
          title="Redo (Ctrl+Y)"
        >
          <Redo2 style={iconSize} />
        </button>
      </div>

      {/* Right: model selector + generate */}
      <div className="flex items-center gap-2">
        {/* Model dropdown — pulls available models from Integrations, defaults from Settings */}
        <Select value={selectedModelId} onValueChange={setSelectedModelId}>
          <SelectTrigger
            className="bg-white"
            style={{
              height: 28,
              fontSize: typography.xs,
              borderColor: colors.borderLight,
              width: 160,
            }}
          >
            <SelectValue placeholder="Select Model" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="auto">Auto (Default)</SelectItem>
            {textModels.map((model: any) => (
              <SelectItem key={model.id} value={model.modelId}>
                {model.customName || model.originalName}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {/* Generate button — single source of truth for article generation */}
        <Button
          size="sm"
          className="h-8 gap-1.5 text-xs"
          onClick={handleGenerate}
          disabled={isPending || !canGenerate}
        >
          {isPending ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Sparkles className="w-3.5 h-3.5" />}
          {isPending ? 'Generating…' : 'Generate'}
        </Button>
      </div>
    </div>
  );
}
