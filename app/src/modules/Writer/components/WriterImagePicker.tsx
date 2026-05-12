import React, { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { ImageIcon, UploadIcon, Wand2Icon } from 'lucide-react';
import { WriterImageUploadTab } from './WriterImageUploadTab';
import { WriterImageGenerateTab } from './WriterImageGenerateTab';

interface WriterImagePickerProps {
  isOpen: boolean;
  onClose: () => void;
  onOpenWP: () => void;
  onInsertImage: (url: string) => void;
  contextText?: string;
}

export function WriterImagePicker({
  isOpen,
  onClose,
  onOpenWP,
  onInsertImage,
  contextText = '',
}: WriterImagePickerProps) {
  const [activeTab, setActiveTab] = useState<string>('library');


  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-[700px] gap-0 p-0 overflow-hidden bg-background border-border">
        <DialogHeader className="px-6 py-4 border-b border-border bg-muted/30">
          <DialogTitle>Insert Image</DialogTitle>
        </DialogHeader>

        <Tabs value={activeTab} onValueChange={setActiveTab} className="flex flex-col">
          <div className="px-6 pt-4 border-b border-border">
            <TabsList className="grid w-full grid-cols-3 h-10 bg-muted">
              <TabsTrigger value="library" className="flex items-center gap-2">
                <ImageIcon className="w-4 h-4" /> Library
              </TabsTrigger>
              <TabsTrigger value="upload" className="flex items-center gap-2">
                <UploadIcon className="w-4 h-4" /> Upload
              </TabsTrigger>
              <TabsTrigger value="generate" className="flex items-center gap-2">
                <Wand2Icon className="w-4 h-4" /> Generate
              </TabsTrigger>
            </TabsList>
          </div>

          <div className="p-6 h-[400px] overflow-y-auto w-full">
            {/* ── Library Tab ── */}
            <TabsContent value="library" className="m-0 h-full flex flex-col items-center justify-center text-center space-y-4">
              <div className="w-16 h-16 rounded-full bg-muted flex items-center justify-center text-muted-foreground">
                <ImageIcon className="w-8 h-8" />
              </div>
              <div>
                <h3 className="font-medium text-foreground text-lg mb-1">WordPress Media Library</h3>
                <p className="text-sm text-muted-foreground max-w-[300px] mx-auto mb-6">
                  Browse your existing images or drop files directly into the native media manager.
                </p>
                <Button onClick={onOpenWP} size="lg">
                  Open Media Library
                </Button>
              </div>
            </TabsContent>

            {/* ── Upload Tab ── */}
            <WriterImageUploadTab onInsertImage={onInsertImage} onClose={onClose} />

            {/* ── Generate Tab ── */}
            <WriterImageGenerateTab onInsertImage={onInsertImage} onClose={onClose} contextText={contextText} />
          </div>
        </Tabs>
      </DialogContent>
    </Dialog>
  );
}
