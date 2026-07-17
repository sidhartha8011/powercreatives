/**
 * CREATIVE MACHINE - Shell Component
 * Main layout shell that orchestrates module rendering
 * This is the core of the micro-frontend architecture
 * 
 * PowerKeys Specs:
 * - Main content background: #f8f9fa
 * - Content padding: 1.25rem (for standard modules)
 * - Full-bleed modules (image, video) get no padding
 */

import { useApp } from '@/contexts/AppContext';
import { Sidebar } from './Sidebar';
import { ProjectsModule } from '@/modules/Projects';
import { ImageModule } from '@/modules/Image';
import { VideoModule } from '@/modules/Video';
import { SettingsModule } from '@/modules/Settings';
import { IntegrationsModule } from '@/modules/Integrations';
import { DeliveriesModule } from '@/modules/Deliveries';
import { AssetsModule } from '@/modules/Assets';
import { CopyModule } from '@/modules/Copy';
import { TemplatesModule } from '@/modules/Templates';
import { BrandsModule } from '@/modules/Brands';
import { KeywordsModule } from '@/modules/Keywords';
import { WriterModule } from '@/modules/Writer';
import { StrategiesModule } from '@/modules/Strategies';
import { SitesModule } from '@/modules/Sites';
import { ApprovalsModule } from '@/modules/Approvals';
import { AdsModule } from '@/modules/Ads';
import { AutomationsModule } from '@/modules/Automations';
import { UsersModule } from '@/modules/Users';
import { SEOModule } from '@/modules/SEO';
import { LogsModule } from '@/modules/Logs';
import type { ModuleId } from '@/types';
import { MODULE_VERSIONS } from '@/lib/module-versions';

// Module registry - maps module IDs to their components
const moduleRegistry: Record<ModuleId, React.ComponentType> = {
  brands: BrandsModule,
  deliveries: DeliveriesModule,
  projects: ProjectsModule,
  assets: AssetsModule,
  copy: CopyModule,
  text: CopyModule, // 'text' is a capability type used by integrations, maps to Copy module
  image: ImageModule,
  templates: TemplatesModule,
  video: VideoModule,
  settings: SettingsModule,
  integrations: IntegrationsModule,
  keywords: KeywordsModule,
  writer: WriterModule,
  strategies: StrategiesModule,
  sites: SitesModule,
  approvals: ApprovalsModule,
  ads: AdsModule,
  automations: AutomationsModule,
  users: UsersModule,
  seo: SEOModule,
  logs: LogsModule,
};

// Modules that need full-bleed layout (no padding, full height)
const fullBleedModules: ModuleId[] = ['image', 'video', 'writer', 'ads'];

export function Shell() {
  const { state } = useApp();
  const { activeModule } = state;

  // Get the active module component
  const ActiveModuleComponent = moduleRegistry[activeModule];
  const isFullBleed = fullBleedModules.includes(activeModule);

  // Conditional shell background. Default is the platform-wide grey
  // (#f8f9fa) that list/form modules rely on. Modules with their own
  // white surface opt into a white shell so the page reads as a single
  // white canvas instead of a white module floating inside a grey frame.
  const whiteShellModules: ModuleId[] = ['deliveries', 'seo'];
  const shellBg = whiteShellModules.includes(activeModule) ? '#ffffff' : '#f8f9fa';

  return (
    <div className="flex h-screen overflow-hidden" style={{ background: shellBg }}>
      {/* Sidebar - Fixed navigation */}
      <Sidebar />

      {/* Main Content Area - Module Container */}
      <main className="relative flex-1 overflow-hidden">
        {isFullBleed ? (
          // Full-bleed layout for image/video modules
          <div className="h-full overflow-hidden">
            {ActiveModuleComponent ? (
              <ActiveModuleComponent />
            ) : (
              <div className="flex items-center justify-center h-full">
                <p style={{ color: '#666' }}>Module not found</p>
              </div>
            )}
          </div>
        ) : (
          // Standard layout with padding for other modules
          <div
            className="h-full overflow-auto"
            style={{
              padding: '1.25rem',
              background: shellBg,
            }}
          >
            {ActiveModuleComponent ? (
              <ActiveModuleComponent />
            ) : (
              <div className="flex items-center justify-center h-full">
                <p style={{ color: '#666' }}>Module not found</p>
              </div>
            )}
          </div>
        )}
        {/* THE MODULE VERSION STAMP (owner order 2026-07-17, gap
            MODULE-VERSION-STAMP): the active module's own version, quiet
            bottom-left; hover shows the baked build stamp — a stale tab
            is visible at a glance. ONE mount for every module. */}
        {ActiveModuleComponent && (
          <span
            className="absolute bottom-1 left-2 z-10 text-[10px] leading-none text-slate-400/60 select-none"
            title={`build ${__PCM_BUILD__}`}
          >
            {activeModule} v{MODULE_VERSIONS[activeModule]}
          </span>
        )}
      </main>
    </div>
  );
}
