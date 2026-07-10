/**
 * Pill — THE app-wide value chip (Airtable-style single-select). One place owns
 * the pill's entire design: geometry AND every color variant. Consumers pick a
 * semantic variant and render — no inline sizes, no inline colors, ever.
 *
 *   <Pill variant="h2">H2</Pill>
 *   <Pill variant="publish" className="capitalize">publish</Pill>
 *
 * `pillVariants` is exported (buttonVariants convention) for elements that must
 * LOOK like a pill without being one (e.g. a Select trigger).
 */

import { type HTMLAttributes } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

export const pillVariants = cva(
  // Icon law: an icon inside a pill is 10px, muted, non-interactive — defined
  // here once so pill-shaped triggers need zero call-site overrides.
  'inline-flex items-center gap-0.5 rounded-full px-1.5 py-0 text-[9px] font-medium whitespace-nowrap [&_svg]:pointer-events-none [&_svg]:size-2.5 [&_svg]:shrink-0 [&_svg]:opacity-50',
  {
    variants: {
      variant: {
        // Content statuses (the SEO table's Status column).
        publish: 'bg-green-100 text-green-700',
        pending: 'bg-amber-100 text-amber-700',
        private: 'bg-purple-100 text-purple-700',
        future: 'bg-blue-100 text-blue-700',
        draft: 'bg-muted text-muted-foreground',
        // Heading levels (the outline's H1–H6 chips).
        h1: 'bg-blue-100 text-blue-700',
        h2: 'bg-green-100 text-green-700',
        h3: 'bg-amber-100 text-amber-700',
        h4: 'bg-orange-100 text-orange-700',
        h5: 'bg-rose-100 text-rose-700',
        h6: 'bg-violet-100 text-violet-700',
        // Outline row kinds.
        p: 'bg-muted text-muted-foreground',
        new: 'bg-primary/10 text-primary',
      },
    },
    defaultVariants: { variant: 'draft' },
  },
);

export type PillVariant = NonNullable<VariantProps<typeof pillVariants>['variant']>;

export interface PillProps extends HTMLAttributes<HTMLSpanElement>, VariantProps<typeof pillVariants> {}

export function Pill({ className, variant, ...props }: PillProps) {
  return <span {...props} className={cn(pillVariants({ variant }), className)} />;
}
