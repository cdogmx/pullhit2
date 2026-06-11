import { ChevronDown, ShoppingCart } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { EbayOption } from '@/types';

const REL = 'nofollow sponsored noopener';

/**
 * Affiliate "Shop on eBay" split button — the primary option (Near Mint) on the
 * left, a dropdown of other conditions/grades on the right.
 */
export function EbayShopButton({
    options,
    className,
}: {
    options: EbayOption[];
    className?: string;
}) {
    const primary = options[0];

    if (!primary) {
        return null;
    }

    const groups = options.reduce<Record<string, EbayOption[]>>((acc, o) => {
        (acc[o.group] ??= []).push(o);

        return acc;
    }, {});

    return (
        <div className={cn('flex', className)}>
            <Button asChild className="flex-1 rounded-r-none">
                <a href={primary.url} target="_blank" rel={REL}>
                    <ShoppingCart className="size-4" />
                    Shop on eBay · {primary.label}
                </a>
            </Button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        className="rounded-l-none border-l border-l-white/20 px-2"
                        aria-label="Choose condition or grade"
                    >
                        <ChevronDown className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    className="max-h-80 overflow-y-auto"
                >
                    {Object.entries(groups).map(([group, opts], gi) => (
                        <div key={group}>
                            {gi > 0 && <DropdownMenuSeparator />}
                            <DropdownMenuLabel className="text-xs text-muted-foreground">
                                {group}
                            </DropdownMenuLabel>
                            {opts.map((o) => (
                                <DropdownMenuItem key={o.label} asChild>
                                    <a href={o.url} target="_blank" rel={REL}>
                                        {o.label}
                                    </a>
                                </DropdownMenuItem>
                            ))}
                        </div>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}
