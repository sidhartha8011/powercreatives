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
  /** Mirrors `isDirty` for callbacks that must make a synchronous decision. */
  const dirtyRef = useRef(false);
  /**
   * The one active drain. Every caller joins this promise, so saves can never
   * overlap and a newer value is written only after the older request settles.
   */
  const inFlightRef = useRef<Promise<void> | null>(null);
  /** Used by the retry timer without giving the drain callback a self-dependency. */
  const writeRef = useRef<() => Promise<void>>(async () => undefined);

  // Read through refs so the identity of `save` never restarts a timer. A
  // mutation object is a new identity on every render; a dependency on it would
  // reschedule the window forever and nothing would ever be written.
  const saveRef = useRef(save);
  saveRef.current = save;
  const enabledRef = useRef(enabled);
  enabledRef.current = enabled;

  const write = useCallback((): Promise<void> => {
    if (!enabledRef.current) return Promise.resolve();
    if (inFlightRef.current) return inFlightRef.current;

    let drain: Promise<void>;
    drain = (async () => {
      while (enabledRef.current) {
        const value = latestRef.current;
        if (value === null) return;

        const serialised = JSON.stringify(value);
        if (serialised === savedRef.current) {
          dirtyRef.current = false;
          setIsDirty(false);
          return;
        }

        setState('saving');
        try {
          await saveRef.current(value);
        } catch (error) {
          dirtyRef.current = true;
          setIsDirty(true);
          setState('error');

          // Keep the existing UI contract truthful: an autosave failure is
          // retried after the normal cadence, while an explicit flush still
          // rejects so its caller never closes over an unsaved document.
          if (timerRef.current) clearTimeout(timerRef.current);
          timerRef.current = setTimeout(() => {
            timerRef.current = null;
            void writeRef.current().catch(() => undefined);
          }, delayMs);
          throw error;
        }

        // This exact value is now stored. If the user changed it during the
        // request, loop and write the newer value after this one—not beside it.
        savedRef.current = serialised;
        if (JSON.stringify(latestRef.current) === serialised) {
          if (timerRef.current) {
            clearTimeout(timerRef.current);
            timerRef.current = null;
          }
          dirtyRef.current = false;
          setIsDirty(false);
          setState('saved');
          return;
        }

        dirtyRef.current = true;
        setIsDirty(true);
      }
    })();

    inFlightRef.current = drain;
    const clearInFlight = () => {
      if (inFlightRef.current === drain) inFlightRef.current = null;
    };
    void drain.then(clearInFlight, clearInFlight);
    return drain;
  }, [delayMs]);
  writeRef.current = write;

  const change = useCallback((value: T) => {
    latestRef.current = value;
    if (!enabledRef.current) return;
    const isStored = JSON.stringify(value) === savedRef.current;
    if (isStored && !inFlightRef.current) {
      if (timerRef.current) {
        clearTimeout(timerRef.current);
        timerRef.current = null;
      }
      dirtyRef.current = false;
      setIsDirty(false);
      return;
    }

    dirtyRef.current = true;
    setIsDirty(true);
    setState('pending');
    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => {
      timerRef.current = null;
      void write().catch(() => undefined);
    }, delayMs);
  }, [delayMs, write]);

  const flush = useCallback(async (): Promise<void> => {
    if (timerRef.current) { clearTimeout(timerRef.current); timerRef.current = null; }
    await write();
  }, [write]);

  const reset = useCallback((value: T) => {
    // A query refetch can resolve after the user has typed something newer.
    // Never let that older server echo replace a dirty or in-flight draft.
    if (dirtyRef.current || inFlightRef.current) return;
    latestRef.current = value;
    savedRef.current = JSON.stringify(value);
    dirtyRef.current = false;
    setIsDirty(false);
    setState('idle');
  }, []);

  // A pending timer must not outlive the component; `flush` is the caller's
  // deliberate way to land the last change before going away.
  useEffect(() => () => { if (timerRef.current) clearTimeout(timerRef.current); }, []);

  return { change, flush, reset, isDirty, state };
}
