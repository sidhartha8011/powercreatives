/**
 * WordPress post statuses, and which selected items can actually be changed.
 *
 * Pure and dependency-free ON PURPOSE — no React, no imports — so
 * tests/standalone/post_status_test.mjs can import and execute THIS file
 * (node --experimental-strip-types) instead of transcribing it.
 *
 * The list mirrors PCM_Strategy_Service::POST_STATUSES on the hub. If the two
 * ever disagree the UI offers something the server rejects with a 400, so the
 * test asserts them against each other rather than trusting either alone.
 */

export interface PostStatusOption {
  value: string;
  label: string;
  /** Destructive — the UI must confirm before sending it. */
  destructive?: boolean;
  /** Needs a future scheduledDate on the item; the server refuses without one. */
  needsDate?: boolean;
}

/**
 * Every status a post or page can be moved to from the UI, in WordPress's own
 * order. 'auto-draft' and 'inherit' are internal to WordPress and excluded.
 *
 * 'trash' is included because the owner asked for it explicitly ("Publish /
 * draft / trash (etc)"), even though WordPress models it as a DELETE rather
 * than a status write — the hub turns it into DELETE ?force=false, so it lands
 * in the site's Trash and stays recoverable.
 */
export const POST_STATUSES: PostStatusOption[] = [
  { value: 'publish', label: 'Published' },
  { value: 'future', label: 'Scheduled', needsDate: true },
  { value: 'draft', label: 'Draft' },
  { value: 'pending', label: 'Pending Review' },
  { value: 'private', label: 'Private' },
  { value: 'trash', label: 'Move to Trash', destructive: true },
];

export const isDestructiveStatus = (value: string): boolean =>
  POST_STATUSES.some((s) => s.value === value && s.destructive === true);

/** The label WordPress shows for a status, or the raw value if unknown. */
export const postStatusLabel = (value: string | null | undefined): string =>
  POST_STATUSES.find((s) => s.value === value)?.label ?? (value ? String(value) : '');

export interface PostStatusTarget {
  id: number;
  /** Non-null ONLY when the article really has a post on a site. */
  articlePublishedPostId?: number | null;
}

export interface BulkPostStatusPlan {
  /** Items that will be sent — every one has a live post. */
  targets: PostStatusTarget[];
  /** Selected items with no live post; nothing to change on the site. */
  skipped: number;
}

/**
 * Which of the ticked items can have their WordPress status changed.
 *
 * An item with no `articlePublishedPostId` has never been published, so there
 * is no remote post to act on — the hub throws "not published to a site yet"
 * for those. Filtering here means a mixed selection still works for the items
 * that CAN change, instead of the batch reporting N failures.
 */
export function planBulkPostStatus(
  items: PostStatusTarget[],
  selectedIds: number[],
): BulkPostStatusPlan {
  const wanted = new Set(selectedIds);
  const selected = items.filter((it) => wanted.has(it.id));
  const targets = selected.filter((it) => !!it.articlePublishedPostId);
  return { targets, skipped: selected.length - targets.length };
}
