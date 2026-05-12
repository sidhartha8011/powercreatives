import type { WriterSectionConfig } from './types';
import { STYLE_PRESETS } from '@/modules/Image/imageConfig';

/** Single source of truth for Writer generation defaults.
 *  Used by ContextGenerationPanel (frontend) and writer/service.php (backend). */
export const WRITER_DEFAULTS = {
  format: 'deep_dive',
  perspective: 'first_plural',
  toneOfVoice: 'professional',
} as const;


export const WRITER_SECTIONS: WriterSectionConfig[] = [
  {
    id: 'primary_context',
    title: 'Keywords',
    defaultCollapsed: false,
    fields: [
      {
        id: 'primaryKeyword',
        label: 'Primary Keyword',
        inputType: 'text',
        placeholder: 'e.g. B2B SaaS Marketing',
        width: 'full',
      },
      {
        id: 'supportingKeywords',
        label: 'Supporting Keywords / Questions',
        inputType: 'textarea',
        placeholder: 'Paste related terms, entities, or PAAs here...',
        helpText: 'AI will organically include these terms.',
        width: 'full',
      },
    ]
  },
  {
    id: 'prompt_instructions',
    title: 'Text Prompt / Brief',
    defaultCollapsed: false,
    fields: [
      {
        id: 'writerPrompt',
        label: 'Content Brief & Instructions',
        inputType: 'textarea',
        placeholder: 'Write a comprehensive guide about...',
        helpText: 'Specific instructions for the AI on how to write the content.',
        width: 'full',
      },
      {
        id: 'format',
        label: 'Format',
        inputType: 'select',
        options: [
          { value: 'deep_dive', label: 'Deep Dive / Guide' },
          { value: 'listicle', label: 'Listicle' },
          { value: 'how_to', label: 'How-to Tutorial' },
          { value: 'news', label: 'News Article' },
          { value: 'review', label: 'Product Review' },
        ],
        defaultValue: 'deep_dive',
        width: 'full',
      },
    ]
  },
  {
    id: 'content_hierarchy',
    title: 'Content Hierarchy (Silo)',
    defaultCollapsed: true,
    fields: [
      {
        id: 'contentHierarchyParent',
        label: 'Parent (Pillar Page)',
        inputType: 'url',
        placeholder: 'e.g. https://domain.com/pillar-page',
        helpText: 'The main cluster page to link up to.',
        width: 'full',
      },
      {
        id: 'contentHierarchyChildren',
        label: 'Children (Sub-pages)',
        inputType: 'dynamic_list',
        placeholder: 'https://domain.com/child-reference',
        helpText: 'Deep links to interlink organically within text.',
        width: 'full',
        dynamicListPrefix: 'child_url',
      },
    ]
  },
  {
    id: 'advanced_ai',
    title: 'Advanced AI Settings',
    defaultCollapsed: true,
    fields: [
      {
        id: 'toneOfVoice',
        label: 'Tone of Voice',
        inputType: 'select',
        options: [
          { value: 'professional', label: 'Professional & Authoritative' },
          { value: 'conversational', label: 'Conversational & Friendly' },
          { value: 'educational', label: 'Educational & Objective' },
          { value: 'witty', label: 'Witty & Engaging' },
          { value: 'urgent', label: 'Action-Oriented' },
        ],
        defaultValue: 'professional',
        width: 'half',
      },
      {
        id: 'perspective',
        label: 'Perspective',
        inputType: 'select',
        options: [
          { value: 'first_plural', label: 'We / Our (Brand Voice)' },
          { value: 'first_singular', label: 'I / My (Personal)' },
          { value: 'second_person', label: 'You / Your (Direct)' },
          { value: 'third_person', label: 'They / It (Objective)' },
        ],
        defaultValue: 'first_plural',
        width: 'half',
      },
    ]
  },
  {
    id: 'image_generation',
    title: 'Image Generation',
    defaultCollapsed: false,
    fields: [
      {
        id: 'imageCount',
        label: 'Number of Images',
        inputType: 'select',
        options: [
          { value: '0', label: 'No Images (0)' },
          { value: '1', label: '1 Image' },
          { value: '2', label: '2 Images' },
          { value: '3', label: '3 Images' },
          { value: '4', label: '4 Images' },
          { value: '5', label: '5 Images' },
        ],
        defaultValue: '2',
        width: 'full',
      },
      {
        id: 'imageStyle',
        label: 'Visual Style',
        inputType: 'select',
        options: STYLE_PRESETS.map(s => ({ value: s.id, label: s.label })),
        defaultValue: 'auto',
        width: 'half',
      },
      {
        id: 'imageModel',
        label: 'AI Model',
        inputType: 'select',
        // Options will be injected dynamically by WriterDynamicSection
        options: [{ value: 'auto', label: 'Loading models...' }],
        defaultValue: 'auto',
        width: 'half',
      },
    ]
  }
];

export function buildDefaultWriterFormValues(): Record<string, any> {
  const values: Record<string, any> = {};
  for (const section of WRITER_SECTIONS) {
    for (const field of section.fields) {
      if (field.defaultValue !== undefined) {
        values[field.id] = field.defaultValue;
      }
    }
  }
  return values;
}
