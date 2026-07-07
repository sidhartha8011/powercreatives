import React, { useState, useMemo, useEffect } from 'react';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import { Project } from './types';
import { useProjectActions } from './hooks/useProjectActions';
import { ProjectGrid } from './components/ProjectGrid';
import { ProjectList } from './components/ProjectList';
import { getGradient, formatProjectDate, extractNumericId } from './utils';
import { InlineEditableCard } from '../Copy/components/InlineEditableCard';

import { ModuleHeader } from '@/components/shared/ModuleHeader';
import { EmptyState } from '@/components/shared/EmptyState';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { useSortableTable } from '@/hooks/useSortableTable';
import {
  Plus,
  Folder,
  LayoutGrid,
  List,
  Loader2,
  ArrowLeft,
  ImageIcon,
  Search,
  X,
  Copy,
  Check,
  FileText,
  FolderInput,
} from 'lucide-react';
import { BulkActionBar } from '@/components/shared/BulkActionBar';
import { useApp } from '@/contexts/AppContext';

export function ProjectsModule() {
  // Global View State
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
  const [selectedProject, setSelectedProject] = useState<Project | null>(null);

  // Detail View Asset State
  const assetsQuery = trpc.assets.getAll.useQuery(
    { projectId: selectedProject?.id },
    { enabled: !!selectedProject }
  );
  const assets = assetsQuery.data ?? [];

  const [detailTab, setDetailTab] = useState<'media' | 'copy'>('media');
  const copyResultsQuery = trpc.copy.getProjectResults.useQuery(
    { projectId: selectedProject?.id ?? 0 },
    { enabled: !!selectedProject && detailTab === 'copy' }
  );
  const copyResults = copyResultsQuery.data?.results ?? [];

  const [activeAudienceTab, setActiveAudienceTab] = useState<string>('');

  const groupedCopyResults = useMemo(() => {
    const groups: Record<string, any[]> = {};
    copyResults.forEach((v: any) => {
      const aud = v.audienceName || 'General';
      if (!groups[aud]) {
        groups[aud] = [];
      }
      groups[aud].push(v);
    });
    return groups;
  }, [copyResults]);

  const audienceNames = useMemo(() => Object.keys(groupedCopyResults), [groupedCopyResults]);

  // Auto-select first audience tab if current one is invalid
  useEffect(() => {
    if (audienceNames.length > 0 && !audienceNames.includes(activeAudienceTab)) {
      setActiveAudienceTab(audienceNames[0]);
    }
  }, [audienceNames, activeAudienceTab]);

  // Reset active audience tab on project change
  useEffect(() => {
    setActiveAudienceTab('');
  }, [selectedProject]);

  // Data Fetching (Batch Loaded)
  const projectsQuery = trpc.assets.getProjects.useQuery({ includeThumbnails: true });
  const projects: Project[] = projectsQuery.data ?? [];

  // Cross-module deep-link (e.g. delivery card → a project's media/copy tab).
  // One-shot: consume clears the pending nav; waits until projects are loaded.
  const { consumePendingProjectNav } = useApp();
  useEffect(() => {
    if (projects.length === 0) return;
    const nav = consumePendingProjectNav();
    if (!nav) return;
    const target = projects.find((p) => Number(p.id) === nav.projectId);
    if (!target) return;
    setSelectedProject(target);
    setDetailTab(nav.tab);
  }, [projects, consumePendingProjectNav]);

  // Brand → Delivery → Project: assign the delivery a project belongs to. Brand is then
  // inherited live from that delivery everywhere (approval sets, etc.) — never stored.
  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery();
  const deliveries: { id: number; name: string }[] = Array.isArray(deliveriesRaw)
    ? (deliveriesRaw as any[]).map((d) => ({ id: Number(d.id), name: String(d.name) }))
    : [];
  const setProjectDeliveryMutation = trpc.assets.setProjectDelivery.useMutation();
  const handleSetProjectDelivery = async (deliveryId: number | null) => {
    if (!selectedProject) return;
    try {
      await setProjectDeliveryMutation.mutateAsync({ id: selectedProject.id, deliveryId });
      setSelectedProject({ ...selectedProject, deliveryId });
      projectsQuery.refetch();
      toast.success(deliveryId ? 'Delivery assigned — brand now inherited from it' : 'Delivery cleared');
    } catch (e: any) {
      toast.error(e?.message || 'Failed to set the project delivery');
    }
  };

  // Site ↔ Project connection (projects.siteId, N:1 — same link the Sites and Deliveries
  // controls edit). "Site" = a connected WP site from the Sites module.
  const { data: sitesRaw } = trpc.sites.list.useQuery();
  const sites: { id: number; name: string }[] = Array.isArray(sitesRaw)
    ? (sitesRaw as any[]).map((s) => ({ id: Number(s.id), name: String(s.name) }))
    : [];
  const setProjectSiteMutation = trpc.assets.setProjectSite.useMutation();
  const handleSetProjectSite = async (siteId: number | null) => {
    if (!selectedProject) return;
    try {
      await setProjectSiteMutation.mutateAsync({ id: selectedProject.id, siteId });
      setSelectedProject({ ...selectedProject, siteId });
      projectsQuery.refetch();
      toast.success(siteId ? 'Site connected' : 'Site disconnected');
    } catch (e: any) {
      toast.error(e?.message || 'Failed to update the site connection');
    }
  };

  // Filter State
  const [searchQuery, setSearchQuery] = useState('');
  const [typeFilter, setTypeFilter] = useState('all');

  // Mutation Hook
  const { deleteProject, renameProject, duplicateProject, isDeleting, isDuplicating, isRenaming } = useProjectActions();
  const [mutatingId, setMutatingId] = useState<number | null>(null);

  // Rename/Edit Modal State
  const [renameModalOpen, setRenameModalOpen] = useState(false);
  const [projectToRename, setProjectToRename] = useState<Project | null>(null);
  const [newName, setNewName] = useState('');
  const [renameExternalId, setRenameExternalId] = useState('');

  // Copy & Media Selection & Movement States
  const [selectedCopyIds, setSelectedCopyIds] = useState<string[]>([]);
  const [selectedAssetIds, setSelectedAssetIds] = useState<number[]>([]);
  const [isMoveDialogOpen, setIsMoveDialogOpen] = useState(false);

  // Sync/clear selections when active project or tab changes
  useEffect(() => {
    setSelectedCopyIds([]);
    setSelectedAssetIds([]);
  }, [selectedProject, detailTab]);

  const handleToggleCopySelect = (cardId: string) => {
    setSelectedCopyIds((prev) =>
      prev.includes(cardId) ? prev.filter((id) => id !== cardId) : [...prev, cardId]
    );
  };

  const handleClearCopySelection = () => setSelectedCopyIds([]);

  const handleToggleAssetSelect = (assetId: number) => {
    setSelectedAssetIds((prev) =>
      prev.includes(assetId) ? prev.filter((id) => id !== assetId) : [...prev, assetId]
    );
  };

  const handleClearAssetSelection = () => setSelectedAssetIds([]);

  // Mutation for Inline Copy Card Editing
  const updateCopyResultMutation = trpc.copy.updateResult.useMutation();

  const handleSaveCopyEdits = async (
    variationId: string,
    updates: { headline?: string; body?: string; cta?: string; hashtags?: string; description?: string }
  ) => {
    const resultId = extractNumericId(variationId);
    if (resultId <= 0) {
      toast.error('Invalid copy result ID');
      return;
    }
    try {
      await updateCopyResultMutation.mutateAsync({
        resultId,
        ...updates,
      });
      toast.success('Edits saved successfully');
      copyResultsQuery.refetch();
    } catch (err: any) {
      toast.error(err.message || 'Failed to save edits');
      throw err;
    }
  };

  // Create Project State
  const [showCreate, setShowCreate] = useState(false);
  const [createName, setCreateName] = useState('');
  const [createExternalId, setCreateExternalId] = useState('');

  const createMutation = trpc.assets.createProject.useMutation({
    onSuccess: (data: any) => {
      toast.success(`Created project "${data.name}"`);
      projectsQuery.refetch();
      setCreateName('');
      setCreateExternalId('');
      setShowCreate(false);
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to create project');
    },
  });

  const handleCreate = () => {
    if (!createName.trim()) return;
    createMutation.mutate({
      name: createName.trim(),
      type: 'general',
      externalId: createExternalId.trim() || undefined,
    });
  };

  // Handlers for Project Actions
  const handleDelete = async (p: Project) => {
    if (window.confirm(`Are you sure you want to delete "${p.name}"? Assets will NOT be permanently deleted, but will be moved outside this folder.`)) {
      setMutatingId(p.id);
      deleteProject({ id: p.id }, {
        onSettled: () => setMutatingId(null)
      });
    }
  };

  const handleDuplicate = (p: Project) => {
    setMutatingId(p.id);
    duplicateProject({ id: p.id }, {
      onSettled: () => setMutatingId(null)
    });
  };

  const openRenameModal = (p: Project) => {
    setProjectToRename(p);
    setNewName(p.name);
    setRenameExternalId(p.externalId ?? '');
    setRenameModalOpen(true);
  };

  const renameDirty = !!projectToRename
    && (newName !== projectToRename.name || renameExternalId !== (projectToRename.externalId ?? ''));

  const submitRename = () => {
    if (projectToRename && newName.trim()) {
      setMutatingId(projectToRename.id);
      renameProject(
        { id: projectToRename.id, name: newName.trim(), externalId: renameExternalId.trim() },
        {
          onSuccess: () => setRenameModalOpen(false),
          onSettled: () => setMutatingId(null)
        }
      );
    }
  };

  // Derived Filtered Data
  const filteredProjects = useMemo(() => {
    return projects.filter(p => {
      const matchesSearch = p.name.toLowerCase().includes(searchQuery.toLowerCase());
      const matchesType = typeFilter === 'all' || p.type === typeFilter;
      return matchesSearch && matchesType;
    });
  }, [projects, searchQuery, typeFilter]);

  type ProjectSortKey = 'name' | 'type' | 'assetCount' | 'createdAt';

  const { sortKey, sortDir, toggleSort, sortedData } = useSortableTable<Project, ProjectSortKey>(filteredProjects, {
    defaultKey: 'createdAt',
    defaultDir: 'desc',
    accessors: {
      name: (row) => row.name.toLowerCase(),
      createdAt: (row) => new Date(row.createdAt).getTime(),
      assetCount: (row) => row.assetCount,
      type: (row) => row.type.toLowerCase(),
    },
  });

  // Grid views newest first always, unless we apply a generic "sort" logic. For now, mimicking Mockup.
  const displayData = viewMode === 'list' 
    ? sortedData 
    : [...filteredProjects].sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime());

  const clearFilters = () => {
    setSearchQuery('');
    setTypeFilter('all');
  };

  // ========================================
  // Detail View (when selectedProject is set)
  // ========================================
  if (selectedProject) {
    return (
      <div className="animate-fade-in p-6 bg-white min-h-screen">
        <div className="flex items-center gap-3 mb-6">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => {
              setSelectedProject(null);
              setDetailTab('media');
            }}
            className="h-9 w-9 rounded-full"
          >
            <ArrowLeft className="w-4 h-4" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-slate-900">
              {selectedProject.name}
            </h1>
            <p className="text-sm text-slate-500 mt-1 capitalize">
              {assets.length} media asset{assets.length !== 1 ? 's' : ''} · {copyResults.length} copy card{copyResults.length !== 1 ? 's' : ''} · {selectedProject.type}
            </p>
          </div>
          {/* Brand → Delivery → Project: pick the delivery this project belongs to. */}
          <div className="ml-auto flex items-center gap-2">
            <span className="text-xs font-medium text-slate-500">Delivery</span>
            <Select
              value={selectedProject.deliveryId != null ? String(selectedProject.deliveryId) : 'none'}
              onValueChange={(v) => handleSetProjectDelivery(v === 'none' ? null : Number(v))}
            >
              <SelectTrigger className="h-9 w-[220px] text-xs bg-white"><SelectValue placeholder="No delivery" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="none">No delivery</SelectItem>
                {deliveries.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
              </SelectContent>
            </Select>
            {/* Site ↔ Project: connect/disconnect the site this project uses. */}
            <span className="text-xs font-medium text-slate-500">Site</span>
            <Select
              value={selectedProject.siteId != null ? String(selectedProject.siteId) : 'none'}
              onValueChange={(v) => handleSetProjectSite(v === 'none' ? null : Number(v))}
            >
              <SelectTrigger className="h-9 w-[220px] text-xs bg-white"><SelectValue placeholder="Not connected" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="none">Not connected</SelectItem>
                {sites.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
        </div>

        {/* Detail Tabs Bar */}
        <div className="flex border-b border-slate-200 mb-6 gap-6">
          <button
            onClick={() => setDetailTab('media')}
            className={`pb-3 text-sm font-semibold relative transition-colors ${
              detailTab === 'media' ? 'text-blue-600' : 'text-slate-500 hover:text-slate-800'
            }`}
          >
            Media Assets ({assets.length})
            {detailTab === 'media' && (
              <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-blue-600 rounded-full animate-in fade-in duration-200" />
            )}
          </button>
          <button
            onClick={() => setDetailTab('copy')}
            className={`pb-3 text-sm font-semibold relative transition-colors ${
              detailTab === 'copy' ? 'text-blue-600' : 'text-slate-500 hover:text-slate-800'
            }`}
          >
            Campaign Copy ({copyResults.length})
            {detailTab === 'copy' && (
              <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-blue-600 rounded-full animate-in fade-in duration-200" />
            )}
          </button>
        </div>

        {detailTab === 'media' ? (
          assetsQuery.isLoading ? (
            <div className="flex items-center justify-center py-20">
              <Loader2 className="w-6 h-6 animate-spin text-slate-300" />
            </div>
          ) : assets.length === 0 ? (
            <EmptyState
              icon={<ImageIcon className="w-12 h-12" />}
              title="No assets in this project"
              description="Save images or videos to this project from the Image or Video modules"
            />
          ) : (
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 animate-in fade-in duration-200">
              {assets.map((asset: any) => {
                const isSelected = selectedAssetIds.includes(asset.id);
                return (
                  <div
                    key={asset.id}
                    onClick={() => handleToggleAssetSelect(asset.id)}
                    className={`group relative rounded-lg overflow-hidden border bg-slate-50 aspect-square cursor-pointer transition-all duration-200 ${
                      isSelected
                        ? 'border-blue-500 ring-2 ring-blue-500/40 shadow-sm scale-[0.99]'
                        : 'border-slate-200 hover:border-slate-300 hover:shadow-sm'
                    }`}
                  >
                    {/* Selection checkbox */}
                    <div
                      className={`absolute top-2 left-2 z-10 transition-all duration-200 ${
                        isSelected ? 'opacity-100 scale-100' : 'opacity-0 group-hover:opacity-100 scale-95'
                      }`}
                      onClick={(e) => e.stopPropagation()}
                    >
                      <input
                        type="checkbox"
                        checked={isSelected}
                        onChange={() => handleToggleAssetSelect(asset.id)}
                        className="h-4.5 w-4.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer shadow-sm"
                      />
                    </div>

                    {asset.url ? (
                      <img
                        src={asset.url}
                        alt={asset.prompt || 'Saved asset'}
                        className="w-full h-full object-cover select-none"
                        loading="lazy"
                      />
                    ) : (
                      <div className="w-full h-full flex items-center justify-center">
                        <ImageIcon className="w-8 h-8 text-slate-300 animate-pulse" />
                      </div>
                    )}
                    
                    {/* Hover overlay */}
                    <div
                      className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col justify-end p-2 bg-gradient-to-t from-black/80 via-black/20 to-transparent pointer-events-none"
                    >
                      {asset.prompt && (
                        <p className="line-clamp-2 text-[11px] text-white font-medium leading-snug">
                          {asset.prompt}
                        </p>
                      )}
                      <div className="flex items-center gap-2 mt-1 text-[10px] text-white/70">
                        <span className="capitalize">{asset.type || 'Media'}</span>
                        <span>{asset.model}</span>
                        <span>{formatProjectDate(asset.createdAt)}</span>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )
        ) : (
          copyResultsQuery.isLoading ? (
            <div className="flex items-center justify-center py-20">
              <Loader2 className="w-6 h-6 animate-spin text-slate-300" />
            </div>
          ) : copyResults.length === 0 ? (
            <EmptyState
              icon={<FileText className="w-12 h-12" />}
              title="No saved copy in this project"
              description="Select and save generated copy cards to this project from the Copy module"
            />
          ) : (
            <div className="flex flex-col gap-4 animate-in fade-in duration-200">
              {/* Audience Sub-Tabs Bar */}
              <div className="flex flex-wrap items-center gap-1.5 p-1.5 bg-slate-50/80 rounded-xl border border-slate-100 max-w-max">
                {audienceNames.map((audName) => {
                  const isActive = audName === activeAudienceTab;
                  const count = groupedCopyResults[audName]?.length ?? 0;
                  return (
                    <button
                      key={audName}
                      onClick={() => setActiveAudienceTab(audName)}
                      className={`flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold transition-all duration-150 ${
                        isActive 
                          ? 'bg-white shadow-sm border border-slate-200 text-slate-900 font-bold' 
                          : 'border border-transparent text-slate-500 hover:text-slate-800'
                      }`}
                    >
                      {audName}
                      <span className={`text-[10px] rounded-full px-1.5 py-0.5 font-bold transition-colors ${
                        isActive ? 'bg-blue-50 text-blue-600' : 'bg-slate-200/60 text-slate-500'
                      }`}>
                        {count}
                      </span>
                    </button>
                  );
                })}
              </div>

              {/* Grid of overlookable compact cards */}
              <div className="rounded-2xl border border-slate-100 bg-slate-50/50 p-4">
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
                  {(groupedCopyResults[activeAudienceTab] ?? []).map((v: any, idx: number) => (
                    <InlineEditableCard 
                      key={v.id} 
                      variation={v} 
                      index={idx} 
                      readOnly={false}
                      isSelected={selectedCopyIds.includes(v.id)}
                      onToggleSelect={handleToggleCopySelect}
                      showCheckbox={selectedCopyIds.length > 0}
                      onSaveEdits={handleSaveCopyEdits}
                    />
                  ))}
                </div>
              </div>
            </div>
          )
        )}

        {/* Generic Bulk Action Bar */}
        {((detailTab === 'media' ? selectedAssetIds.length : selectedCopyIds.length) > 0) && (
          <BulkActionBar
            count={detailTab === 'media' ? selectedAssetIds.length : selectedCopyIds.length}
            onClear={detailTab === 'media' ? handleClearAssetSelection : handleClearCopySelection}
          >
            <BulkActionBar.Action
              icon={FolderInput}
              label="Move to Project"
              onClick={() => setIsMoveDialogOpen(true)}
            />
          </BulkActionBar>
        )}

        {/* Move To Project Dialog */}
        <MoveToProjectDialog
          open={isMoveDialogOpen}
          onOpenChange={setIsMoveDialogOpen}
          selectedIds={detailTab === 'media' ? selectedAssetIds.map(String) : selectedCopyIds}
          currentProjectId={selectedProject.id}
          assetType={detailTab}
          onComplete={() => {
            if (detailTab === 'media') {
              handleClearAssetSelection();
              assetsQuery.refetch();
            } else {
              handleClearCopySelection();
              copyResultsQuery.refetch();
            }
            projectsQuery.refetch();
          }}
        />
      </div>
    );
  }

  // ========================================
  // Main List/Grid View
  // ========================================
  return (
    <div className="animate-fade-in p-6 bg-white min-h-screen">
      
      {/* Header section */}
      <div className="flex items-center justify-between mb-6">
        <ModuleHeader
          title="Projects"
          description="Manage your projects and keep your campaign assets organized"
        />
        <div className="flex items-center gap-3">
          <div className="flex bg-slate-100 p-1 rounded-md border border-slate-200">
            <button onClick={() => setViewMode('grid')} className={`p-1.5 rounded-sm transition-all ${viewMode === 'grid' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'}`}>
              <LayoutGrid className="w-4 h-4" />
            </button>
            <button onClick={() => setViewMode('list')} className={`p-1.5 rounded-sm transition-all ${viewMode === 'list' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'}`}>
              <List className="w-4 h-4" />
            </button>
          </div>
          <Button onClick={() => setShowCreate(!showCreate)} className="rounded-full shadow-sm font-semibold">
            <Plus className="w-4 h-4 mr-1" />
            New Project
          </Button>
        </div>
      </div>

      {/* Inline Create Form */}
      {showCreate && (
        <div className="flex flex-wrap items-end gap-3 mb-6 p-3 bg-white rounded-lg border border-slate-200 shadow-sm animate-in slide-in-from-top-2">
          <div className="flex-1 min-w-[200px] max-w-sm space-y-1.5">
            <Label htmlFor="project-name">Project name</Label>
            <Input
              id="project-name"
              value={createName}
              onChange={(e) => setCreateName(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleCreate()}
              placeholder="Project name..."
              autoFocus
            />
          </div>
          <div className="flex-1 min-w-[200px] max-w-sm space-y-1.5">
            <Label htmlFor="project-external-id">External ID (eg. airtable or other PM tool)</Label>
            <Input
              id="project-external-id"
              value={createExternalId}
              onChange={(e) => setCreateExternalId(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleCreate()}
              placeholder="Optional — used for webhook/automation mapping"
            />
          </div>
          <Button onClick={handleCreate} disabled={!createName.trim() || createMutation.isPending} size="sm">
            {createMutation.isPending ? <Loader2 className="w-4 h-4 animate-spin" /> : 'Create'}
          </Button>
          <Button variant="ghost" size="sm" onClick={() => { setShowCreate(false); setCreateName(''); setCreateExternalId(''); }}>
            Cancel
          </Button>
        </div>
      )}

      {/* Global Filter Bar */}
      <div className="flex flex-wrap items-center gap-3 mb-6 bg-slate-50/50 p-2 rounded-lg border border-slate-100">
        <div className="relative flex-1 min-w-[200px] max-w-[300px]">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
          <Input
            placeholder="Search projects..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="pl-9 h-9 bg-white"
          />
        </div>
        
        <Select value={typeFilter} onValueChange={setTypeFilter}>
          <SelectTrigger className="w-[160px] h-9 bg-white">
            <SelectValue placeholder="Project Type" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Types</SelectItem>
            <SelectItem value="campaign">Campaign</SelectItem>
            <SelectItem value="branding">Branding</SelectItem>
            <SelectItem value="social">Social</SelectItem>
            <SelectItem value="general">General</SelectItem>
          </SelectContent>
        </Select>
        
        {(searchQuery !== '' || typeFilter !== 'all') && (
          <Button variant="ghost" size="sm" onClick={clearFilters} className="h-9 px-2 text-slate-500">
            <X className="w-4 h-4 mr-1" />
            Clear Filters
          </Button>
        )}

        <div className="ml-auto text-xs text-slate-500 font-medium">
            Showing {displayData.length} projects
        </div>
      </div>

      {projectsQuery.isLoading ? (
        <div className="flex items-center justify-center py-20">
          <Loader2 className="w-6 h-6 animate-spin text-slate-300" />
        </div>
      ) : displayData.length === 0 ? (
        <EmptyState
          icon={<Folder className="w-12 h-12" />}
          title="No projects found"
          description={(searchQuery !== '' || typeFilter !== 'all') ? "Try adjusting your search or filters" : "Create your first project to start organizing your assets"}
          action={
            (searchQuery !== '' || typeFilter !== 'all') ? (
              <Button variant="outline" onClick={clearFilters}>Clear Filters</Button>
            ) : (
              <Button onClick={() => setShowCreate(true)} className="rounded-full">
                 <Plus className="w-4 h-4 mr-2" /> Create Project
              </Button>
            )
          }
        />
      ) : viewMode === 'grid' ? (
        <ProjectGrid 
          projects={displayData} 
          onSelectProject={setSelectedProject}
          onRenameProject={openRenameModal}
          onDuplicateProject={handleDuplicate}
          onDeleteProject={handleDelete}
          mutatingProjectId={mutatingId}
          isDeleting={isDeleting}
          isDuplicating={isDuplicating}
        />
      ) : (
        <ProjectList 
          projects={displayData} 
          onSelectProject={setSelectedProject}
          onRenameProject={openRenameModal}
          onDuplicateProject={handleDuplicate}
          onDeleteProject={handleDelete}
          sortKey={sortKey as string}
          sortDir={sortDir}
          toggleSort={toggleSort as any}
          mutatingProjectId={mutatingId}
          isDeleting={isDeleting}
          isDuplicating={isDuplicating}
        />
      )}

      {/* Rename Modal */}
      <Dialog open={renameModalOpen} onOpenChange={setRenameModalOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Edit Project</DialogTitle>
          </DialogHeader>
          <div className="py-4 space-y-3">
            <div className="space-y-1.5">
              <Label htmlFor="project-rename-name">Project name</Label>
              <Input
                id="project-rename-name"
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && submitRename()}
                placeholder="Enter new name..."
                className="w-full"
                autoFocus
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="project-rename-extid">External ID (eg. airtable or other PM tool)</Label>
              <Input
                id="project-rename-extid"
                value={renameExternalId}
                onChange={(e) => setRenameExternalId(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && submitRename()}
                placeholder="Optional — used for webhook/automation mapping"
                className="w-full"
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRenameModalOpen(false)}>Cancel</Button>
            <Button onClick={submitRename} disabled={!newName.trim() || !renameDirty || isRenaming}>
              {isRenaming ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : null}
              Save Changes
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

    </div>
  );
}

interface MoveToProjectDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  selectedIds: string[];
  currentProjectId: number;
  assetType: 'media' | 'copy';
  onComplete: () => void;
}

export function MoveToProjectDialog({
  open,
  onOpenChange,
  selectedIds,
  currentProjectId,
  assetType,
  onComplete,
}: MoveToProjectDialogProps) {
  const [targetProjectId, setTargetProjectId] = useState<string>('');
  const [isMoving, setIsMoving] = useState(false);

  // Fetch projects list (omit the current project so they don't move to the same one!)
  const { data: projects, isLoading } = trpc.assets.getProjects.useQuery(undefined, { enabled: open });
  const copyMoveMutation = trpc.copy.saveToProject.useMutation();
  const mediaMoveMutation = trpc.assets.saveToProject.useMutation();

  const filteredProjects = useMemo(() => {
    return (projects ?? []).filter((p: any) => p.id !== currentProjectId);
  }, [projects, currentProjectId]);

  const handleMove = async () => {
    if (!targetProjectId) {
      toast.error('Please select a project');
      return;
    }

    setIsMoving(true);
    try {
      const cleanIds = selectedIds
        .map(extractNumericId)
        .filter(id => id > 0);

      if (cleanIds.length === 0) {
        toast.error(`No valid ${assetType} items selected.`);
        return;
      }

      if (assetType === 'media') {
        await mediaMoveMutation.mutateAsync({
          assetIds: cleanIds,
          projectId: parseInt(targetProjectId, 10),
        });
      } else {
        await copyMoveMutation.mutateAsync({
          resultIds: cleanIds,
          projectId: parseInt(targetProjectId, 10),
        });
      }

      const projectName = projects?.find((p: any) => p.id.toString() === targetProjectId)?.name ?? 'project';
      const typeLabel = assetType === 'media' ? 'media asset(s)' : 'copy card(s)';
      toast.success(`Moved ${cleanIds.length} ${typeLabel} to "${projectName}"`);
      onComplete();
      onOpenChange(false);
    } catch (err: any) {
      toast.error(err?.message || `Failed to move ${assetType} items`);
    } finally {
      setIsMoving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Move Copy to Project</DialogTitle>
        </DialogHeader>
        <div className="py-4 flex flex-col gap-4">
          <label className="text-sm font-medium text-slate-700">Select target project:</label>
          {isLoading ? (
            <div className="flex items-center justify-center py-4">
              <Loader2 className="w-6 h-6 animate-spin text-blue-500" />
            </div>
          ) : filteredProjects.length === 0 ? (
            <p className="text-sm text-slate-500 text-center py-2">No other active projects found.</p>
          ) : (
            <Select value={targetProjectId} onValueChange={setTargetProjectId}>
              <SelectTrigger className="w-full">
                <SelectValue placeholder="Choose a project..." />
              </SelectTrigger>
              <SelectContent>
                {filteredProjects.map((p: any) => (
                  <SelectItem key={p.id} value={p.id.toString()}>
                    {p.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isMoving}>
            Cancel
          </Button>
          <Button onClick={handleMove} disabled={isMoving || !targetProjectId} className="bg-blue-600 hover:bg-blue-700 text-white font-semibold">
            {isMoving && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
            Confirm Move
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
