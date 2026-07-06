/**
 * GscSetupGuide — collapsible, Notion-style setup instructions for creating the Google
 * Cloud OAuth client that "Connect with Google" needs. The body reproduces the client's
 * "Search Console API Setup (from GCP)" document (Integrations.pdf) verbatim, with one
 * clearly-marked plugin-specific callout at Step 5 (this plugin needs a *Web application*
 * client, not the doc's Desktop app). A button toggles it — hidden by default.
 */
import { useState, type ReactNode } from 'react';
import { BookOpen, ChevronDown, ChevronRight, Check, AlertTriangle, Info, ExternalLink } from 'lucide-react';

/** Notion-style inline code. */
function Code({ children }: { children: ReactNode }) {
  return <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[12px] text-foreground/90">{children}</code>;
}

/** Notion-style callout box (icon + tinted panel). */
function Callout({ tone = 'gray', icon, children }: { tone?: 'gray' | 'amber' | 'blue'; icon: ReactNode; children: ReactNode }) {
  const tones: Record<string, string> = {
    gray: 'border-border bg-muted/50',
    amber: 'border-amber-300/60 bg-amber-50 dark:border-amber-800/50 dark:bg-amber-950/25',
    blue: 'border-blue-300/60 bg-blue-50 dark:border-blue-800/50 dark:bg-blue-950/25',
  };
  return (
    <div className={`flex gap-2.5 rounded-lg border px-3 py-2.5 ${tones[tone]}`}>
      <span className="mt-0.5 shrink-0">{icon}</span>
      <div className="text-[13px] leading-[1.6] text-foreground/80">{children}</div>
    </div>
  );
}

/** Section heading — Notion H2 feel. */
function Step({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-2.5">
      <h4 className="text-[14.5px] font-semibold text-foreground">{title}</h4>
      {children}
    </section>
  );
}

/** Numbered list, Notion-style. */
function OL({ children }: { children: ReactNode }) {
  return <ol className="ml-0.5 space-y-1.5 text-[13.5px] leading-[1.6] text-foreground/80">{children}</ol>;
}
function LI({ n, children }: { n: number; children: ReactNode }) {
  return (
    <li className="flex gap-2.5">
      <span className="mt-[1px] flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-muted-foreground">{n}</span>
      <span className="min-w-0">{children}</span>
    </li>
  );
}

export function GscSetupGuide() {
  const [open, setOpen] = useState(false);

  return (
    <div>
      {/* The button — instructions are hidden until clicked. */}
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2 text-left text-sm font-medium text-foreground transition-colors hover:bg-muted/60"
      >
        <BookOpen className="h-4 w-4 shrink-0 text-primary" />
        <span className="flex-1">Google Cloud setup guide — how to get the Client ID &amp; Secret</span>
        {open ? <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground" /> : <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />}
      </button>

      {open && (
        /* Notion "page": readable column, comfortable spacing, warm typography. */
        <div className="mt-2 space-y-5 rounded-xl border border-border bg-card p-5 sm:p-6">
          <header className="space-y-2">
            <h3 className="text-lg font-semibold text-foreground">Search Console API Setup</h3>
            <p className="text-[13.5px] leading-[1.65] text-foreground/75">
              <span className="font-medium text-foreground">What you’re doing:</span> a one-time setup in Google Cloud so
              our developer can pull Search Console data automatically. Takes about 15 minutes. You only click around in
              the browser — no code.
            </p>
          </header>

          <Callout tone="gray" icon={<Check className="h-4 w-4 text-emerald-600" />}>
            <p className="mb-1 font-medium text-foreground">Before you start, make sure:</p>
            <ul className="list-disc space-y-1 pl-4">
              <li>You’re logged into the central Google account (the one that has been added to all the client Search Console properties — not your personal account).</li>
              <li>Billing is already active on the account (you mentioned a paid subscription, so this should be fine — the Search Console API itself is free anyway).</li>
            </ul>
          </Callout>

          <hr className="border-border" />

          <Step title="Step 1 — Create a project">
            <OL>
              <LI n={1}>Go to{' '}
                <a href="https://console.cloud.google.com" target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-0.5 text-primary underline-offset-2 hover:underline">
                  https://console.cloud.google.com <ExternalLink className="h-3 w-3" />
                </a>
              </LI>
              <LI n={2}>At the top left, next to the “Google Cloud” logo, click the project dropdown → <span className="font-medium text-foreground">New Project</span>.</LI>
              <LI n={3}>Name it something recognizable, e.g. <Code>SEO Data Pipeline</Code>. Leave organization/location as-is.</LI>
              <LI n={4}>Click <span className="font-medium text-foreground">Create</span>, then make sure this new project is selected in that same dropdown (the name should show at the top of the page). Everything below happens inside this project.</LI>
            </OL>
          </Step>

          <Step title="Step 2 — Enable the Search Console API">
            <OL>
              <LI n={1}>In the search bar at the very top of the page, type “Google Search Console API”.</LI>
              <LI n={2}>Click the result (under “Marketplace” or “APIs &amp; Services”).</LI>
              <LI n={3}>Click the blue <span className="font-medium text-foreground">Enable</span> button. Wait until the page changes — done.</LI>
              <LI n={4}>Repeat for the <span className="font-medium text-foreground">“Site Verification API”</span> (search it the same way → Enable) — the plugin uses it to auto-verify newly added sites.</LI>
            </OL>
          </Step>

          <Step title="Step 3 — Set up the consent screen">
            <OL>
              <LI n={1}>In the top search bar, type “OAuth consent screen” and open it.</LI>
              <LI n={2}>If asked to choose a user type: pick <span className="font-medium text-foreground">External</span> → Create. (Internal will be greyed out — that’s expected, it’s Workspace-only.)</LI>
              <LI n={3}>
                Fill in only the required fields:
                <ul className="mt-1 list-disc space-y-0.5 pl-4">
                  <li><span className="font-medium text-foreground">App name:</span> SEO Data Pipeline</li>
                  <li><span className="font-medium text-foreground">User support email:</span> select the account’s email from the dropdown</li>
                  <li><span className="font-medium text-foreground">Developer contact email:</span> same email</li>
                </ul>
              </LI>
              <LI n={4}>Click <span className="font-medium text-foreground">Save and Continue</span> through the remaining pages (Scopes and Test users — leave both empty, don’t add anything) until you reach the summary, then <span className="font-medium text-foreground">Back to Dashboard</span>.</LI>
            </OL>
          </Step>

          <Step title="Step 4 — Publish the app (most important step)">
            <OL>
              <LI n={1}>Still on the OAuth consent screen page, find <span className="font-medium text-foreground">Publishing status</span>. It will say “Testing”.</LI>
              <LI n={2}>Click <span className="font-medium text-foreground">Publish App</span> → confirm.</LI>
              <LI n={3}>It now says “In production”. You do not need to submit for verification — if a message mentions verification, ignore/dismiss it. We’re the only user of this app.</LI>
            </OL>
            <Callout tone="amber" icon={<AlertTriangle className="h-4 w-4 text-amber-600" />}>
              <span className="font-medium text-foreground">Why this matters:</span> if the app stays in “Testing”, the
              developer’s access automatically breaks every 7 days. Published = permanent.
            </Callout>
          </Step>

          <Step title="Step 5 — Create the credentials">
            <OL>
              <LI n={1}>In the left menu (or top search bar), go to APIs &amp; Services → <span className="font-medium text-foreground">Credentials</span>.</LI>
              <LI n={2}>Click <span className="font-medium text-foreground">+ Create Credentials</span> (top of page) → OAuth client ID.</LI>
              <LI n={3}>Application type: choose <span className="font-medium text-foreground">Desktop app</span>.</LI>
              <LI n={4}>Name: <Code>gsc-pipeline</Code> → click Create.</LI>
              <LI n={5}>A popup appears — click <span className="font-medium text-foreground">Download JSON</span>. A file like <Code>client_secret_xxxxx.json</Code> lands in your Downloads folder.</LI>
            </OL>
            <Callout tone="blue" icon={<Info className="h-4 w-4 text-blue-600" />}>
              <span className="font-medium text-foreground">Connecting through this plugin?</span> Choose{' '}
              <span className="font-medium text-foreground">Web application</span> (instead of Desktop app), add the
              redirect URI shown in the “Connect with Google” box above, then paste the resulting{' '}
              <span className="font-medium text-foreground">Client ID</span> and{' '}
              <span className="font-medium text-foreground">Client Secret</span> there — no JSON download needed.
            </Callout>
          </Step>

          <Step title="Step 6 — Hand off to the developer">
            <p className="text-[13.5px] leading-[1.6] text-foreground/80">
              Send the developer, via password manager or another secure channel (not plain email or Slack):
            </p>
            <OL>
              <LI n={1}>The downloaded JSON file from Step 5.</LI>
              <LI n={2}>The email + password of the central Google account (or better: sit together for 5 minutes when they do their one-time authorization — they need to log in as this account once, approve the access, and after that they never need the login again).</LI>
            </OL>
            <Callout tone="gray" icon={<Info className="h-4 w-4 text-muted-foreground" />}>
              During that one-time approval, a warning saying “Google hasn’t verified this app” will appear. That’s normal
              and expected — it’s our own internal app. Click <span className="font-medium text-foreground">Advanced</span> →{' '}
              Go to SEO Data Pipeline (unsafe) → Allow.
            </Callout>
          </Step>
        </div>
      )}
    </div>
  );
}
