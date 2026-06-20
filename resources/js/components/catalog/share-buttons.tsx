import { Check, Copy, Share2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';

/**
 * Social share row for a card page. Shares the current (canonical slug) URL with
 * card-specific title/text. X, Facebook, Reddit, copy-link, and — where the
 * browser supports it — the native share sheet.
 */
export function ShareButtons({ title, text }: { title: string; text: string }) {
    const [copied, setCopied] = useState(false);

    const currentUrl = () =>
        typeof window !== 'undefined' ? window.location.href : '';

    const popup = (href: string) =>
        window.open(
            href,
            '_blank',
            'noopener,noreferrer,width=600,height=600',
        );

    const shareX = () =>
        popup(
            `https://twitter.com/intent/tweet?text=${encodeURIComponent(text)}&url=${encodeURIComponent(currentUrl())}`,
        );

    const shareFacebook = () =>
        popup(
            `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(currentUrl())}`,
        );

    const shareReddit = () =>
        popup(
            `https://www.reddit.com/submit?url=${encodeURIComponent(currentUrl())}&title=${encodeURIComponent(title)}`,
        );

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(currentUrl());
            setCopied(true);
            toast.success('Link copied.');
            setTimeout(() => setCopied(false), 1500);
        } catch {
            toast.error('Could not copy link.');
        }
    };

    const canNativeShare =
        typeof navigator !== 'undefined' && typeof navigator.share === 'function';

    const nativeShare = () =>
        navigator.share({ title, text, url: currentUrl() }).catch(() => {});

    return (
        <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-medium text-muted-foreground">
                Share
            </span>
            <Button
                size="sm"
                variant="outline"
                onClick={shareX}
                className="hover:bg-foreground hover:text-background"
                aria-label="Share on X"
            >
                X
            </Button>
            <Button
                size="sm"
                variant="outline"
                onClick={shareFacebook}
                className="hover:border-[#1877F2] hover:text-[#1877F2]"
                aria-label="Share on Facebook"
            >
                Facebook
            </Button>
            <Button
                size="sm"
                variant="outline"
                onClick={shareReddit}
                className="hover:border-[#FF4500] hover:text-[#FF4500]"
                aria-label="Share on Reddit"
            >
                Reddit
            </Button>
            <Button size="sm" variant="outline" onClick={copy}>
                {copied ? (
                    <Check className="size-4" />
                ) : (
                    <Copy className="size-4" />
                )}
                Copy link
            </Button>
            {canNativeShare && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={nativeShare}
                    aria-label="Share"
                >
                    <Share2 className="size-4" />
                    Share
                </Button>
            )}
        </div>
    );
}
