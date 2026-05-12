/**
 * OFFER TEMPLATE SELECT
 *
 * Quick-fill dropdown that applies a pre-built offer template to the form.
 * Filtered by the applicable copy type.
 */

import type { CopyType, CopyFormValues } from '../types';
import { COPY_TYPE_LABELS } from '../types';
import { OFFER_TEMPLATES } from '../copyConfig';

interface Props {
  copyType: CopyType;
  onApply: (values: Partial<CopyFormValues>) => void;
}

export function OfferTemplateSelect({ copyType, onApply }: Props) {
  const templates = OFFER_TEMPLATES.filter((t) => t.applicableTo === copyType);

  if (templates.length === 0) return null;

  return (
    <div>
      <label
        className="flex items-center gap-1.5 text-xs font-semibold mb-1.5"
        style={{ color: '#555' }}
      >
        {COPY_TYPE_LABELS[copyType]} Template
      </label>
      <select
        defaultValue=""
        onChange={(e) => {
          const tpl = templates.find((t) => t.id === e.target.value);
          if (tpl) onApply(tpl.fields);
          // Reset select so user can re-apply same template
          e.target.value = '';
        }}
        className="w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
        style={{ borderColor: '#e5e7eb', background: '#fff', color: '#999' }}
      >
        <option value="">Apply a template…</option>
        {templates.map((tpl) => (
          <option key={tpl.id} value={tpl.id}>
            {tpl.name}
          </option>
        ))}
      </select>
    </div>
  );
}
