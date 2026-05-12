import React from 'react';
import { Folder, Clock } from 'lucide-react';
import { Project } from '../types';
import { ProjectDropdownActions } from './ProjectDropdownActions';
import { getGradient, formatProjectDate } from '../utils';

interface ProjectGridProps {
  projects: Project[];
  onSelectProject: (p: Project) => void;
  onRenameProject: (p: Project) => void;
  onDuplicateProject: (p: Project) => void;
  onDeleteProject: (p: Project) => void;
  mutatingProjectId?: number | null;
  isDeleting?: boolean;
  isDuplicating?: boolean;
}

export function ProjectGrid({
  projects,
  onSelectProject,
  onRenameProject,
  onDuplicateProject,
  onDeleteProject,
  mutatingProjectId,
  isDeleting,
  isDuplicating,
}: ProjectGridProps) {
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
      {projects.map((p) => {
        const isMutatingThis = mutatingProjectId === p.id;

        return (
          <div
            key={p.id}
            onClick={() => onSelectProject(p)}
            className="group relative bg-white border border-slate-200 rounded-xl overflow-hidden hover:border-blue-200 hover:shadow-md transition-all cursor-pointer flex flex-col h-[220px]"
          >
            <div className="h-[120px] w-full bg-slate-50 border-b border-slate-100 relative">
              {p.images && p.images.length > 0 ? (
                <div
                  className={`grid ${
                    p.images.length === 1
                      ? 'grid-cols-1'
                      : p.images.length === 2
                      ? 'grid-cols-2'
                      : 'grid-cols-2 grid-rows-2'
                  } w-full h-full gap-0.5`}
                >
                  {p.images.slice(0, 4).map((img, i) => (
                    <div key={i} className="w-full h-full overflow-hidden bg-slate-100">
                      <img src={img} className="w-full h-full object-cover" />
                    </div>
                  ))}
                </div>
              ) : (
                <div
                  className="w-full h-full flex items-center justify-center opacity-80"
                  style={{ background: getGradient(p.id) }}
                >
                  <Folder className="w-10 h-10 text-white/50" />
                </div>
              )}

              {/* Dropdown Menu Overlay */}
              <div className="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                <div className="bg-white/90 backdrop-blur-sm rounded-md shadow-sm border border-slate-200/50 flex">
                  <ProjectDropdownActions
                    onRename={() => onRenameProject(p)}
                    onDuplicate={() => onDuplicateProject(p)}
                    onDelete={() => onDeleteProject(p)}
                    isDeleting={isDeleting && isMutatingThis}
                    isDuplicating={isDuplicating && isMutatingThis}
                  />
                </div>
              </div>
            </div>

            <div className="p-3 flex-1 flex flex-col justify-between">
              <div>
                <h3 className="font-semibold text-slate-800 text-sm truncate" title={p.name}>
                  {p.name}
                </h3>
                <div className="flex items-center gap-2 mt-1">
                  <span className="text-[10px] text-slate-500 uppercase tracking-wider font-semibold bg-slate-100 px-1.5 py-0.5 rounded">
                    {p.type}
                  </span>
                </div>
              </div>

              <div className="flex items-center justify-between mt-2 pt-2 border-t border-slate-100/50">
                <span className="text-xs font-medium text-slate-600">
                  {p.assetCount || 0} assets
                </span>
                <div className="flex items-center text-[11px] text-slate-400">
                  <Clock className="w-3 h-3 mr-1" />
                  {formatProjectDate(p.createdAt)}
                </div>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}
