/**
 * CREATIVE MACHINE - Deliveries Module
 * Continual fulfilment deliveries for clients which contain projects
 * 
 * Deliveries are containers for projects, enabling organized client delivery workflows.
 */

import { useState } from 'react';
import { Package, Plus, FolderOpen, Calendar, Users, MoreHorizontal } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/shared/EmptyState';

interface Delivery {
  id: string;
  name: string;
  clientName: string;
  projectCount: number;
  status: 'active' | 'completed' | 'paused';
  createdAt: Date;
  updatedAt: Date;
}

export function DeliveriesModule() {
  const [deliveries, setDeliveries] = useState<Delivery[]>([]);

  const getStatusColor = (status: Delivery['status']) => {
    switch (status) {
      case 'active':
        return 'bg-green-100 text-green-700';
      case 'completed':
        return 'bg-blue-100 text-blue-700';
      case 'paused':
        return 'bg-yellow-100 text-yellow-700';
    }
  };

  return (
    <div className="h-full">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Deliveries</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Continual fulfilment deliveries for clients which contain projects
          </p>
        </div>
        <Button className="gap-2">
          <Plus className="w-4 h-4" />
          New Delivery
        </Button>
      </div>

      {/* Content */}
      {deliveries.length === 0 ? (
        <div className="flex items-center justify-center h-[calc(100%-100px)]">
          <EmptyState
            icon={<Package className="w-12 h-12" />}
            title="No deliveries yet"
            description="Create your first delivery to start organizing client projects and fulfilment workflows"
            action={
              <Button className="gap-2">
                <Plus className="w-4 h-4" />
                Create Delivery
              </Button>
            }
          />
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {deliveries.map((delivery) => (
            <div
              key={delivery.id}
              className="card-powerkeys p-5 hover:shadow-md transition-shadow cursor-pointer"
            >
              {/* Header */}
              <div className="flex items-start justify-between mb-3">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                    <Package className="w-5 h-5 text-primary" />
                  </div>
                  <div>
                    <h3 className="font-semibold text-foreground">{delivery.name}</h3>
                    <p className="text-sm text-muted-foreground">{delivery.clientName}</p>
                  </div>
                </div>
                <Button variant="ghost" size="icon" className="h-8 w-8">
                  <MoreHorizontal className="w-4 h-4" />
                </Button>
              </div>

              {/* Stats */}
              <div className="flex items-center gap-4 text-sm text-muted-foreground mb-3">
                <div className="flex items-center gap-1.5">
                  <FolderOpen className="w-4 h-4" />
                  <span>{delivery.projectCount} projects</span>
                </div>
                <div className="flex items-center gap-1.5">
                  <Calendar className="w-4 h-4" />
                  <span>{new Date(delivery.updatedAt).toLocaleDateString()}</span>
                </div>
              </div>

              {/* Status Badge */}
              <div className="flex items-center justify-between">
                <span className={`px-2.5 py-1 rounded-full text-xs font-medium capitalize ${getStatusColor(delivery.status)}`}>
                  {delivery.status}
                </span>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
