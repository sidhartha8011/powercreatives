import React, { useState } from 'react';
import { Filter } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const DEFAULT_DOMAINS = [
  'youtube.com',
  'facebook.com',
  'instagram.com',
  'twitter.com',
  'pinterest.com',
  'tiktok.com',
  'linkedin.com'
];

interface DataTableSerpFilterProps {
  blacklist: string[];
  setBlacklist: (domains: string[]) => void;
}

export function DataTableSerpFilter({ blacklist, setBlacklist }: DataTableSerpFilterProps) {
  const [customInput, setCustomInput] = useState('');

  // Combine defaults with any custom domains the user has added
  const allDomains = Array.from(new Set([...DEFAULT_DOMAINS, ...blacklist])).sort();

  const toggleDomain = (domain: string, checked: boolean) => {
    if (checked) {
      setBlacklist([...blacklist, domain]);
    } else {
      setBlacklist(blacklist.filter((d) => d !== domain));
    }
  };

  const handleAddCustom = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && customInput.trim()) {
      const newDomain = customInput.trim().toLowerCase().replace(/^https?:\/\//, '').split('/')[0];
      if (newDomain && !blacklist.includes(newDomain)) {
        setBlacklist([...blacklist, newDomain]);
      }
      setCustomInput('');
      e.preventDefault();
      e.stopPropagation();
    }
  };

  const isActive = blacklist.length > 0;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button 
          variant="outline" 
          size="sm" 
          className={`h-8 flex ${isActive ? 'bg-primary/10 text-primary border-primary hover:bg-primary/20 hover:text-primary' : ''}`}
        >
          <Filter className="mr-2 h-4 w-4" />
          SERP
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-[200px]">
        <DropdownMenuLabel>Blacklist Domains</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <div className="max-h-[300px] overflow-y-auto">
          {allDomains.map((domain) => (
            <DropdownMenuCheckboxItem
              key={domain}
              checked={blacklist.includes(domain)}
              onCheckedChange={(checked) => toggleDomain(domain, !!checked)}
              onSelect={(e) => e.preventDefault()}
            >
              {domain}
            </DropdownMenuCheckboxItem>
          ))}
        </div>
        <DropdownMenuSeparator />
        <div className="p-2" onKeyDown={(e) => e.stopPropagation()}>
          <Input 
            placeholder="Add custom domain..." 
            className="h-8 text-xs" 
            value={customInput}
            onChange={(e) => setCustomInput(e.target.value)}
            onKeyDown={handleAddCustom}
          />
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
