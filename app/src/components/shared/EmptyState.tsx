/**
 * CREATIVE MACHINE - Empty State Component
 * Displayed when a module has no content
 * 
 * PowerKeys Specs:
 * - Title: font-size 1.1rem, font-weight 600, color #1a1a1a
 * - Description: font-size 0.85rem, color #666
 */

import React from 'react';

interface EmptyStateProps {
  icon: React.ReactNode;
  title: string;
  description?: string;
  action?: React.ReactNode;
}

export function EmptyState({ icon, title, description, action }: EmptyStateProps) {
  return (
    <div className="flex flex-col items-center justify-center py-16 px-4 text-center">
      <div style={{ color: '#ccc', marginBottom: '1rem' }}>
        {icon}
      </div>
      <h3 style={{ fontSize: '1.1rem', fontWeight: 600, color: '#1a1a1a', marginBottom: '0.25rem' }}>
        {title}
      </h3>
      {description && (
        <p style={{ fontSize: '0.85rem', color: '#666', maxWidth: '24rem', marginBottom: '1.5rem' }}>
          {description}
        </p>
      )}
      {action}
    </div>
  );
}
