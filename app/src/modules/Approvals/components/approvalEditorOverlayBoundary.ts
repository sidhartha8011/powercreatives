/**
 * External overlays opened by the approval document editor.
 *
 * WordPress media frames and the image annotator portal to `document.body`,
 * outside any Radix dialog containing the editor. Radix must therefore be told
 * that interactions with these overlays belong to the editor instead of being
 * treated as a request to dismiss the containing approval dialog.
 */
const APPROVAL_EDITOR_OVERLAY_SELECTOR = [
  '.media-modal',
  '.media-frame',
  '.media-modal-backdrop',
  '[data-pcm-annotator]',
].join(', ');

export function isApprovalEditorOverlayTarget(target: EventTarget | null): boolean {
  return target instanceof Element
    && target.closest(APPROVAL_EDITOR_OVERLAY_SELECTOR) !== null;
}

export function preventApprovalEditorOverlayDismiss(
  event: { target: EventTarget | null; preventDefault: () => void },
): void {
  if (isApprovalEditorOverlayTarget(event.target)) event.preventDefault();
}

export function isApprovalEditorOverlayOpen(): boolean {
  if (typeof document === 'undefined') return false;

  // Backbone keeps closed media frames in the DOM for reuse. Presence alone
  // therefore cannot mean "open" or Escape would stop closing approval dialogs
  // forever after the first media selection. A rendered overlay has at least
  // one client rect; a cached `display: none` frame does not.
  return Array.from(document.querySelectorAll(APPROVAL_EDITOR_OVERLAY_SELECTOR))
    .some((element) => element.getClientRects().length > 0);
}
