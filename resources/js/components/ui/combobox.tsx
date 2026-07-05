import { Check, ChevronsUpDown, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export type ComboboxOption = { value: string; label: string };

/**
 * A searchable single-select (type-ahead) built on plain elements — no popover
 * or command library. Drop-in for long option lists (sets, brands) where the
 * shadcn Select's scroll-only menu is unwieldy. Use an empty-string option as
 * the "All" / clear entry.
 */
export function Combobox({
    options,
    value,
    onChange,
    placeholder = 'Select…',
    searchPlaceholder = 'Search…',
    emptyText = 'No matches.',
    className,
    triggerClassName,
}: {
    options: ComboboxOption[];
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    className?: string;
    triggerClassName?: string;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [active, setActive] = useState(0);
    const ref = useRef<HTMLDivElement>(null);

    const selected = options.find((o) => o.value === value);

    const filtered = useMemo(() => {
        const needle = q.trim().toLowerCase();

        return needle
            ? options.filter((o) => o.label.toLowerCase().includes(needle))
            : options;
    }, [options, q]);

    // Close when clicking outside the widget.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onDown);

        return () => document.removeEventListener('mousedown', onDown);
    }, [open]);

    const openMenu = () => {
        setQ('');
        setActive(0);
        setOpen(true);
    };

    const choose = (v: string) => {
        onChange(v);
        setOpen(false);
    };

    const onKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Escape') {
            setOpen(false);
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => Math.min(i + 1, filtered.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();

            if (filtered[active]) {
                choose(filtered[active].value);
            }
        }
    };

    return (
        <div ref={ref} className={cn('relative', className)}>
            <button
                type="button"
                onClick={() => (open ? setOpen(false) : openMenu())}
                className={cn(
                    'flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 py-2 text-sm whitespace-nowrap shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30 dark:hover:bg-input/50',
                    triggerClassName,
                )}
            >
                <span
                    className={cn(
                        'truncate',
                        !selected && 'text-muted-foreground',
                    )}
                >
                    {selected ? selected.label : placeholder}
                </span>
                <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
            </button>

            {open && (
                <div className="absolute left-0 z-50 mt-1 w-max max-w-[20rem] min-w-full overflow-hidden rounded-md border border-border bg-popover text-popover-foreground shadow-md">
                    <div className="relative border-b border-border p-2">
                        <Search className="absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            autoFocus
                            value={q}
                            onChange={(e) => {
                                setQ(e.target.value);
                                setActive(0);
                            }}
                            onKeyDown={onKeyDown}
                            placeholder={searchPlaceholder}
                            className="h-8 w-full rounded-md border border-input bg-transparent pr-3 pl-8 text-sm outline-none focus-visible:border-ring"
                        />
                    </div>
                    <div className="max-h-60 overflow-y-auto p-1">
                        {filtered.length === 0 ? (
                            <p className="px-2 py-3 text-center text-sm text-muted-foreground">
                                {emptyText}
                            </p>
                        ) : (
                            filtered.map((o, i) => (
                                <button
                                    key={o.value}
                                    type="button"
                                    onClick={() => choose(o.value)}
                                    onMouseEnter={() => setActive(i)}
                                    className={cn(
                                        'flex w-full items-center justify-between gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
                                        i === active &&
                                            'bg-accent text-accent-foreground',
                                    )}
                                >
                                    <span className="truncate">{o.label}</span>
                                    {o.value === value && (
                                        <Check className="size-4 shrink-0" />
                                    )}
                                </button>
                            ))
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
