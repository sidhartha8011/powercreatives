import React, { useState, useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Plus, Folder, LayoutGrid, List, Clock, MoreHorizontal, Search, X } from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useSortableTable } from '@/hooks/useSortableTable';
import { SortableTableHead } from '@/components/ui/sortable-table-head';

// Dummy data
const MOCK_PROJECTS = [
  {
    id: 1,
    name: 'Q3 Agency Assets',
    type: 'campaign',
    createdAt: '2026-03-15T10:00:00Z',
    assetCount: 12,
    images: [
      'https://images.unsplash.com/photo-1542204165-65bf26472b9b?w=200&h=200&fit=crop',
      'https://images.unsplash.com/photo-1581291518633-83b4ebd1d83e?w=200&h=200&fit=crop',
      'https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?w=200&h=200&fit=crop',
      'https://images.unsplash.com/photo-1498050108023-c5249f4df085?w=200&h=200&fit=crop',
    ],
  },
  {
    id: 2,
    name: 'Client: Lunar Beauty',
    type: 'branding',
    createdAt: '2026-04-01T14:30:00Z',
    assetCount: 3,
    images: [
      'https://images.unsplash.com/photo-1611078489935-0cb964de46d6?w=200&h=200&fit=crop',
      'https://images.unsplash.com/photo-1596462502278-27bfdc403348?w=200&h=200&fit=crop',
      'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?w=200&h=200&fit=crop',
    ],
  },
  {
    id: 3,
    name: 'Empty Project Idea',
    type: 'general',
    createdAt: '2026-04-04T09:12:00Z',
    assetCount: 0,
    images: [],
  },
  {
    id: 4,
    name: 'Social Media Templates',
    type: 'social',
    createdAt: '2026-03-20T16:45:00Z',
    assetCount: 2,
    images: [
      'https://images.unsplash.com/photo-1432821596592-e2c18b78144f?w=200&h=200&fit=crop',
      'https://images.unsplash.com/photo-1557804506-669a67965ba0?w=200&h=200&fit=crop',
    ],
  },
  {
    id: 5,
    name: 'Summer Sale Assets',
    type: 'campaign',
    createdAt: '2026-04-02T08:15:00Z',
    assetCount: 8,
    images: [
      'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=200&h=200&fit=crop',
      'https://images.unsplash.com/photo-1473496169904-658ba7c44d8a?w=200&h=200&fit=crop',
    ],
  },
];

// Utility to generate a stable gradient
const getGradient = (id: number) => {
  const colors = [
    'linear-gradient(135deg, #fceabb 0%, #f8b500 100%)',
    'linear-gradient(135deg, #13547a 0%, #80d0c7 100%)',
    'linear-gradient(135deg, #3a1c71 0%, #d76d77 50%, #ffaf7b 100%)',
    'linear-gradient(135deg, #0ba360 0%, #3cba92 100%)',
  ];
  return colors[id % colors.length];
};

export function ProjectsMockup() {
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
  
  // Filter States
  const [searchQuery, setSearchQuery] = useState('');
  const [typeFilter, setTypeFilter] = useState('all');

  const formatDate = (dateStr: string) => {
    return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute:'2-digit' }).format(new Date(dateStr));
  };

  // 1. Apply Filters
  const filteredProjects = useMemo(() => {
    return MOCK_PROJECTS.filter(p => {
      const matchesSearch = p.name.toLowerCase().includes(searchQuery.toLowerCase());
      const matchesType = typeFilter === 'all' || p.type === typeFilter;
      return matchesSearch && matchesType;
    });
  }, [searchQuery, typeFilter]);

  type MockProject = typeof MOCK_PROJECTS[0];
  type ProjectSortKey = 'name' | 'type' | 'assetCount' | 'createdAt';

  // 2. Apply Sorting via Reusable Hook (Used in List View)
  const { sortKey, sortDir, toggleSort, sortedData } = useSortableTable<MockProject, ProjectSortKey>(filteredProjects, {
    defaultKey: 'createdAt',
    defaultDir: 'desc',
    accessors: {
      name: (row) => row.name.toLowerCase(),
      createdAt: (row) => new Date(row.createdAt).getTime(),
      assetCount: (row) => row.assetCount,
      type: (row) => row.type.toLowerCase(),
    },
  });

  // Decide what data to map over (Grid defaults to newest first, List uses table sorting)
  const displayData = viewMode === 'list' 
    ? sortedData 
    : [...filteredProjects].sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime());

  const hasActiveFilters = searchQuery !== '' || typeFilter !== 'all';

  const clearFilters = () => {
    setSearchQuery('');
    setTypeFilter('all');
  };

  return (
    <div className="animate-fade-in p-6 bg-white min-h-screen">
      {/* Header section */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900">Projects (Mockup)</h1>
          <p className="text-sm text-slate-500 mt-1">Manage your projects and keep your campaign assets organized</p>
        </div>
        <div className="flex items-center gap-3">
          <div className="flex bg-slate-100 p-1 rounded-md border border-slate-200">
            <button onClick={() => setViewMode('grid')} className={`p-1.5 rounded-sm transition-all ${viewMode === 'grid' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'}`}>
              <LayoutGrid className="w-4 h-4" />
            </button>
            <button onClick={() => setViewMode('list')} className={`p-1.5 rounded-sm transition-all ${viewMode === 'list' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'}`}>
              <List className="w-4 h-4" />
            </button>
          </div>
          <Button className="rounded-full shadow-sm font-semibold">
            <Plus className="w-4 h-4 mr-2" />
            New Project
          </Button>
        </div>
      </div>

      {/* Global Filter Bar (Reused Pattern) */}
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
          
          {hasActiveFilters && (
            <Button variant="ghost" size="sm" onClick={clearFilters} className="h-9 px-2 text-slate-500">
              <X className="w-4 h-4 mr-1" />
              Clear Filters
            </Button>
          )}

          <div className="ml-auto text-xs text-slate-500 font-medium">
             Showing {displayData.length} projects
          </div>
      </div>

      {displayData.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-20 border border-dashed rounded-xl border-slate-200 bg-slate-50">
          <Folder className="w-12 h-12 text-slate-300 mb-4" />
          <h3 className="text-slate-700 font-medium">No projects found</h3>
          <p className="text-slate-500 text-sm mt-1 mb-4">Try adjusting your search or filters</p>
          <Button variant="outline" onClick={clearFilters}>Clear Filters</Button>
        </div>
      ) : viewMode === 'grid' ? (
        /* ======================== GRID VIEW ======================== */
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
          {displayData.map((p) => (
            <div key={p.id} className="group relative bg-white border border-slate-200 rounded-xl overflow-hidden hover:border-blue-200 hover:shadow-md transition-all cursor-pointer flex flex-col h-[220px]">
              <div className="h-[120px] w-full bg-slate-50 border-b border-slate-100 relative">
                {p.images.length > 0 ? (
                  <div className={`grid ${p.images.length === 1 ? 'grid-cols-1' : p.images.length === 2 ? 'grid-cols-2' : 'grid-cols-2 grid-rows-2'} w-full h-full gap-0.5`}>
                    {p.images.slice(0, 4).map((img, i) => (
                      <div key={i} className="w-full h-full overflow-hidden bg-slate-100">
                        <img src={img} className="w-full h-full object-cover" />
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="w-full h-full flex items-center justify-center opacity-80" style={{ background: getGradient(p.id) }}>
                    <Folder className="w-10 h-10 text-white/50" />
                  </div>
                )}
                
                <div className="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                   <DropdownMenu>
                     <DropdownMenuTrigger asChild>
                       <button className="bg-white/90 backdrop-blur-sm p-1.5 rounded-md shadow-sm text-slate-700 hover:text-blue-600 border border-slate-200/50">
                         <MoreHorizontal className="w-4 h-4" />
                       </button>
                     </DropdownMenuTrigger>
                     <DropdownMenuContent align="end">
                       <DropdownMenuItem>Rename Project</DropdownMenuItem>
                       <DropdownMenuItem>Duplicate</DropdownMenuItem>
                       <DropdownMenuItem className="text-red-600">Delete</DropdownMenuItem>
                     </DropdownMenuContent>
                   </DropdownMenu>
                </div>
              </div>

              <div className="p-3 flex-1 flex flex-col justify-between">
                <div>
                  <h3 className="font-semibold text-slate-800 text-sm truncate">{p.name}</h3>
                  <div className="flex items-center gap-2 mt-1">
                    <span className="text-[10px] text-slate-500 uppercase tracking-wider font-semibold bg-slate-100 px-1.5 py-0.5 rounded">{p.type}</span>
                  </div>
                </div>
                
                <div className="flex items-center justify-between mt-2 pt-2 border-t border-slate-100/50">
                   <span className="text-xs font-medium text-slate-600">
                     {p.assetCount} assets
                   </span>
                   <div className="flex items-center text-[11px] text-slate-400">
                     <Clock className="w-3 h-3 mr-1" />
                     {formatDate(p.createdAt)}
                   </div>
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : (
        /* ======================== LIST VIEW ======================== */
        <div className="bg-white border border-slate-200 rounded-lg overflow-hidden shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50/80 border-b border-slate-200 text-slate-600 font-medium">
              <tr>
                <SortableTableHead<"name"> columnKey="name" label="Name" currentSortKey={sortKey as any} currentSortDir={sortDir} onToggle={toggleSort as any} className="px-4 py-3 w-[40%]" />
                <SortableTableHead<"type"> columnKey="type" label="Type" currentSortKey={sortKey as any} currentSortDir={sortDir} onToggle={toggleSort as any} className="px-4 py-3" />
                <SortableTableHead<"assetCount"> columnKey="assetCount" label="Assets" currentSortKey={sortKey as any} currentSortDir={sortDir} onToggle={toggleSort as any} className="px-4 py-3" />
                <SortableTableHead<"createdAt"> columnKey="createdAt" label="Last Updated" currentSortKey={sortKey as any} currentSortDir={sortDir} onToggle={toggleSort as any} className="px-4 py-3" />
                <th className="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {displayData.map((p) => (
                <tr key={p.id} className="hover:bg-slate-50/80 group transition-colors cursor-pointer">
                  <td className="px-4 py-3 flex items-center gap-3">
                    <div className="w-8 h-8 rounded-md overflow-hidden bg-slate-100 border border-slate-200/60 shadow-sm flex-shrink-0">
                      {p.images.length > 0 ? (
                        <img src={p.images[0]} className="w-full h-full object-cover" />
                      ) : (
                        <div className="w-full h-full" style={{ background: getGradient(p.id) }} />
                      )}
                    </div>
                    <span className="font-semibold text-slate-800">{p.name}</span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="text-[10px] text-slate-500 uppercase tracking-wider font-semibold bg-slate-100 px-1.5 py-0.5 rounded">{p.type}</span>
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    {p.assetCount} items
                  </td>
                  <td className="px-4 py-3 text-slate-500">
                    {formatDate(p.createdAt)}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center gap-2">
                       <Button variant="ghost" size="sm" className="h-8 px-2 text-slate-500 hover:text-blue-600 hover:bg-blue-50">Open</Button>
                       <DropdownMenu>
                         <DropdownMenuTrigger asChild>
                           <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-slate-500"><MoreHorizontal className="w-4 h-4" /></Button>
                         </DropdownMenuTrigger>
                         <DropdownMenuContent align="end">
                           <DropdownMenuItem>Rename</DropdownMenuItem>
                           <DropdownMenuItem>Duplicate</DropdownMenuItem>
                           <DropdownMenuItem className="text-red-600">Delete</DropdownMenuItem>
                         </DropdownMenuContent>
                       </DropdownMenu>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
