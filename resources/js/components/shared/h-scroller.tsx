import { ChevronLeft, ChevronRight } from 'lucide-react';
import {
    type ReactNode,
    useCallback,
    useEffect,
    useRef,
    useState,
} from 'react';
import { cn } from '@/lib/utils';

/**
 * A horizontal scroller with desktop hover arrows and click-drag, and native
 * touch/momentum scrolling on mobile. Arrows appear only when there's more to
 * scroll in that direction. Children should be `shrink-0` items.
 */
export function HScroller({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [canLeft, setCanLeft] = useState(false);
    const [canRight, setCanRight] = useState(false);

    const update = useCallback(() => {
        const el = ref.current;
        if (!el) {
            return;
        }
        setCanLeft(el.scrollLeft > 4);
        setCanRight(el.scrollLeft + el.clientWidth < el.scrollWidth - 4);
    }, []);

    useEffect(() => {
        const el = ref.current;
        if (!el) {
            return;
        }
        update();
        el.addEventListener('scroll', update, { passive: true });
        const ro = new ResizeObserver(update);
        ro.observe(el);

        return () => {
            el.removeEventListener('scroll', update);
            ro.disconnect();
        };
    }, [update]);

    const page = (dir: 1 | -1) => {
        ref.current?.scrollBy({
            left: dir * ref.current.clientWidth * 0.85,
            behavior: 'smooth',
        });
    };

    // Mouse click-drag (touch devices use native scrolling + momentum).
    const drag = useRef({ active: false, startX: 0, startLeft: 0, moved: false });

    const onPointerDown = (e: React.PointerEvent) => {
        if (e.pointerType !== 'mouse' || !ref.current) {
            return;
        }
        drag.current = {
            active: true,
            startX: e.clientX,
            startLeft: ref.current.scrollLeft,
            moved: false,
        };
    };

    const onPointerMove = (e: React.PointerEvent) => {
        const el = ref.current;
        if (!el || !drag.current.active) {
            return;
        }
        const dx = e.clientX - drag.current.startX;
        if (Math.abs(dx) > 4) {
            drag.current.moved = true;
        }
        el.scrollLeft = drag.current.startLeft - dx;
    };

    const endDrag = () => {
        drag.current.active = false;
    };

    // Swallow the click that ends a drag so a tile doesn't navigate.
    const onClickCapture = (e: React.MouseEvent) => {
        if (drag.current.moved) {
            e.preventDefault();
            e.stopPropagation();
            drag.current.moved = false;
        }
    };

    return (
        <div className="group/scroller relative">
            <ArrowButton
                dir="left"
                show={canLeft}
                onClick={() => page(-1)}
            />
            <ArrowButton
                dir="right"
                show={canRight}
                onClick={() => page(1)}
            />
            <div
                ref={ref}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={endDrag}
                onPointerLeave={endDrag}
                onClickCapture={onClickCapture}
                style={{ touchAction: 'pan-x' }}
                className={cn(
                    'flex snap-x gap-3 overflow-x-auto overscroll-x-contain pb-2',
                    '[-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
                    'cursor-grab active:cursor-grabbing select-none',
                    className,
                )}
            >
                {children}
            </div>
        </div>
    );
}

function ArrowButton({
    dir,
    show,
    onClick,
}: {
    dir: 'left' | 'right';
    show: boolean;
    onClick: () => void;
}) {
    const Icon = dir === 'left' ? ChevronLeft : ChevronRight;

    return (
        <button
            type="button"
            aria-label={dir === 'left' ? 'Scroll left' : 'Scroll right'}
            tabIndex={-1}
            onClick={onClick}
            className={cn(
                'absolute top-[42%] z-10 hidden size-9 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-background/90 text-foreground shadow-md backdrop-blur transition-opacity hover:bg-background md:flex',
                dir === 'left' ? '-left-3' : '-right-3',
                show
                    ? 'opacity-0 group-hover/scroller:opacity-100'
                    : 'pointer-events-none opacity-0',
            )}
        >
            <Icon className="size-5" />
        </button>
    );
}
