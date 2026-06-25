import { Copy, Share2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * A small Share control: copy the link to the clipboard, or open an X (Twitter)
 * compose intent. `url` defaults to the current page at click time.
 */
export function ShareButton({
    url,
    text,
    label = 'Share',
}: {
    url?: string;
    text?: string;
    label?: string;
}) {
    const href = () =>
        url ?? (typeof window !== 'undefined' ? window.location.href : '');

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(href());
            toast.success('Link copied');
        } catch {
            toast.error('Could not copy the link');
        }
    };

    const shareX = () => {
        const params = new URLSearchParams({ url: href(), text: text ?? '' });
        window.open(
            `https://x.com/intent/tweet?${params.toString()}`,
            '_blank',
            'noopener,noreferrer',
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                    <Share2 className="size-4" />
                    {label}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={copy}>
                    <Copy className="size-4" />
                    Copy link
                </DropdownMenuItem>
                <DropdownMenuItem onClick={shareX}>
                    <XGlyph className="size-4" />
                    Share on X
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function XGlyph({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            className={className}
            fill="currentColor"
            aria-hidden="true"
        >
            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
        </svg>
    );
}
