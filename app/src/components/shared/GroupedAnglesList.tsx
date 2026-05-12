/**
 * GROUPED ANGLES LIST — Audience-paired angle management
 *
 * Replaces the flat ModeListBox for angles in the Copy module sidebar.
 * Groups angles under their paired audience with:
 *   - Audience headers (color-coded dots)
 *   - Drag-and-drop between groups via @hello-pangea/dnd
 *   - Copy button to duplicate an angle
 *   - Per-group "Add angle..." inline input
 *   - Header with sparkle icon + Manual/Auto toggle (reuses ModeToggle)
 *
 * Data contract:
 *   - Each angle has an `audienceId` field linking it to an audience
 *   - `audienceId: '*'` means the angle applies to ALL audiences (global)
 *   - Backend `build_task_matrix()` uses audienceId for paired generation
 *
 * @package PowerCreatives
 * @since   1.2.0
 */

import { useState, useCallback, useMemo } from 'react';
import {
    DragDropContext,
    Droppable,
    Draggable,
    type DropResult,
} from '@hello-pangea/dnd';
import { Plus, X, Sparkles, Loader2, Copy, GripVertical } from 'lucide-react';
import { colors, typography, spacing } from './design-tokens';
import { ModeToggle, type GenerationMode } from './ModeToggle';

// ─── Types ────────────────────────────────────────────────

/** Extended ListItem with optional audience pairing */
export interface AngleItem {
    id: string;
    name: string;
    audienceId?: string;
}

/** Audience reference for grouping */
interface AudienceRef {
    id: string;
    name: string;
}

interface GroupedAnglesListProps {
    /** Current mode: manual or auto */
    mode: GenerationMode;
    onModeChange: (mode: GenerationMode) => void;
    /** All angle items (will be grouped by audienceId) */
    items: AngleItem[];
    /** Callback when items change (add, remove, copy, reorder, drag) */
    onItemsChange: (items: AngleItem[]) => void;
    /** Audiences to group by */
    audiences: AudienceRef[];
    /** Auto mode count (angles per audience) */
    autoCount: number;
    onAutoCountChange: (count: number) => void;
    /** AI generation callback (sparkle button) */
    onGenerate?: () => Promise<AngleItem[]>;
    /** Called after AI generates items — switches to Manual */
    onItemsGenerated?: (items: AngleItem[]) => void;
}

// ─── Audience Color Palette ──────────────────────────────

/** Harmonious color palette for audience group dots */
const AUDIENCE_COLORS = [
    '#3b82f6', // blue
    '#10b981', // green
    '#f59e0b', // amber
    '#8b5cf6', // purple
    '#ef4444', // red
    '#06b6d4', // cyan
    '#ec4899', // pink
    '#84cc16', // lime
    '#f97316', // orange
    '#6366f1', // indigo
] as const;

/** Get a stable color for an audience index */
function getAudienceColor(index: number): string {
    return AUDIENCE_COLORS[index % AUDIENCE_COLORS.length];
}

// ─── Component ───────────────────────────────────────────

export function GroupedAnglesList({
    mode,
    onModeChange,
    items,
    onItemsChange,
    audiences,
    autoCount,
    onAutoCountChange,
    onGenerate,
    onItemsGenerated,
}: GroupedAnglesListProps) {
    const [isGenerating, setIsGenerating] = useState(false);

    // ── Group items by audienceId ──
    const grouped = useMemo(() => {
        const groups: Record<string, AngleItem[]> = {};

        // Initialize groups for each audience (preserves order)
        for (const aud of audiences) {
            groups[aud.id] = [];
        }
        // Wildcard group for global angles
        groups['*'] = [];

        // Distribute items into groups
        for (const item of items) {
            const key = item.audienceId ?? '*';
            if (groups[key]) {
                groups[key].push(item);
            } else {
                // Unknown audienceId → treat as global
                groups['*'].push(item);
            }
        }

        return groups;
    }, [items, audiences]);

    // ── Sparkle click handler ──
    const handleSparkleClick = useCallback(async () => {
        if (!onGenerate || isGenerating) return;
        setIsGenerating(true);
        try {
            const generated = await onGenerate();
            if (generated.length > 0) {
                onItemsGenerated?.(generated);
                onModeChange('manual');
            }
        } finally {
            setIsGenerating(false);
        }
    }, [onGenerate, isGenerating, onItemsGenerated, onModeChange]);

    // ── Add item to a specific audience group ──
    const handleAddToGroup = useCallback(
        (audienceId: string, name: string) => {
            const trimmed = name.trim();
            if (!trimmed) return;
            const newItem: AngleItem = {
                id: `angle-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
                name: trimmed,
                audienceId,
            };
            onItemsChange([...items, newItem]);
        },
        [items, onItemsChange],
    );

    // ── Remove item ──
    const handleRemove = useCallback(
        (itemId: string) => {
            onItemsChange(items.filter((i) => i.id !== itemId));
        },
        [items, onItemsChange],
    );

    // ── Copy item to same group ──
    const handleCopy = useCallback(
        (item: AngleItem) => {
            const copy: AngleItem = {
                id: `angle-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
                name: item.name,
                audienceId: item.audienceId,
            };
            // Insert copy right after the original
            const idx = items.findIndex((i) => i.id === item.id);
            const updated = [...items];
            updated.splice(idx + 1, 0, copy);
            onItemsChange(updated);
        },
        [items, onItemsChange],
    );

    // ── Drag end — move angle between audience groups ──
    const handleDragEnd = useCallback(
        (result: DropResult) => {
            const { source, destination, draggableId } = result;
            if (!destination) return;

            // Find the dragged item
            const draggedItem = items.find((i) => i.id === draggableId);
            if (!draggedItem) return;

            // If dropped in same position, do nothing
            if (
                source.droppableId === destination.droppableId &&
                source.index === destination.index
            ) {
                return;
            }

            // Build new items array: remove from old position, insert at new
            const updated = items.filter((i) => i.id !== draggableId);
            const movedItem: AngleItem = {
                ...draggedItem,
                audienceId: destination.droppableId,
            };

            // Find insertion point based on destination group + index
            const destGroup = updated.filter(
                (i) => (i.audienceId ?? '*') === destination.droppableId,
            );
            if (destination.index >= destGroup.length) {
                // Append at end of group — find last item in dest group
                const lastInGroup = destGroup[destGroup.length - 1];
                const lastIdx = lastInGroup
                    ? updated.indexOf(lastInGroup) + 1
                    : updated.length;
                updated.splice(lastIdx, 0, movedItem);
            } else {
                // Insert at specific position within group
                const targetItem = destGroup[destination.index];
                const targetIdx = updated.indexOf(targetItem);
                updated.splice(targetIdx, 0, movedItem);
            }

            onItemsChange(updated);
        },
        [items, onItemsChange],
    );

    // ── Build audience lookup for colors ──
    const audienceColorMap = useMemo(() => {
        const map: Record<string, { color: string; name: string }> = {};
        audiences.forEach((aud, i) => {
            map[aud.id] = { color: getAudienceColor(i), name: aud.name };
        });
        return map;
    }, [audiences]);

    // ── Render ──
    return (
        <div
            className="rounded-lg border p-3 space-y-2"
            style={{ borderColor: colors.border, background: colors.bgMuted }}
        >
            {/* Header: label + sparkle + mode toggle */}
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-1">
                    <span
                        style={{
                            fontSize: typography.micro,
                            fontWeight: typography.semibold,
                            textTransform: 'uppercase',
                            letterSpacing: '0.05em',
                            color: colors.textSecondary,
                        }}
                    >
                        Angles
                    </span>

                    {/* Sparkle icon */}
                    {onGenerate && (
                        <button
                            type="button"
                            onClick={handleSparkleClick}
                            disabled={isGenerating}
                            title="Generate angles with AI"
                            className="p-1 rounded-md transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                            style={{
                                color: colors.textMuted,
                            }}
                            onMouseEnter={(e) => {
                                if (!isGenerating) {
                                    e.currentTarget.style.color = colors.primary;
                                    e.currentTarget.style.background = colors.primaryLight;
                                }
                            }}
                            onMouseLeave={(e) => {
                                e.currentTarget.style.color = colors.textMuted;
                                e.currentTarget.style.background = 'transparent';
                            }}
                        >
                            {isGenerating ? (
                                <Loader2
                                    style={{ width: spacing.iconXs, height: spacing.iconXs }}
                                    className="animate-spin"
                                />
                            ) : (
                                <Sparkles
                                    style={{ width: spacing.iconXs, height: spacing.iconXs }}
                                />
                            )}
                        </button>
                    )}
                </div>

                <ModeToggle
                    mode={mode}
                    onModeChange={onModeChange}
                    count={autoCount}
                    onCountChange={onAutoCountChange}
                    min={1}
                    max={10}
                />
            </div>

            {/* Auto mode hint */}
            {mode === 'auto' && (
                <p
                    style={{
                        fontSize: typography.xs,
                        color: colors.textMuted,
                        margin: 0,
                    }}
                >
                    AI will generate {autoCount} angle{autoCount !== 1 ? 's' : ''} per
                    audience based on your brief
                </p>
            )}

            {/* Manual mode: grouped angle list */}
            {mode === 'manual' && (
                <DragDropContext onDragEnd={handleDragEnd}>
                    <div className="space-y-3">
                        {/* Global angles (audienceId: '*') — only show if they exist */}
                        {grouped['*'] && grouped['*'].length > 0 && (
                            <AngleGroup
                                groupId="*"
                                label="All Audiences"
                                color={colors.textMuted}
                                items={grouped['*']}
                                onRemove={handleRemove}
                                onCopy={handleCopy}
                                onAdd={(name) => handleAddToGroup('*', name)}
                            />
                        )}

                        {/* Audience-specific groups */}
                        {audiences.map((aud, i) => (
                            <AngleGroup
                                key={aud.id}
                                groupId={aud.id}
                                label={aud.name}
                                color={getAudienceColor(i)}
                                items={grouped[aud.id] ?? []}
                                onRemove={handleRemove}
                                onCopy={handleCopy}
                                onAdd={(name) => handleAddToGroup(aud.id, name)}
                            />
                        ))}

                        {/* Empty state: no audiences defined */}
                        {audiences.length === 0 && (!grouped['*'] || grouped['*'].length === 0) && (
                            <p
                                style={{
                                    fontSize: typography.xs,
                                    color: colors.textMuted,
                                    textAlign: 'center',
                                    padding: '8px 0',
                                }}
                            >
                                Add audiences first, then generate or add angles
                            </p>
                        )}
                    </div>
                </DragDropContext>
            )}
        </div>
    );
}

// ─── Audience Group Sub-Component ────────────────────────

interface AngleGroupProps {
    groupId: string;
    label: string;
    color: string;
    items: AngleItem[];
    onRemove: (id: string) => void;
    onCopy: (item: AngleItem) => void;
    onAdd: (name: string) => void;
}

function AngleGroup({
    groupId,
    label,
    color,
    items,
    onRemove,
    onCopy,
    onAdd,
}: AngleGroupProps) {
    const [inputValue, setInputValue] = useState('');

    const handleAdd = () => {
        if (!inputValue.trim()) return;
        onAdd(inputValue.trim());
        setInputValue('');
    };

    return (
        <div>
            {/* Audience header with color dot */}
            <div className="flex items-center gap-1.5 mb-1">
                <div
                    style={{
                        width: 8,
                        height: 8,
                        borderRadius: '50%',
                        background: color,
                        flexShrink: 0,
                    }}
                />
                <span
                    className="truncate"
                    style={{
                        fontSize: typography.xxs,
                        fontWeight: typography.semibold,
                        color: colors.textSecondary,
                    }}
                >
                    {label}
                </span>
                <span
                    style={{
                        fontSize: typography.xxs,
                        color: colors.textFaint,
                    }}
                >
                    ({items.length})
                </span>
            </div>

            {/* Droppable zone for this audience group */}
            <Droppable droppableId={groupId}>
                {(provided, snapshot) => (
                    <div
                        ref={provided.innerRef}
                        {...provided.droppableProps}
                        className="space-y-1 rounded-md transition-colors"
                        style={{
                            minHeight: 4,
                            padding: snapshot.isDraggingOver ? '4px' : '0',
                            background: snapshot.isDraggingOver
                                ? colors.primaryLight
                                : 'transparent',
                            borderRadius: spacing.radiusSm,
                        }}
                    >
                        {items.map((item, index) => (
                            <Draggable key={item.id} draggableId={item.id} index={index}>
                                {(dragProvided, dragSnapshot) => (
                                    <div
                                        ref={dragProvided.innerRef}
                                        {...dragProvided.draggableProps}
                                        className="flex items-center justify-between rounded-md px-2 py-1.5 group"
                                        style={{
                                            ...dragProvided.draggableProps.style,
                                            background: dragSnapshot.isDragging
                                                ? colors.bgHover
                                                : colors.bgSurface,
                                            border: `1px solid ${dragSnapshot.isDragging
                                                ? colors.primary
                                                : colors.borderLight
                                                }`,
                                            boxShadow: dragSnapshot.isDragging
                                                ? '0 4px 12px rgba(0,0,0,0.1)'
                                                : 'none',
                                        }}
                                    >
                                        {/* Drag handle */}
                                        <div
                                            {...dragProvided.dragHandleProps}
                                            className="opacity-0 group-hover:opacity-60 transition-opacity cursor-grab mr-1"
                                            style={{ flexShrink: 0 }}
                                        >
                                            <GripVertical
                                                style={{
                                                    width: spacing.iconXs,
                                                    height: spacing.iconXs,
                                                    color: colors.textMuted,
                                                }}
                                            />
                                        </div>

                                        {/* Angle name */}
                                        <span
                                            className="truncate flex-1"
                                            style={{
                                                fontSize: typography.xs,
                                                fontWeight: typography.medium,
                                                color: colors.text,
                                            }}
                                        >
                                            {item.name}
                                        </span>

                                        {/* Action buttons (visible on hover) */}
                                        <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                            {/* Copy */}
                                            <button
                                                type="button"
                                                onClick={() => onCopy(item)}
                                                className="p-0.5 rounded hover:bg-blue-50 transition-colors"
                                                title="Duplicate angle"
                                            >
                                                <Copy
                                                    style={{
                                                        width: spacing.iconXs,
                                                        height: spacing.iconXs,
                                                        color: colors.textMuted,
                                                    }}
                                                />
                                            </button>

                                            {/* Remove */}
                                            <button
                                                type="button"
                                                onClick={() => onRemove(item.id)}
                                                className="p-0.5 rounded hover:bg-red-50 transition-colors"
                                                title="Remove angle"
                                            >
                                                <X
                                                    style={{
                                                        width: spacing.iconXs,
                                                        height: spacing.iconXs,
                                                        color: colors.danger,
                                                    }}
                                                />
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </Draggable>
                        ))}
                        {provided.placeholder}
                    </div>
                )}
            </Droppable>

            {/* Per-group add input */}
            <div className="flex gap-1 mt-1">
                <input
                    type="text"
                    value={inputValue}
                    onChange={(e) => setInputValue(e.target.value)}
                    placeholder="Add angle..."
                    onKeyDown={(e) => e.key === 'Enter' && handleAdd()}
                    className="flex-1 focus:outline-none focus:ring-1 focus:ring-blue-300"
                    style={{
                        fontSize: typography.xs,
                        color: colors.text,
                        background: colors.bgSurface,
                        border: `1px solid ${colors.borderLight}`,
                        borderRadius: spacing.radiusSm,
                        padding: '4px 8px',
                    }}
                />
                <button
                    type="button"
                    onClick={handleAdd}
                    disabled={!inputValue.trim()}
                    className="rounded-md p-1 transition-colors disabled:opacity-30"
                    style={{
                        background: colors.bgSurface,
                        border: `1px solid ${colors.borderLight}`,
                    }}
                >
                    <Plus
                        style={{
                            width: spacing.iconXs,
                            height: spacing.iconXs,
                            color: colors.primary,
                        }}
                    />
                </button>
            </div>
        </div>
    );
}
