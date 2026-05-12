import React from 'react';
import { Button } from '@/components/ui/button';
import { SortableTableHead } from '@/components/ui/sortable-table-head';
import { Project } from '../types';
import { ProjectDropdownActions } from './ProjectDropdownActions';
import { getGradient, formatProjectDate } from '../utils';

interface ProjectListProps {
  projects: Project[];
  onSelectProject: (p: Project) => void;
  onRenameProject: (p: Project) => void;
  onDuplicateProject: (p: Project) => void;
  onDeleteProject: (p: Project) => void;
  
  // Sorting props
  sortKey: string;
  sortDir: 'asc' | 'desc';
  toggleSort: (key: string) => void;

  mutatingProjectId?: number | null;
  isDeleting?: boolean;
  isDuplicating?: boolean;
}

export function ProjectList({
  projects,
  onSelectProject,
  onRenameProject,
  onDuplicateProject,
  onDeleteProject,
  sortKey,
  sortDir,
  toggleSort,
  mutatingProjectId,
  isDeleting,
  isDuplicating,
}: ProjectListProps) {
  return (
    <div className="bg-white border border-slate-200 rounded-lg overflow-hidden shadow-sm">
      <table className="w-full text-left text-sm">
        <thead className="bg-slate-50/80 border-b border-slate-200 text-slate-600 font-medium">
          <tr>
            <SortableTableHead<Project, 'name'>
              columnKey="name"
              label="Name"
              currentSortKey={sortKey as any}
              currentSortDir={sortDir}
              onToggle={toggleSort as any}
              className="px-4 py-3 w-[40%]"
            />
            <SortableTableHead<Project, 'type'>
              columnKey="type"
              label="Type"
              currentSortKey={sortKey as any}
              currentSortDir={sortDir}
              onToggle={toggleSort as any}
              className="px-4 py-3"
            />
            <SortableTableHead<Project, 'assetCount'>
              columnKey="assetCount"
              label="Assets"
              currentSortKey={sortKey as any}
              currentSortDir={sortDir}
              onToggle={toggleSort as any}
              className="px-4 py-3"
            />
            <SortableTableHead<Project, 'createdAt'>
              columnKey="createdAt"
              label="Last Updated"
              currentSortKey={sortKey as any}
              currentSortDir={sortDir}
              onToggle={toggleSort as any}
              className="px-4 py-3"
            />
            <th className="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {projects.map((p) => {
            const isMutatingThis = mutatingProjectId === p.id;
            
            return (
              <tr
                key={p.id}
                onClick={() => onSelectProject(p)}
                className="hover:bg-slate-50/80 group transition-colors cursor-pointer"
              >
                <td className="px-4 py-3 flex items-center gap-3">
                  <div className="w-8 h-8 rounded-md overflow-hidden bg-slate-100 border border-slate-200/60 shadow-sm flex-shrink-0">
                    {p.images && p.images.length > 0 ? (
                      <img src={p.images[0]} className="w-full h-full object-cover" />
                    ) : (
                      <div className="w-full h-full" style={{ background: getGradient(p.id) }} />
                    )}
                  </div>
                  <span className="font-semibold text-slate-800 truncate max-w-[250px]" title={p.name}>
                    {p.name}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <span className="text-[10px] text-slate-500 uppercase tracking-wider font-semibold bg-slate-100 px-1.5 py-0.5 rounded">
                    {p.type}
                  </span>
                </td>
                <td className="px-4 py-3 text-slate-600">
                  {p.assetCount || 0} items
                </td>
                <td className="px-4 py-3 text-slate-500">
                  {formatProjectDate(p.createdAt)}
                </td>
                <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                  <div className="opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => onSelectProject(p)}
                      className="h-8 px-2 text-slate-500 hover:text-blue-600 hover:bg-blue-50"
                    >
                      Open
                    </Button>
                    <ProjectDropdownActions
                      onRename={() => onRenameProject(p)}
                      onDuplicate={() => onDuplicateProject(p)}
                      onDelete={() => onDeleteProject(p)}
                      isDeleting={isDeleting && isMutatingThis}
                      isDuplicating={isDuplicating && isMutatingThis}
                    />
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
