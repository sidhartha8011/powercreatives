import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Autosave: persist a value a moment after the user stops changing it.
 *
 * This is the Writer's autosave contract, extracted so there is ONE definition
 * of "autosave" in the app rather than a second copy per module —
 * `useWriterPersistence` has carried it since the Writer shipped:
 *   • a debounce window, so a keystroke is not a request
 *   • a dirty check, so an unchanged value is never written
 *   • silent failure, because an autosave must not interrupt someone writing
 *
 * It exists because saving only on close loses work. The approval card did
 * exactly that: an image upload that finished 14 seconds AFTER the card closed
 * was handed to a destroyed editor, and the card had already written its old
 * content over itself (approval set 25, 2026-08-06).
 *
 * The value is compared by its serialised form, so callers pass plain data and
 * never have to memoise it.
 */

/** Matches the Writer's window (`AUTOSAVE_DEBOUNCE_MS`) — one cadence app-wide. */
export const AUTOSAVE_DEBOUNCE_MS = 2_000;

export type SaveState = 'idle' | 'pending' | 'saving' | 'saved' | 'error';

export interface UseDebouncedSaveOptions<T> {
  /**
   * Performs the write. Rejecting marks the state `error` and keeps the value
   * dirty, so the next change retries rather than silently dropping it.
   */
  save: (value: T) => Promise<unknown>;
  /** Override the debounce window. Defaults to the app-wide cadence. */
  delayMs?: number;
  /**
   * Autosave is off while this is false — used for a read-only viewer, so a
   * client's copy of a document can never write back.
   */
  enabled?: boolean;
}

export interface UseDebouncedSave<T> {
  /** Record a change. Starts (or restarts) the debounce window. */
  change: (value: T) => void;
  /**
   * Write immediately, skipping the window, and resolve when it lands.
   * Await this before unmounting so nothing in flight is lost.
   */
  flush: () => Promise<void>;
  /** Adopt a value as already-saved — after a refetch, so it is not re-sent. */
  reset: (value: T) => void;
  /** True while there is an unwritten change. */
  isDirty: boolean;
  state: SaveState;
}

export function useDebouncedSave<T>({
  save,
  delayMs = AUTOSAVE_DEBOUNCE_MS,
  enabled = true,
}: UseDebouncedSaveOptions<T>): UseDebouncedSave<T> {
  const [state, setState] = useState<SaveState>('idle');
  const [isDirty, setIsDirty] = useState(false);

  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  /** Latest value seen, saved or not. */
  const latestRef = useRef<T | null>(null);
  /** Serialised form of what is known to be stored — the dirty check. */
  const savedRef = useRef<string | null>(null);
  /** In-flight write, so `flush` can await a save already under way. */
  const inFlightRef = useRef<Promise<unknown> | null>(null);

  // Read through refs so the identity of `save` never restarts a timer. A
  // mutation object is a new identity on every render; a dependency on it would
  // reschedule the window forever and nothing would ever be written.
  const saveRef = useRef(save);
  saveRef.current = save;
  const enabledRef = useRef(enabled);
  enabledRef.current = enabled;

  const write = useCallback(async (): Promise<void> => {
    if (!enabledRef.current) return;
    const value = latestRef.current;
    if (value === null) return;

    const serialised = JSON.stringify(value);
    if (serialised === savedRef.current) return; // Nothing changed.

    setState('saving');
    const promise = saveRef.current(value);
    inFlightRef.current = promise;
    try {
      await promise;
      // Only mark clean if nothing newer arrived while the write was running.
      if (JSON.stringify(latestRef.current) === serialised) {
        savedRef.current = serialised;
        setIsDirty(false);
        setState('saved');
      }
    } catch {
      // Silent by contract: an autosave failure must not interrupt writing.
      // The value stays dirty, so the next change retries it.
      setState('error');
    } finally {
      if (inFlightRef.current === promise) inFlightRef.current = null;
    }
  }, []);

  const change = useCallback((value: T) => {
    latestRef.current = value;
    if (!enabledRef.current) return;
    if (JSON.stringify(value) === savedRef.current) return; // Back to stored.

    setIsDirty(true);
    setState('pending');
    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => { void write(); }, delayMs);
  }, [delayMs, write]);

  const flush = useCallback(async (): Promise<void> => {
    if (timerRef.current) { clearTimeout(timerRef.current); timerRef.current = null; }
    if (inFlightRef.current) await inFlightRef.current.catch(() => undefined);
    await write();
  }, [write]);

  const reset = useCallback((value: T) => {
    latestRef.current = value;
    savedRef.current = JSON.stringify(value);
    setIsDirty(false);
    setState('idle');
  }, []);

  // A pending timer must not outlive the component; `flush` is the caller's
  // deliberate way to land the last change before going away.
  useEffect(() => () => { if (timerRef.current) clearTimeout(timerRef.current); }, []);

  return { change, flush, reset, isDirty, state };
}
