export interface Project {
  id: number;
  name: string;
  description?: string;
  status: string;
  type: string;
  assetCount: number;
  images: string[];
  createdAt: string;
}
