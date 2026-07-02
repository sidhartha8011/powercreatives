/**
 * Logs — central place for the platform's activity logs.
 *
 * Skeleton by design: LOG_TYPES is the extension point. Each log type is a
 * self-contained panel component; adding a future type (generation logs,
 * webhook logs, connector logs, …) = add its component + one entry here.
 * Email (Brevo send log + diagnostics) is the first resident, moved from
 * the Automations page.
 */
import { useState } from 'react';
import { Mail, ScrollText } from 'lucide-react';
import { EmailDiagnostics } from './EmailDiagnostics';

interface LogType {
  id: string;
  label: string;
  icon: React.ReactNode;
  /** Panel rendered when this type is active. */
  component: React.ComponentType;
}

// ── Extension point: register new log types here ──
const LOG_TYPES: LogType[] = [
  { id: 'email', label: 'Email', icon: <Mail className="w-4 h-4" />, component: EmailDiagnostics },
  // e.g. { id: 'generation', label: 'Generation', icon: <Sparkles …/>, component: GenerationLogs },
];

export function LogsModule() {
  const [active, setActive] = useState<string>(LOG_TYPES[0]?.id ?? '');
  const ActivePanel = LOG_TYPES.find((t) => t.id === active)?.component;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold flex items-center gap-2">
          <ScrollText className="w-6 h-6" />
          Logs
        </h1>
        <p className="text-sm text-muted-foreground mt-1">
          Activity logs across the platform — pick a log type to inspect.
        </p>
      </div>

      {/* Log-type switcher (a simple pill row; grows with LOG_TYPES) */}
      <div className="flex items-center gap-2">
        {LOG_TYPES.map((t) => (
          <button
            key={t.id}
            onClick={() => setActive(t.id)}
            className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition-colors ${
              active === t.id
                ? 'border-primary bg-primary text-primary-foreground'
                : 'border-border bg-card text-muted-foreground hover:text-foreground'
            }`}
          >
            {t.icon}
            {t.label}
          </button>
        ))}
      </div>

      {ActivePanel ? <ActivePanel /> : null}
    </div>
  );
}
