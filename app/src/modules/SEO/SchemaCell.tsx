/**
 * SchemaCell — per-row Schema.org type editor.
 *
 * A popover of the supported types; toggling persists via seo.setSchema and
 * patches the row cache. The trigger shows the active count.
 */

import { useState } from 'react';
import { ChevronDown } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { trpc } from '@/lib/trpc';
import { SCHEMA_TYPES } from './types';

export function SchemaCell({
  postId,
  types,
  onChange,
}: {
  postId: number;
  types: string[];
  onChange: (next: string[]) => void;
}) {
  const [open, setOpen] = useState(false);
  const setSchema = trpc.seo.setSchema.useMutation();

  const toggle = (type: string) => {
    const next = types.includes(type) ? types.filter((t) => t !== type) : [...types, type];
    onChange(next); // optimistic
    setSchema.mutate({ id: postId, types: next });
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="outline" size="sm" className="h-7 gap-1 text-xs font-normal">
          {types.length > 0 ? `${types.length} type${types.length > 1 ? 's' : ''}` : 'None'}
          <ChevronDown className="w-3 h-3 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-44 p-2" align="start">
        <div className="space-y-1.5">
          {SCHEMA_TYPES.map((type) => (
            <div key={type} className="flex items-center gap-2">
              <Checkbox id={`schema-${postId}-${type}`} checked={types.includes(type)} onCheckedChange={() => toggle(type)} />
              <Label htmlFor={`schema-${postId}-${type}`} className="text-xs font-normal cursor-pointer">{type}</Label>
            </div>
          ))}
        </div>
      </PopoverContent>
    </Popover>
  );
}
