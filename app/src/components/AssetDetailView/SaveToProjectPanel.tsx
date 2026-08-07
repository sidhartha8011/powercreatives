/**
 * SAVE TO PROJECT PANEL
 * 
 * Dropdown to select project + create new project option.
 * Saves asset to selected project.
 * PowerKeys-consistent compact design.
 */

import { useState } from "react";
import { FolderPlus, Save, Plus } from "lucide-react";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { SectionPanel } from "./SectionPanel";
import { ActionButton } from "./ActionButton";
import type { SaveToProjectPanelProps } from "./types";

export function SaveToProjectPanel({
  asset,
  projects,
  isLoading,
  onSave,
  onCreateProject,
}: SaveToProjectPanelProps) {
  const [selectedProjectId, setSelectedProjectId] = useState<string>("");
  const [showNewProject, setShowNewProject] = useState(false);
  const [newProjectName, setNewProjectName] = useState("");

  const handleSave = async () => {
    if (!selectedProjectId) return;
    await onSave(parseInt(selectedProjectId));
  };

  const handleCreateProject = async () => {
    if (!newProjectName.trim()) return;
    const newId = await onCreateProject(newProjectName);
    // Auto-select the newly created project in the dropdown
    if (newId) {
      setSelectedProjectId(newId.toString());
    }
    setNewProjectName("");
    setShowNewProject(false);
  };

  return (
    <SectionPanel title="Save to Project">
      {showNewProject ? (
        <div className="space-y-2">
          <Input
            placeholder="Project name..."
            value={newProjectName}
            onChange={(e) => setNewProjectName(e.target.value)}
            disabled={isLoading}
            className="text-sm"
          />
          <div className="flex gap-2">
            <ActionButton
              onClick={handleCreateProject}
              disabled={!newProjectName.trim()}
              loading={isLoading}
              className="flex-1"
              icon={<Plus className="h-3.5 w-3.5" />}
            >
              Create
            </ActionButton>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setShowNewProject(false)}
              disabled={isLoading}
            >
              Cancel
            </Button>
          </div>
        </div>
      ) : (
        <div className="space-y-2">
          <div className="flex gap-2">
            <Select
              value={selectedProjectId}
              onValueChange={setSelectedProjectId}
              disabled={isLoading}
            >
              <SelectTrigger className="flex-1">
                <SelectValue placeholder="Select project..." />
              </SelectTrigger>
              <SelectContent>
                {projects.length === 0 ? (
                  <SelectItem value="no-projects" disabled>
                    No projects yet
                  </SelectItem>
                ) : (
                  projects.map((project) => (
                    <SelectItem key={project.id} value={project.id.toString()}>
                      {project.name}
                    </SelectItem>
                  ))
                )}
              </SelectContent>
            </Select>
            <Button
              variant="outline"
              size="icon"
              onClick={() => setShowNewProject(true)}
              disabled={isLoading}
              title="New Project"
              className="h-9 w-9 shrink-0"
            >
              <FolderPlus className="h-4 w-4" />
            </Button>
          </div>
          <ActionButton
            onClick={handleSave}
            disabled={!selectedProjectId}
            loading={isLoading}
            icon={<Save className="h-4 w-4" />}
          >
            Save
          </ActionButton>
        </div>
      )}
    </SectionPanel>
  );
}
