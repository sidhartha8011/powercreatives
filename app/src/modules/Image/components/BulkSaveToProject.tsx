/**
 * BulkSaveToProject — Popover-based project picker for bulk saving
 *
 * Reuses existing tRPC endpoints:
 *   - assets.saveToProject  (per-asset assignment)
 *   - assets.getProjects    (project list)
 *   - assets.createProject  (new project)
 *
 * Saves selected assets sequentially to avoid race conditions on the backend.
 * Lazy-loads the project list (enabled only when popover is open).
 */

import { useState, useCallback } from 'react';
import { FolderOpen, FolderPlus, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { toast } from 'sonner';
import { trpc } from '@/lib/trpc';
import type { GeneratedAsset } from '@/types';

// ============================================================================
// Types
// ============================================================================

interface BulkSaveToProjectProps {
  selectedAssets: GeneratedAsset[];
  onComplete: () => void;
}

// ============================================================================
// Component
// ============================================================================

export function BulkSaveToProject({ selectedAssets, onComplete }: BulkSaveToProjectProps) {
  const [open, setOpen] = useState(false);
  const [selectedProjectId, setSelectedProjectId] = useState<string>('');
  const [showNew, setShowNew] = useState(false);
  const [newName, setNewName] = useState('');
  const [isSaving, setIsSaving] = useState(false);

  // Lazy-load projects list only when popover is open
  const projectsQuery = trpc.assets.getProjects.useQuery(undefined, { enabled: open });
  const projects = (projectsQuery.data as Array<{ id: number; name: string }>) ?? [];

  const saveToProjectMutation = trpc.assets.saveToProject.useMutation();
  const createProjectMutation = trpc.assets.createProject.useMutation();

  /**
   * Save all selected assets to the chosen project.
   * Sequential loop to avoid race conditions on the backend.
   */
  const handleSave = useCallback(async () => {
    if (!selectedProjectId) return;
    const projectId = parseInt(selectedProjectId);
    const validAssets = selectedAssets.filter(
      (a) => a.status === 'complete' && Number.isFinite(Number(a.id)),
    );

    if (validAssets.length === 0) {
      toast.error('No saved assets to assign. Try regenerating.');
      return;
    }

    setIsSaving(true);
    let saved = 0;
    for (const asset of validAssets) {
      try {
        await saveToProjectMutation.mutateAsync({
          assetId: parseInt(asset.id),
          projectId,
        });
        saved++;
      } catch (err) {
        console.warn(`Failed to save asset ${asset.id}:`, err);
      }
    }

    setIsSaving(false);
    setOpen(false);
    setSelectedProjectId('');

    if (saved > 0) {
      const projName = projects.find((p) => p.id === projectId)?.name ?? 'project';
      toast.success(`Saved ${saved} images to "${projName}"`);
      onComplete();
    } else {
      toast.error('Failed to save any images');
    }
  }, [selectedProjectId, selectedAssets, saveToProjectMutation, projects, onComplete]);

  /** Create a new project then auto-select it in the dropdown */
  const handleCreate = useCallback(async () => {
    if (!newName.trim()) return;
    setIsSaving(true);
    try {
      const result = await createProjectMutation.mutateAsync({ name: newName, type: 'image' });
      await projectsQuery.refetch();
      setSelectedProjectId(String(result.id));
      setNewName('');
      setShowNew(false);
      toast.success(`Created project "${result.name}"`);
    } catch (err) {
      toast.error('Failed to create project');
    } finally {
      setIsSaving(false);
    }
  }, [newName, createProjectMutation, projectsQuery]);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button size="sm" variant="secondary" className="gap-1.5 text-xs h-8">
          <FolderOpen className="w-3.5 h-3.5" />
          Save to Project
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[260px] p-3" side="top" align="center">
        <div className="space-y-2">
          <p className="text-xs font-medium text-muted-foreground">
            Save {selectedAssets.length} image{selectedAssets.length !== 1 ? 's' : ''} to project
          </p>

          {showNew ? (
            <div className="space-y-2">
              <Input
                placeholder="Project name..."
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                disabled={isSaving}
                className="text-sm h-8"
                onKeyDown={(e) => e.key === 'Enter' && handleCreate()}
              />
              <div className="flex gap-2">
                <Button size="sm" onClick={handleCreate} disabled={!newName.trim() || isSaving} className="flex-1 h-7 text-xs">
                  {isSaving ? <Loader2 className="w-3 h-3 animate-spin" /> : <FolderPlus className="w-3 h-3" />}
                  Create
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setShowNew(false)} disabled={isSaving} className="h-7 text-xs">
                  Cancel
                </Button>
              </div>
            </div>
          ) : (
            <div className="space-y-2">
              <div className="flex gap-2">
                <Select value={selectedProjectId} onValueChange={setSelectedProjectId} disabled={isSaving}>
                  <SelectTrigger className="flex-1">
                    <SelectValue placeholder="Select project..." />
                  </SelectTrigger>
                  <SelectContent>
                    {projects.length === 0 ? (
                      <SelectItem value="no-projects" disabled>No projects yet</SelectItem>
                    ) : (
                      projects.map((p) => (
                        <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>
                      ))
                    )}
                  </SelectContent>
                </Select>
                <Button
                  size="icon"
                  variant="outline"
                  onClick={() => setShowNew(true)}
                  disabled={isSaving}
                  title="New Project"
                  className="h-8 w-8 shrink-0"
                >
                  <FolderPlus className="w-3.5 h-3.5" />
                </Button>
              </div>
              <Button
                size="sm"
                onClick={handleSave}
                disabled={!selectedProjectId || isSaving}
                className="w-full h-7 text-xs gap-1"
              >
                {isSaving ? <Loader2 className="w-3 h-3 animate-spin" /> : <FolderOpen className="w-3 h-3" />}
                Save
              </Button>
            </div>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}
