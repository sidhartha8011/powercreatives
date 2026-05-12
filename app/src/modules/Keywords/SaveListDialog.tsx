import React, { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { trpc } from '@/lib/trpc';
import { Loader2 } from 'lucide-react';

interface SaveListDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  defaultName: string;
  onSave: (name: string, projectId: number) => void;
  isSaving: boolean;
}

export function SaveListDialog({
  open,
  onOpenChange,
  defaultName,
  onSave,
  isSaving,
}: SaveListDialogProps) {
  const [name, setName] = useState('');
  const [projectId, setProjectId] = useState<string>('none');

  // Fetch projects from the existing projects module (assets layer)
  const { data: projects, isLoading: projectsLoading } = trpc.assets.getProjects.useQuery();

  const handleSave = () => {
    // If the user left it empty, fallback to the default (auto-generated) name exactly as requested
    const finalName = name.trim() ? name.trim() : defaultName;
    const finalProjectId = projectId !== 'none' ? parseInt(projectId, 10) : 0;
    
    onSave(finalName, finalProjectId);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Save Keyword List</DialogTitle>
        </DialogHeader>
        
        <div className="grid gap-6 py-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="list-name">List Name (Optional)</Label>
            <Input
              id="list-name"
              placeholder={defaultName}
              value={name}
              onChange={(e) => setName(e.target.value)}
              autoFocus
            />
            <p className="text-xs text-muted-foreground">
              Leave blank to automatically use: {defaultName}
            </p>
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="project">Assign to Project (Optional)</Label>
            <Select value={projectId} onValueChange={setProjectId}>
              <SelectTrigger id="project" className="w-full">
                <SelectValue placeholder="Select a project..." />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="none">No Project (Unassigned)</SelectItem>
                {projectsLoading ? (
                  <div className="flex items-center p-2 text-sm text-muted-foreground">
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" /> Loading...
                  </div>
                ) : (
                  projects?.map((p: any) => (
                    <SelectItem key={p.id} value={p.id.toString()}>
                      {p.name}
                    </SelectItem>
                  ))
                )}
              </SelectContent>
            </Select>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isSaving}>
            Cancel
          </Button>
          <Button onClick={handleSave} disabled={isSaving}>
            {isSaving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
            Save List
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
