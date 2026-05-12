import { useState, useRef } from 'react';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';

export interface UseWriterImageUploadReturn {
    isUploading: boolean;
    isDragActive: boolean;
    uploadPreview: string | null;
    fileInputRef: React.RefObject<HTMLInputElement>;
    handleFileSelect: (e: React.ChangeEvent<HTMLInputElement>) => Promise<void>;
    handleDrop: (e: React.DragEvent) => Promise<void>;
    handleDragEnter: (e: React.DragEvent) => void;
    handleDragLeave: (e: React.DragEvent) => void;
    clearPreview: () => void;
}

export function useWriterImageUpload(): UseWriterImageUploadReturn {
    const [isUploading, setIsUploading] = useState(false);
    const [isDragActive, setIsDragActive] = useState(false);
    const [uploadPreview, setUploadPreview] = useState<string | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const uploadImageMutation = trpc.writer.uploadImage.useMutation();

    const processFileUpload = async (file: File) => {
        setIsUploading(true);
        setUploadPreview(null);
        try {
            const reader = new FileReader();
            const base64 = await new Promise<string>((resolve, reject) => {
                reader.onload = () => resolve(reader.result as string);
                reader.onerror = reject;
                reader.readAsDataURL(file);
            });

            const result = await uploadImageMutation.mutateAsync({
                fileData: base64,
                filename: file.name,
                mimeType: file.type,
            });

            setUploadPreview(result.url);
            toast.success('Image uploaded successfully');
        } catch (error) {
            console.error(error);
            toast.error('Failed to upload image');
        } finally {
            setIsUploading(false);
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        }
    };

    const handleFileSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        await processFileUpload(file);
    };

    const handleDrop = async (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragActive(false);
        const file = e.dataTransfer.files?.[0];
        if (!file || !file.type.startsWith('image/')) return;
        await processFileUpload(file);
    };

    const handleDragEnter = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragActive(true);
    };

    const handleDragLeave = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragActive(false);
    };

    const clearPreview = () => setUploadPreview(null);

    return {
        isUploading,
        isDragActive,
        uploadPreview,
        fileInputRef,
        handleFileSelect,
        handleDrop,
        handleDragEnter,
        handleDragLeave,
        clearPreview
    };
}
