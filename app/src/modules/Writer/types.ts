export interface SelectOption {
  value: string;
  label: string;
}

export interface WriterFieldConfig {
  id: string;
  label: string;
  inputType: 'text' | 'textarea' | 'select' | 'dynamic_list' | 'url' | 'searchable_select' | 'switch';
  placeholder?: string;
  options?: SelectOption[];
  defaultValue?: string | number;
  width?: 'full' | 'half';
  helpText?: string;
  /** Custom dynamic list prefix (e.g. "child_url") */
  dynamicListPrefix?: string;
}

export interface WriterSectionConfig {
  id: string;
  title: string;
  defaultCollapsed?: boolean;
  fields: WriterFieldConfig[];
}
