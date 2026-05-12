import React from 'react';
import { Button } from '@/components/ui/button';
import { TabsContent } from '@/components/ui/tabs';
import { Loader2Icon, UploadIcon } from 'lucide-react';
import { useWriterImageUpload } from '../hooks/useWriterImageUpload';

interface WriterImageUploadTabProps {
    onInsertImage: (url: string) => void;
    onClose: () => void;
}

export function WriterImageUploadTab({ onInsertImage, onClose }: WriterImageUploadTabProps) {
    const {
        isUploading,
        isDragActive,
        uploadPreview,
        fileInputRef,
        handleFileSelect,
        handleDrop,
        handleDragEnter,
        handleDragLeave,
        clearPreview
    } = useWriterImageUpload();

    return (
        <TabsContent value="upload" className="m-0 h-full flex flex-col">
            {!uploadPreview ? (
                <div
                    onDragOver={(e) => e.preventDefault()}
                    onDragEnter={handleDragEnter}
                    onDragLeave={handleDragLeave}
                    onDrop={handleDrop}
                    onClick={() => fileInputRef.current?.click()}
                    className={`flex-1 border-2 border-dashed rounded-xl flex flex-col items-center justify-center text-center p-6 transition-colors cursor-pointer ${isDragActive
                            ? 'border-blue-500 bg-blue-50/50 dark:bg-blue-900/20'
                            : 'border-border hover:bg-muted/50'
                        }`}
                >
                    <input
                        type="file"
                        ref={fileInputRef}
                        className="hidden"
                        accept="image/*"
                        onChange={handleFileSelect}
                    />
                    {isUploading ? (
                        <div className="flex flex-col items-center gap-2 text-muted-foreground">
                            <Loader2Icon className="w-8 h-8 animate-spin" />
                            <p className="text-sm font-medium">Uploading to server...</p>
                        </div>
                    ) : (
                        <>
                            <div className="w-12 h-12 rounded-full bg-muted flex items-center justify-center text-muted-foreground mb-4">
                                <UploadIcon className="w-6 h-6" />
                            </div>
                            <h3 className="font-medium text-foreground mb-1">Click or drag image here</h3>
                            <p className="text-sm text-muted-foreground">
                                Supports standard image formats (JPEG, PNG, WebP)
                            </p>
                        </>
                    )}
                </div>
            ) : (
                <div className="flex-1 flex flex-col gap-4">
                    <div className="flex-1 bg-muted rounded-xl border border-border overflow-hidden flex items-center justify-center relative group p-2">
                        <img src={uploadPreview} alt="Uploaded" className="max-w-full max-h-full object-contain rounded-md" />
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={clearPreview}
                            className="absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity shadow-sm"
                        >
                            Clear
                        </Button>
                    </div>
                    <div className="flex justify-end">
                        <Button onClick={() => { onInsertImage(uploadPreview); onClose(); }}>
                            Insert Image
                        </Button>
                    </div>
                </div>
            )}
        </TabsContent>
    );
}
