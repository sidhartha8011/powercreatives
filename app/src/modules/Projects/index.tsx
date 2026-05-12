import React, { useState, useMemo } from 'react';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import { Project } from './types';
import { useProjectActions } from './hooks/useProjectActions';
import { ProjectGrid } from './components/ProjectGrid';
import { ProjectList } from './components/ProjectList';
import { getGradient, formatProjectDate } from './utils';

import { ModuleHeader } from '@/components/shared/ModuleHeader';
import { EmptyState } from '@/components/shared/EmptyState';
import { Input } from '@/components/ui/input';
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
import { Plus, Folder, LayoutGrid, List, Loader2, ArrowLeft, ImageIcon, Search, X } from 'lucide-react';

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

  // Data Fetching (Batch Loaded)
  const projectsQuery = trpc.assets.getProjects.useQuery({ includeThumbnails: true });
  const projects: Project[] = projectsQuery.data ?? [];

  // Filter State
  const [searchQuery, setSearchQuery] = useState('');
  const [typeFilter, setTypeFilter] = useState('all');

  // Mutation Hook
  const { deleteProject, renameProject, duplicateProject, isDeleting, isDuplicating, isRenaming } = useProjectActions();
  const [mutatingId, setMutatingId] = useState<number | null>(null);

  // Rename Modal State
  const [renameModalOpen, setRenameModalOpen] = useState(false);
  const [projectToRename, setProjectToRename] = useState<Project | null>(null);
  const [newName, setNewName] = useState('');

  // Create Project State
  const [showCreate, setShowCreate] = useState(false);
  const [createName, setCreateName] = useState('');

  const createMutation = trpc.assets.createProject.useMutation({
    onSuccess: (data: any) => {
      toast.success(`Created project "${data.name}"`);
      projectsQuery.refetch();
      setCreateName('');
      setShowCreate(false);
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to create project');
    },
  });

  const handleCreate = () => {
    if (!createName.trim()) return;
    createMutation.mutate({ name: createName.trim(), type: 'general' });
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
    setRenameModalOpen(true);
  };

  const submitRename = () => {
    if (projectToRename && newName.trim()) {
      setMutatingId(projectToRename.id);
      renameProject(
        { id: projectToRename.id, name: newName.trim() },
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
            onClick={() => setSelectedProject(null)}
            className="h-9 w-9 rounded-full"
          >
            <ArrowLeft className="w-4 h-4" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-slate-900">
              {selectedProject.name}
            </h1>
            <p className="text-sm text-slate-500 mt-1 capitalize">
              {assets.length} asset{assets.length !== 1 ? 's' : ''} · {selectedProject.type}
            </p>
          </div>
        </div>

        {assetsQuery.isLoading ? (
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
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
            {assets.map((asset: any) => (
              <div
                key={asset.id}
                className="group relative rounded-lg overflow-hidden border border-slate-200 bg-slate-50 aspect-square"
              >
                {asset.url ? (
                  <img
                    src={asset.url}
                    alt={asset.prompt || 'Saved asset'}
                    className="w-full h-full object-cover"
                    loading="lazy"
                  />
                ) : (
                  <div className="w-full h-full flex items-center justify-center">
                    <ImageIcon className="w-8 h-8 text-slate-300" />
                  </div>
                )}
                {/* Hover overlay */}
                <div
                  className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col justify-end p-2 bg-gradient-to-t from-black/80 to-transparent"
                >
                  {asset.prompt && (
                    <p className="line-clamp-2 text-[11px] text-white font-medium leading-snug">
                      {asset.prompt}
                    </p>
                  )}
                  <div className="flex items-center gap-2 mt-1 text-[10px] text-white/70">
                    <span>{asset.model}</span>
                    <span>{formatProjectDate(asset.createdAt)}</span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
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
          className="mb-0"
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
        <div className="flex items-center gap-2 mb-6 p-3 bg-white rounded-lg border border-slate-200 shadow-sm animate-in slide-in-from-top-2">
          <Input
            value={createName}
            onChange={(e) => setCreateName(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && handleCreate()}
            placeholder="Project name..."
            className="flex-1 max-w-sm"
            autoFocus
          />
          <Button onClick={handleCreate} disabled={!createName.trim() || createMutation.isPending} size="sm">
            {createMutation.isPending ? <Loader2 className="w-4 h-4 animate-spin" /> : 'Create'}
          </Button>
          <Button variant="ghost" size="sm" onClick={() => { setShowCreate(false); setCreateName(''); }}>
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
            <DialogTitle>Rename Project</DialogTitle>
          </DialogHeader>
          <div className="py-4">
            <Input
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && submitRename()}
              placeholder="Enter new name..."
              className="w-full"
              autoFocus
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRenameModalOpen(false)}>Cancel</Button>
            <Button onClick={submitRename} disabled={!newName.trim() || newName === projectToRename?.name || isRenaming}>
              {isRenaming ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : null}
              Save Changes
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

    </div>
  );
}
