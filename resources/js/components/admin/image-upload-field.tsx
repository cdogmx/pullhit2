import { Loader2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/** Laravel XSRF-TOKEN cookie for same-origin POSTs. */
function xsrfToken(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

/**
 * Image input that never hot-links: a URL is fetched and a file is uploaded —
 * both stored in our bucket via /admin/images, which returns our hosted URL.
 * `value` is the stored URL; `onChange` receives the new one.
 */
export function ImageUploadField({
    value,
    onChange,
}: {
    value: string;
    onChange: (url: string) => void;
}) {
    const [url, setUrl] = useState('');
    const [busy, setBusy] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);

    const send = async (body: FormData) => {
        setBusy(true);
        try {
            const res = await fetch('/admin/images', {
                method: 'POST',
                body,
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            });

            if (!res.ok) {
                toast.error('Could not store that image.');

                return;
            }

            const data = await res.json();
            onChange(data.url);
            setUrl('');
        } catch {
            toast.error('Image upload failed.');
        } finally {
            setBusy(false);
        }
    };

    const fetchUrl = () => {
        const form = new FormData();
        form.append('url', url);
        send(form);
    };

    const onFile = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            const form = new FormData();
            form.append('file', file);
            send(form);
        }
        e.target.value = '';
    };

    return (
        <div className="grid gap-2">
            {value && (
                <img
                    src={value}
                    alt="Product"
                    className="h-24 w-auto rounded-md border border-border bg-muted object-contain"
                />
            )}
            <div className="flex gap-2">
                <Input
                    placeholder="Paste image URL"
                    value={url}
                    onChange={(e) => setUrl(e.target.value)}
                    disabled={busy}
                />
                <Button
                    type="button"
                    variant="secondary"
                    onClick={fetchUrl}
                    disabled={!url.trim() || busy}
                >
                    {busy ? <Loader2 className="size-4 animate-spin" /> : 'Fetch'}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => fileRef.current?.click()}
                    disabled={busy}
                >
                    <Upload className="size-4" /> Upload
                </Button>
                <input
                    ref={fileRef}
                    type="file"
                    accept="image/*"
                    className="hidden"
                    onChange={onFile}
                />
            </div>
            {value && (
                <button
                    type="button"
                    className="justify-self-start text-xs text-muted-foreground hover:text-foreground"
                    onClick={() => onChange('')}
                >
                    Remove image
                </button>
            )}
        </div>
    );
}
