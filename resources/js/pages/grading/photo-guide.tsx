import { Head } from '@inertiajs/react';
import { PhotoGuide } from '@/components/grading/photo-guide';

/**
 * The photo guide as its own public page.
 *
 * Linked from the bench before anyone uploads, and from shared reports, which
 * land on people who have no account and are looking at somebody else's card.
 */
export default function PhotoGuidePage() {
    return (
        <>
            <Head title="How to photograph a card for grading" />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-10">
                <header className="flex flex-col gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        How to photograph a card
                    </h1>
                    <p className="text-muted-foreground">
                        Seven things, and the reason behind each. The read finds
                        your card’s four corners, flattens it to a true 2.5 × 3.5
                        rectangle, measures the borders off that, and reads the
                        surface by comparing your frames as the glare moves
                        across them. Everything below exists to make one of those
                        four steps possible.
                    </p>
                </header>

                <PhotoGuide />
            </div>
        </>
    );
}
