/**
 * Clipboard hook — thin wrapper around copyToClipboard() with React feedback state.
 * Shows success toast + sets `copied` to true for 2s after successful copy.
 */

import { useState, useCallback, useRef } from 'react';
import { toast } from 'sonner';
import { copyToClipboard } from '@/lib/utils';

export function useClipboard(feedbackMs = 2000) {
    const [copied, setCopied] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout>>();

    const copy = useCallback(async (text: string) => {
        clearTimeout(timer.current);
        const ok = await copyToClipboard(text);
        if (ok) {
            setCopied(true);
            toast.success('Copied to clipboard');
            timer.current = setTimeout(() => setCopied(false), feedbackMs);
        } else {
            toast.error('Could not copy to clipboard');
        }
    }, [feedbackMs]);

    return { copy, copied };
}
