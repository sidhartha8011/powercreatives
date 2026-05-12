/**
 * CREATIVE MACHINE - Module Header Component
 * Consistent header for all modules
 * 
 * PowerKeys Specs:
 * - Header title: font-size 1.1rem, font-weight 600
 * - Description: font-size 0.85rem, color #666
 */

import React from 'react';

interface ModuleHeaderProps {
  title: string;
  description?: string;
  action?: React.ReactNode;
}

export function ModuleHeader({ title, description, action }: ModuleHeaderProps) {
  return (
    <div className="flex items-start justify-between mb-6">
      <div>
        <h1 
          style={{ 
            fontSize: '1.25rem', 
            fontWeight: 600, 
            color: '#1a1a1a',
            margin: 0
          }}
        >
          {title}
        </h1>
        {description && (
          <p 
            style={{ 
              fontSize: '0.85rem', 
              color: '#666',
              marginTop: '0.25rem'
            }}
          >
            {description}
          </p>
        )}
      </div>
      {action && <div>{action}</div>}
    </div>
  );
}
