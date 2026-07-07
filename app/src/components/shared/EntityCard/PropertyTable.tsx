/**
 * PropertyTable — the declarative properties row of an EntityCard.
 *
 * Modules describe their properties as data (PropertyDef[]), not JSX:
 * text / select / multiToggle controls with an onSave per property. Cells
 * follow the "text at rest, control on demand" rule: no borders or fills
 * until hover/focus, muted empty-labels instead of blank cells, fixed
 * column widths so nothing jitters when values change.
 *
 * Domain-agnostic: this file knows nothing about deliveries, projects or
 * any other module.
 */

import { useEffect, useState } from 'react';
import { ChevronDown } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

export interface SelectOption {
  value: string;
  label: string;
  /** Optional status-dot color (the one place color carries meaning). */
  dot?: string;
}

export interface ToggleOption {
  id: string;
  label: string;
}

export type PropertyDef =
  | {
      control: 'text';
      key: string;
      label: string;
      value: string;
      placeholder?: string;
      /** Persist a changed value ('' = cleared). Called on blur, only when changed. */
      onSave: (next: string) => void;
      width?: string;
    }
  | {
      control: 'select';
      key: string;
      label: string;
      value: string | null;
      options: SelectOption[];
      /** Muted label for the empty choice, e.g. "No brand". */
      noneLabel: string;
      onSave: (next: string | null) => void;
      width?: string;
    }
  | {
      control: 'multiToggle';
      key: string;
      label: string;
      values: string[];
      options: ToggleOption[];
      popoverLabel?: string;
      onSave: (next: string[]) => void;
      width?: string;
    };

/** Sentinel for the empty select choice (Radix forbids an empty item value). */
const NONE = '__none__';

export function PropertyTable({ properties }: { properties: PropertyDef[] }) {
  return (
    <div className="rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            {properties.map((p) => (
              <TableHead key={p.key} className="text-xs" style={p.width ? { width: p.width } : undefined}>
                {p.label}
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow className="hover:bg-transparent">
            {properties.map((p) => (
              <TableCell key={p.key} className="p-1 align-middle">
                <PropertyCell def={p} />
              </TableCell>
            ))}
          </TableRow>
        </TableBody>
      </Table>
    </div>
  );
}

function PropertyCell({ def }: { def: PropertyDef }) {
  if (def.control === 'text') return <TextCell def={def} />;
  if (def.control === 'select') return <SelectCell def={def} />;
  return <MultiToggleCell def={def} />;
}

function TextCell({ def }: { def: Extract<PropertyDef, { control: 'text' }> }) {
  const [draft, setDraft] = useState(def.value);
  useEffect(() => setDraft(def.value), [def.value]);

  return (
    <Input
      value={draft}
      onChange={(e) => setDraft(e.target.value)}
      onBlur={() => {
        const trimmed = draft.trim();
        if (trimmed !== def.value) def.onSave(trimmed);
      }}
      placeholder={def.placeholder ?? 'Empty'}
      aria-label={def.label}
      maxLength={256}
      className="h-8 rounded border-none bg-transparent px-2 text-xs shadow-none cursor-text hover:bg-slate-50 focus-visible:ring-1 placeholder:text-muted-foreground"
    />
  );
}

function SelectCell({ def }: { def: Extract<PropertyDef, { control: 'select' }> }) {
  return (
    <Select
      value={def.value ?? NONE}
      onValueChange={(v) => def.onSave(v === NONE ? null : v)}
    >
      <SelectTrigger
        aria-label={def.label}
        className={`h-8 w-full rounded border-none bg-transparent px-2 text-xs shadow-none hover:bg-slate-50 focus-visible:ring-1 ${def.value == null ? 'text-muted-foreground' : ''}`}
      >
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={NONE} className="text-muted-foreground">{def.noneLabel}</SelectItem>
        {def.options.map((o) => (
          <SelectItem key={o.value} value={o.value}>
            <span className="flex items-center gap-1.5">
              {o.dot && <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: o.dot }} />}
              {o.label}
            </span>
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

function MultiToggleCell({ def }: { def: Extract<PropertyDef, { control: 'multiToggle' }> }) {
  const selected = def.options.filter((o) => def.values.includes(o.id));
  const toggle = (id: string) => {
    const next = def.values.includes(id) ? def.values.filter((v) => v !== id) : [...def.values, id];
    def.onSave(next);
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          aria-label={def.label}
          className="h-8 w-full justify-between gap-1 rounded px-2 text-xs font-normal hover:bg-slate-50"
        >
          {/* Chips, not a sentence — scannable at a glance. */}
          <span className="flex min-w-0 items-center gap-1">
            {selected.length === 0 && <span className="text-muted-foreground">None</span>}
            {selected.slice(0, 2).map((o) => (
              <Badge key={o.id} variant="secondary" className="px-1.5 py-0 text-[10px] font-normal">{o.label}</Badge>
            ))}
            {selected.length > 2 && (
              <Badge variant="secondary" className="px-1.5 py-0 text-[10px] font-normal">+{selected.length - 2}</Badge>
            )}
          </span>
          <ChevronDown className="h-3 w-3 shrink-0 text-muted-foreground" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-56">
        {def.popoverLabel && (
          <>
            <DropdownMenuLabel className="text-xs">{def.popoverLabel}</DropdownMenuLabel>
            <DropdownMenuSeparator />
          </>
        )}
        {def.options.map((o) => (
          <DropdownMenuCheckboxItem
            key={o.id}
            className="text-xs"
            checked={def.values.includes(o.id)}
            onCheckedChange={() => toggle(o.id)}
            onSelect={(e) => e.preventDefault()}
          >
            {o.label}
          </DropdownMenuCheckboxItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
