/**
 * Item type registry — the ONE place that knows what kinds of item a card holds.
 *
 * An approval card is a container of items. An item declares what it IS: how it
 * renders, what actions apply to it, what its text is, whether it can be edited.
 * Nothing outside this folder may branch on a type name.
 *
 * This replaces 13 `type ===` branches spread through a 682-line component in
 * which each type was implemented to a different depth — which is why a document
 * rendered as a 120-character snippet, its copy button read a field it does not
 * have, and it had no editor at all. A type that cannot do something must not
 * offer a control that pretends it can.
 */

import type { ComponentType } from 'react';
import { FileText, Image as ImageIcon, Newspaper, Type, type LucideIcon } from 'lucide-react';

/** What an item can be asked to do. The shell renders exactly these. */
export type ItemAction = 'approve' | 'comment' | 'edit' | 'delete' | 'copy' | 'download';

/** Everything a renderer is given. No component reads `window` or the URL. */
export interface ItemContext {
  /** The set's share token — the asset routes are token-scoped. */
  token: string;
  /** What the CURRENT viewer may do. A locked card grants none of the writes. */
  can: {
    approve: boolean;
    comment: boolean;
    edit: boolean;
    delete: boolean;
  };
  /** Re-read the card after a mutation. */
  onChanged?: () => void;
}

export interface ApprovalItem {
  id: string;
  type: string;
  /** The raw stored payload for this item. Shape is the type's business. */
  data: Record<string, any>;
}

export interface ItemTypeDef {
  /** Storage bucket in `snapshot`, and the discriminator the board sends. */
  id: 'media' | 'copy' | 'articles' | 'custom';
  /** Singular, human label. English only, per the standing UI law. */
  label: string;
  icon: LucideIcon;
  /** Where this bucket's approved ids live in `reviewFeedback`. */
  approvalKey: 'approvedVisualIds' | 'approvedCopyIds' | 'approvedArticleIds' | 'approvedCustomIds';
  /** The body of the item. The shell supplies the surrounding chrome. */
  render: ComponentType<{ item: ApprovalItem; ctx: ItemContext }>;
  /**
   * Actions this type genuinely supports. The shell renders ONLY these, so a
   * control can never appear for something the type cannot do.
   */
  actions: ReadonlyArray<ItemAction>;
  /**
   * The text `copy` puts on the clipboard. Declared per type because the field
   * differs — copy uses `body`, a document uses `content`. Hardcoding one of
   * them is precisely the bug this removes.
   */
  getText: (item: ApprovalItem) => string;
  /** Display title for lists and headers. */
  getTitle: (item: ApprovalItem) => string;
}

/** Strip tags for the clipboard / snippets, without pulling in a parser. */
export function plainText(html: string | undefined | null): string {
  if (!html) return '';
  return String(html).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

/**
 * Registered types are wired in `index.ts`, which imports the renderers. This
 * file stays free of component imports so a renderer can import the types it
 * needs without a cycle.
 */
export const ITEM_TYPE_META: Readonly<Record<ItemTypeDef['id'], Omit<ItemTypeDef, 'render'>>> = {
  media: {
    id: 'media',
    label: 'Image',
    icon: ImageIcon,
    approvalKey: 'approvedVisualIds',
    actions: ['approve', 'comment', 'download', 'delete'],
    getText: (i) => String(i.data.url ?? ''),
    getTitle: (i) => String(i.data.name ?? 'Untitled image'),
  },
  copy: {
    id: 'copy',
    label: 'Copy',
    icon: Type,
    approvalKey: 'approvedCopyIds',
    actions: ['approve', 'comment', 'edit', 'copy', 'delete'],
    getText: (i) => String(i.data.body ?? ''),
    getTitle: (i) => String(i.data.headline ?? 'Untitled copy'),
  },
  articles: {
    id: 'articles',
    label: 'Article',
    icon: Newspaper,
    approvalKey: 'approvedArticleIds',
    actions: ['approve', 'comment', 'edit', 'copy', 'delete'],
    getText: (i) => plainText(i.data.content),
    getTitle: (i) => String(i.data.title ?? 'Untitled article'),
  },
  custom: {
    id: 'custom',
    label: 'Document',
    icon: FileText,
    approvalKey: 'approvedCustomIds',
    actions: ['approve', 'comment', 'edit', 'copy', 'delete'],
    // A document keeps its text in `content`. The old shared handler read
    // `body`, which documents do not have, so "copy" silently copied nothing.
    getText: (i) => plainText(i.data.content),
    getTitle: (i) => String(i.data.title ?? 'Untitled document'),
  },
};

/** Singular discriminators used by the client view's merged list. */
const ALIASES: Readonly<Record<string, ItemTypeDef['id']>> = {
  article: 'articles',
  image: 'media',
  video: 'media',
};

/**
 * Resolve a type from either vocabulary. Falls back to `custom` rather than
 * throwing: an unknown type must still render as something — a card that
 * silently drops an item is worse than one labelled generically.
 */
export function itemTypeMeta(type: string | undefined | null): Omit<ItemTypeDef, 'render'> {
  const key = String(type ?? '').trim();
  if (key in ITEM_TYPE_META) return ITEM_TYPE_META[key as ItemTypeDef['id']];
  const alias = ALIASES[key];
  return alias ? ITEM_TYPE_META[alias] : ITEM_TYPE_META.custom;
}
