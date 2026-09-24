import {
    Camera,
    Crop,
    Layers,
    Lightbulb,
    Maximize,
    Square,
    Sun,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * How to photograph a card for the grading read.
 *
 * Every rule here exists because of something the pipeline does, and each one
 * says which — a rule with a reason gets followed, and a reason also tells
 * somebody how far they can bend it. Generic photography advice would be both
 * longer and less use: what matters is not a nice picture, it is that the
 * corners can be found, the card can be flattened, and the frames can be
 * differenced against each other.
 */

type Rule = {
    Icon: LucideIcon;
    title: string;
    body: string;
    because: string;
};

const RULES: Rule[] = [
    {
        Icon: Square,
        title: 'Put it on a background that is not card-coloured',
        body: 'A plain, flat surface a few shades away from the card’s border. A white-bordered card on a white desk is the one setup that reliably fails.',
        because: 'The card is found by its edges. No contrast at the edge, no edge.',
    },
    {
        Icon: Maximize,
        title: 'Whole card in frame, with room around it',
        body: 'Fill most of the frame but leave a margin on all four sides. Do not crop before uploading.',
        because: 'A corner touching the frame edge cannot be located, and the crop you draw here is the one that gets measured.',
    },
    {
        Icon: Camera,
        title: 'Camera roughly square above the card',
        body: 'Hold the phone flat over the card rather than at an angle. It does not have to be perfect.',
        because: 'The card is flattened to a true 2.5 × 3.5 rectangle before anything is measured, so a moderate tilt is corrected — but a steep one stretches the pixels it has to guess from.',
    },
    {
        Icon: Lightbulb,
        title: 'Move the light, never the card',
        body: 'Take the first shot, then move a lamp — or your phone’s flashlight in your other hand — and take another without touching the card. Three or four frames is plenty.',
        because: 'Surface is read by comparing frames: the artwork stays put and the glare travels. That comparison is the whole method, and it needs the card in the same place each time.',
    },
    {
        Icon: Sun,
        title: 'No direct flash',
        body: 'A lamp off to one side, a window, a flashlight held at an angle. Anything but the flash next to the lens.',
        because: 'A head-on flash puts one blown-out hotspot in the middle of every frame, in the same place each time — the one kind of glare that moving the light cannot separate.',
    },
    {
        Icon: Layers,
        title: 'Out of the sleeve if you can',
        body: 'Penny sleeve, toploader and one-touch all read worse than bare card. If you will not take it out, at least get the case square to the camera.',
        because: 'Plastic adds its own edges and its own reflections, and the detector cannot tell a toploader’s corner from the card’s.',
    },
    {
        Icon: Crop,
        title: 'Front and back are separate sets',
        body: 'Shoot the front, then flip and shoot the back the same way. Centering is graded on both, to different tolerances.',
        because: 'Every standard is far more forgiving of the back — PSA allows 75/25 there against 55/45 on the front for a 10. Skipping the back means guessing at half the verdict.',
    },
];

function RuleCard({ rule }: { rule: Rule }) {
    return (
        <li className="flex gap-3 rounded-lg border border-border bg-card p-4">
            <rule.Icon
                className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                strokeWidth={1.5}
                aria-hidden
            />
            <div className="flex flex-col gap-1.5">
                <h3 className="text-sm font-medium">{rule.title}</h3>
                <p className="text-sm text-muted-foreground">{rule.body}</p>
                <p className="text-xs text-muted-foreground/80 italic">
                    {rule.because}
                </p>
            </div>
        </li>
    );
}

export function PhotoGuide({ className }: { className?: string }) {
    return (
        <div className={cn('flex flex-col gap-6', className)}>
            <ul className="grid gap-3 sm:grid-cols-2">
                {RULES.map((rule) => (
                    <RuleCard key={rule.title} rule={rule} />
                ))}
            </ul>

            <div className="flex flex-col gap-3 rounded-lg border border-border bg-muted/40 p-4">
                <h3 className="text-sm font-medium">Worth knowing</h3>
                <ul className="flex list-disc flex-col gap-1.5 pl-5 text-sm text-muted-foreground">
                    <li>
                        <strong className="font-medium text-foreground">
                            Megapixels stop helping quickly.
                        </strong>{' '}
                        The card is flattened to 700 pixels across before it is
                        measured, so past roughly a thousand pixels of card in
                        your photo the extra detail is thrown away. Getting
                        close matters; a better camera mostly does not.
                    </li>
                    <li>
                        <strong className="font-medium text-foreground">
                            One photo still gets you centering.
                        </strong>{' '}
                        It cannot get you surface — there is nothing to compare
                        it against — and the read will say so rather than
                        quietly scoring the surface anyway.
                    </li>
                    <li>
                        <strong className="font-medium text-foreground">
                            Send the original.
                        </strong>{' '}
                        No filters, no auto-enhance, no screenshots of the
                        photo. Up to 12 MB each. Editing moves exactly the
                        pixels being measured.
                    </li>
                    <li>
                        <strong className="font-medium text-foreground">
                            A shifted hand is recoverable.
                        </strong>{' '}
                        Each frame is located on its own, so the set does not
                        collapse if the card nudged. It is simply better when
                        it did not.
                    </li>
                </ul>
            </div>

            <p className="text-xs text-muted-foreground">
                This is a read of your photos, not a grade. It measures what a
                photograph can show — centering precisely, surface roughly,
                corners and edges not at all unless they are visible. No grading
                company has seen the card.
            </p>
        </div>
    );
}
