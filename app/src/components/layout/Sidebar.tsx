/**
 * CREATIVE MACHINE - Sidebar Component
 * Navigation between modules with PowerKeys-inspired design
 * 
 * PowerKeys Specs:
 * - Sidebar width: 200px
 * - Padding: 0.8rem
 * - Logo: font-weight 700, font-size 1.1rem
 * - Nav items: pill shape (border-radius 999px), padding 6px 14px, font-size 0.85rem
 * - Active state: bg #e7f5ff, color #007bff
 */

import { useApp } from '@/contexts/AppContext';
import type { ModuleId } from '@/types';
import { 
  FolderOpen, 
  Image, 
  Video, 
  Settings, 
  Plug,
  Sparkles,
  Package,
  Images,
  PenLine,
  FileText,
  Building2,
  Search,
  FileEdit,
  Layers,
  Globe,
  KanbanSquare
} from 'lucide-react';

interface NavItem {
  id: ModuleId;
  label: string;
  icon: React.ReactNode;
}

// Main workflow items
const mainNavItems: NavItem[] = [
  { id: 'brands', label: 'Brands', icon: <Building2 className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'deliveries', label: 'Deliveries', icon: <Package className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'projects', label: 'Projects', icon: <FolderOpen className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'assets', label: 'Assets', icon: <Images className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'copy', label: 'Copy', icon: <PenLine className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'keywords', label: 'Keywords', icon: <Search className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'strategies', label: 'Strategies', icon: <Layers className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'writer', label: 'Writer', icon: <FileEdit className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'sites', label: 'Sites', icon: <Globe className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'approvals', label: 'Approvals', icon: <KanbanSquare className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'image', label: 'Image', icon: <Image className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'video', label: 'Video', icon: <Video className="w-[1.2rem] h-[1.2rem]" /> },
];

// Configuration items (bottom of sidebar)
const configNavItems: NavItem[] = [
  { id: 'templates', label: 'Templates', icon: <FileText className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'settings', label: 'Settings', icon: <Settings className="w-[1.2rem] h-[1.2rem]" /> },
  { id: 'integrations', label: 'Integrations', icon: <Plug className="w-[1.2rem] h-[1.2rem]" /> },
];

export function Sidebar() {
  const { state, setActiveModule } = useApp();
  const { activeModule } = state;

  return (
    <aside 
      className="h-screen bg-white flex flex-col shrink-0"
      style={{ 
        width: '200px',
        padding: '0.8rem',
        borderRight: '1px solid #ededed'
      }}
    >
      {/* Logo - PowerKeys style */}
      <div 
        className="flex items-center gap-2"
        style={{ 
          fontWeight: 700, 
          fontSize: '1.1rem',
          marginBottom: '1.25rem',
          color: '#1a1a1a'
        }}
      >
        <Sparkles className="w-[1.2rem] h-[1.2rem]" style={{ color: '#007bff' }} />
        Creative Machine
      </div>

      {/* Subtitle */}
      <div 
        style={{ 
          fontSize: '0.7rem',
          color: '#999',
          fontWeight: 600,
          marginBottom: '0.5rem',
          paddingLeft: '0.5rem'
        }}
      >
        AI Ad Studio
      </div>

      {/* Navigation */}
      <nav className="flex flex-col flex-1">
        {/* Main workflow items */}
        {mainNavItems.map((item) => (
          <button
            key={item.id}
            onClick={() => setActiveModule(item.id)}
            className="flex items-center justify-start text-left"
            style={{
              background: activeModule === item.id ? '#e7f5ff' : 'transparent',
              color: activeModule === item.id ? '#007bff' : '#555',
              padding: '6px 14px',
              borderRadius: '999px',
              fontSize: '0.85rem',
              marginBottom: '1px',
              border: '1px solid transparent',
              gap: '8px',
              fontWeight: activeModule === item.id ? 600 : 500,
              transition: 'background-color 0.2s, color 0.2s',
            }}
            onMouseEnter={(e) => {
              if (activeModule !== item.id) {
                e.currentTarget.style.background = '#f1f3f5';
                e.currentTarget.style.color = '#333';
              }
            }}
            onMouseLeave={(e) => {
              if (activeModule !== item.id) {
                e.currentTarget.style.background = 'transparent';
                e.currentTarget.style.color = '#555';
              }
            }}
          >
            <span style={{ color: activeModule === item.id ? '#007bff' : '#888' }}>
              {item.icon}
            </span>
            {item.label}
          </button>
        ))}

        {/* Spacer */}
        <div className="flex-1" />

        {/* Separator */}
        <div style={{ height: '1px', background: '#ededed', margin: '6px 8px' }} />

        {/* Config items at bottom */}
        {configNavItems.map((item) => (
          <button
            key={item.id}
            onClick={() => setActiveModule(item.id)}
            className="flex items-center justify-start text-left"
            style={{
              background: activeModule === item.id ? '#e7f5ff' : 'transparent',
              color: activeModule === item.id ? '#007bff' : '#555',
              padding: '6px 14px',
              borderRadius: '999px',
              fontSize: '0.85rem',
              marginBottom: '1px',
              border: '1px solid transparent',
              gap: '8px',
              fontWeight: activeModule === item.id ? 600 : 500,
              transition: 'background-color 0.2s, color 0.2s',
            }}
            onMouseEnter={(e) => {
              if (activeModule !== item.id) {
                e.currentTarget.style.background = '#f1f3f5';
                e.currentTarget.style.color = '#333';
              }
            }}
            onMouseLeave={(e) => {
              if (activeModule !== item.id) {
                e.currentTarget.style.background = 'transparent';
                e.currentTarget.style.color = '#555';
              }
            }}
          >
            <span style={{ color: activeModule === item.id ? '#007bff' : '#888' }}>
              {item.icon}
            </span>
            {item.label}
          </button>
        ))}
      </nav>

      {/* Footer - User profile */}
      <div 
        className="flex items-center gap-3"
        style={{ 
          paddingTop: '0.8rem',
          borderTop: '1px solid #ededed'
        }}
      >
        {/* Avatar - PowerKeys style */}
        <div 
          className="flex items-center justify-center"
          style={{
            width: '28px',
            height: '28px',
            background: '#e9ecef',
            borderRadius: '50%',
            fontSize: '0.75rem',
            fontWeight: 600,
            color: '#555',
            border: '1px solid #dee2e6'
          }}
        >
          U
        </div>
        <div className="flex-1 min-w-0">
          <p style={{ fontSize: '0.85rem', fontWeight: 500, color: '#1a1a1a' }} className="truncate">User</p>
          <p style={{ fontSize: '0.7rem', color: '#666' }} className="truncate">Free Plan</p>
        </div>
      </div>
    </aside>
  );
}
