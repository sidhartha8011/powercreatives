/**
 * SHARED — Image Node View (Tiptap Custom NodeView)
 *
 * Custom React renderer for image nodes in Tiptap. When the editor is
 * editable, shows a hover overlay with regenerate/edit actions.
 * When read-only (e.g. Approvals viewer), renders the plain image.
 *
 * Registered in editorExtensions.ts via Image.extend({ addNodeView }).
 *
 * Architecture: NodeView is a thin wrapper. Logic lives in useImageActions,
 * UI lives in ImageOverlay. This file just wires them together.
 *
 * @package PowerCreatives
 * @module  Shared
 */

import { useState } from 'react';
import { NodeViewWrapper } from '@tiptap/react';
import type { NodeViewProps } from '@tiptap/react';
import { ImageOverlay } from './ImageOverlay';
import { useImageActions } from './useImageActions';

/**
 * Custom NodeView component for Tiptap Image nodes.
 * Shows hover overlay with regenerate/edit when editable.
 */
export function ImageNodeViewComponent({ node, getPos, editor }: NodeViewProps) {
  const [isHovered, setIsHovered] = useState(false);
  const { imageModels, selectedModel, setSelectedModel, regenerate, editImage, isProcessing } = useImageActions();
  const isEditable = editor.isEditable;

  const handleRegenerate = (additionalPrompt?: string) => {
    const pos = typeof getPos === 'function' ? getPos() : undefined;
    if (pos === undefined) return;
    regenerate(editor, pos, additionalPrompt);
  };

  const handleEdit = (instructions: string) => {
    const pos = typeof getPos === 'function' ? getPos() : undefined;
    if (pos === undefined) return;
    editImage(editor, pos, instructions);
  };

  return (
    <NodeViewWrapper
      as="div"
      style={{ position: 'relative', display: 'inline-block', width: '100%' }}
      onMouseEnter={() => isEditable && setIsHovered(true)}
      onMouseLeave={() => !isProcessing && setIsHovered(false)}
      data-drag-handle=""
    >
      {/* Render the actual image */}
      <img
        src={node.attrs.src}
        alt={node.attrs.alt || ''}
        title={node.attrs.title || undefined}
        className={node.attrs.class || ''}
        style={{
          display: 'block',
          width: '100%',
          maxWidth: '100%',
          borderRadius: '8px',
        }}
        draggable={false}
      />

      {/* Hover overlay — only when editor is editable */}
      {isEditable && (
        <ImageOverlay
          isVisible={isHovered || isProcessing}
          isProcessing={isProcessing}
          imageModels={imageModels}
          selectedModel={selectedModel}
          onModelChange={setSelectedModel}
          onRegenerate={handleRegenerate}
          onEdit={handleEdit}
        />
      )}
    </NodeViewWrapper>
  );
}
