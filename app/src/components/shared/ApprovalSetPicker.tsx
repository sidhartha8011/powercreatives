/**
 * ApprovalSetPicker — searchable dropdown of approval sets that can still
 * receive assets (not in a post-submit lane: draft / internal / client).
 *
 * Used by the share dialogs' "Add to existing set" mode (Copy/Image shared
 * dialog + Ads dialog). Fully approved sets auto-advance to 'launch', so
 * filtering by lane also excludes them; the append endpoint re-checks
 * server-side and returns 409 pcm_set_locked as the authority.
 *
 * Rendered WITHOUT a Portal on purpose — it lives inside a Radix Dialog,
 * whose scroll lock blocks wheel/touch on portalled popovers. Same proven
 * pattern as the Automations ItemCombobox (inline content, height bounded
 * on the content element, redundant max-h on the CommandList).
 */

import { useMemo, useState } from 'react';
import { Check, ChevronsUpDown } from 'lucide-react';
import * as PopoverPrimitive from '@radix-ui/react-popover';

import { Button } from '@/components/ui/button';
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command';
import { Popover, PopoverTrigger } from '@/components/ui/popover';
import { trpc } from '@/lib/trpc';
import { cn } from '@/lib/utils';

/** Lanes that can still receive assets (pre client sign-off). */
const APPENDABLE_STATUSES = ['draft', 'internal', 'client'];

export interface AppendableSet {
  id: number;
  name: string;
  status: string;
}

interface ApprovalSetPickerProps {
  /** Selected set id, or null. */
  value: number | null;
  onChange: (set: AppendableSet | null) => void;
  disabled?: boolean;
}

export function ApprovalSetPicker({ value, onChange, disabled }: ApprovalSetPickerProps) {
  const [open, setOpen] = useState(false);

  const { data: setsRaw } = trpc.approvals.listSets.useQuery() as { data?: unknown };
  const sets = useMemo<AppendableSet[]>(() => {
    if (!Array.isArray(setsRaw)) return [];
    return (setsRaw as any[])
      .filter((s) => APPENDABLE_STATUSES.includes(String(s.status)))
      .map((s) => ({ id: Number(s.id), name: String(s.name), status: String(s.status) }));
  }, [setsRaw]);

  const selected = sets.find((s) => s.id === value) ?? null;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          className="w-full justify-between font-normal"
        >
          <span className="truncate text-left">
            {selected ? selected.name : <span className="text-muted-foreground">Select an approval set…</span>}
          </span>
          <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverPrimitive.Content
        align="start"
        sideOffset={4}
        collisionPadding={8}
        className={cn(
          'z-50 flex max-h-80 w-[--radix-popover-trigger-width] flex-col overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md outline-hidden',
          'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
        )}
      >
        <Command className="flex min-h-0 flex-1 flex-col">
          <CommandInput placeholder="Search sets…" />
          <CommandList className="min-h-0 max-h-72 flex-1 overflow-y-auto">
            <CommandEmpty>No open approval sets.</CommandEmpty>
            <CommandGroup>
              {sets.map((s) => (
                <CommandItem
                  key={s.id}
                  value={`${s.name} ${s.id}`}
                  onSelect={() => {
                    onChange(s.id === value ? null : s);
                    setOpen(false);
                  }}
                >
                  <Check className={cn('mr-2 h-4 w-4', s.id === value ? 'opacity-100' : 'opacity-0')} />
                  <span className="truncate">{s.name}</span>
                  <span className="ml-auto pl-2 text-xs text-muted-foreground capitalize shrink-0">{s.status}</span>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverPrimitive.Content>
    </Popover>
  );
}
