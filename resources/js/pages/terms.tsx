import { Head } from '@inertiajs/react';

/**
 * Terms of Use — public legal page. Chrome (header/footer) is provided by the
 * AppShell layout. Plain-language template; have it reviewed by counsel before
 * relying on it.
 */
const EFFECTIVE_DATE = 'June 11, 2026';

const sections: { heading: string; body: string[] }[] = [
    {
        heading: '1. Acceptance of these terms',
        body: [
            'CardFoo ("CardFoo", "we", "us", or "our") provides tools to catalog, value, track, and trade trading cards and other collectibles (the "Service"). By creating an account or otherwise using the Service, you agree to these Terms of Use. If you do not agree, do not use the Service.',
        ],
    },
    {
        heading: '2. Eligibility',
        body: [
            'You must be at least 13 years old to use the Service, and old enough to form a binding contract in your jurisdiction. If you use the Service on behalf of an organization, you represent that you are authorized to bind that organization to these terms.',
        ],
    },
    {
        heading: '3. Your account',
        body: [
            'You are responsible for the activity under your account and for keeping your credentials secure. Provide accurate information and keep it current. Notify us promptly of any unauthorized use.',
        ],
    },
    {
        heading: '4. Acceptable use',
        body: [
            'Do not misuse the Service. This includes: breaking the law; infringing others’ rights; scraping, overloading, or reverse-engineering the Service; uploading malware; attempting to access data that is not yours; or using the Service to deceive or defraud other users.',
        ],
    },
    {
        heading: '5. Valuations and market data',
        body: [
            'Values, price ranges, confidence scores, and similar figures are estimates for informational purposes only. They are not appraisals, offers to buy or sell, or financial advice.',
            'A card is only worth what a buyer will actually pay. Prices are subjective and change constantly. Our figures are derived from past and listed sales that may be incomplete, delayed, sampled, or inaccurate, and they may be wrong. Do not rely on them for buying, selling, insurance, tax, or other financial decisions. You are solely responsible for your own valuations and transactions.',
        ],
    },
    {
        heading: '6. Card scanning',
        body: [
            'When you use scanning features, images you submit are processed (including by third-party AI providers) to identify the item. Identification may be incorrect; you are responsible for confirming the exact item, variant, and condition before adding it to your collection or acting on it.',
        ],
    },
    {
        heading: '7. Collection and portfolio data',
        body: [
            'You may record items, conditions, costs, and notes. This information is provided by you and for your own use; we do not guarantee its accuracy and it does not constitute financial reporting or advice.',
        ],
    },
    {
        heading: '8. Marketplace and transactions',
        body: [
            'Any marketplace features let users deal directly with one another. Unless expressly stated, CardFoo is not a party to those transactions, does not take possession of items, and does not guarantee any listing, buyer, seller, item condition, payment, or delivery. You transact at your own risk and are responsible for complying with applicable law and taxes.',
        ],
    },
    {
        heading: '9. Payments and subscriptions',
        body: [
            'Paid plans are billed through our third-party payment provider, which may act as merchant of record. By subscribing you authorize recurring charges until you cancel. Fees are stated at purchase and may change with notice. Except where required by law or expressly stated, payments are non-refundable. You can cancel at any time; access continues through the end of the paid period.',
        ],
    },
    {
        heading: '10. Third-party services and data',
        body: [
            'The Service incorporates data and links from third parties (for example, marketplaces and catalog and pricing sources). We do not control and are not responsible for third-party content, services, or accuracy. Some outbound links are affiliate links, and we may earn a commission when you buy through them at no extra cost to you.',
        ],
    },
    {
        heading: '11. Intellectual property',
        body: [
            'The Service, including the CardFoo name, logo, and original content, is owned by CardFoo and protected by law. We grant you a limited, non-exclusive, non-transferable, revocable license to use the Service for its intended purpose. Product names and images belong to their respective owners and are used for identification only.',
        ],
    },
    {
        heading: '12. Your content',
        body: [
            'You retain ownership of content you submit. You grant us a worldwide, non-exclusive license to host, process, and display that content as needed to operate and improve the Service. You are responsible for your content and represent that you have the rights to submit it.',
        ],
    },
    {
        heading: '13. Disclaimers',
        body: [
            'The Service is provided "as is" and "as available" without warranties of any kind, whether express or implied, including merchantability, fitness for a particular purpose, accuracy, and non-infringement. We do not warrant that the Service will be uninterrupted, error-free, or that any data or valuation is accurate or complete.',
        ],
    },
    {
        heading: '14. Limitation of liability',
        body: [
            'To the fullest extent permitted by law, CardFoo and its affiliates will not be liable for any indirect, incidental, special, consequential, or punitive damages, or for lost profits, data, or goodwill, arising from your use of the Service or reliance on any valuation. Our total liability for any claim will not exceed the greater of the amount you paid us in the twelve months before the claim or USD $50.',
        ],
    },
    {
        heading: '15. Indemnification',
        body: [
            'You agree to indemnify and hold CardFoo harmless from claims, losses, and expenses (including reasonable legal fees) arising from your use of the Service, your content, or your violation of these terms or the law.',
        ],
    },
    {
        heading: '16. Termination',
        body: [
            'You may stop using the Service at any time. We may suspend or terminate your access if you violate these terms or to protect the Service or other users. Provisions that by their nature should survive termination will survive.',
        ],
    },
    {
        heading: '17. Changes to these terms',
        body: [
            'We may update these terms from time to time. If we make material changes, we will take reasonable steps to notify you. Your continued use of the Service after changes take effect constitutes acceptance.',
        ],
    },
    {
        heading: '18. Governing law',
        body: [
            'These terms are governed by the laws of the United States and the state in which CardFoo is established, without regard to conflict-of-laws rules. Disputes will be resolved in the courts located there, unless applicable law requires otherwise.',
        ],
    },
    {
        heading: '19. Contact',
        body: ['Questions about these terms? Email us at support@cardfoo.com.'],
    },
];

export default function Terms() {
    return (
        <>
            <Head title="Terms of Use" />

            <section className="mx-auto w-full max-w-3xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20">
                <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                    Terms of Use
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Last updated {EFFECTIVE_DATE}
                </p>

                <div className="mt-10 space-y-8">
                    {sections.map((section) => (
                        <div key={section.heading}>
                            <h2 className="text-lg font-semibold">
                                {section.heading}
                            </h2>
                            {section.body.map((paragraph, index) => (
                                <p
                                    key={index}
                                    className="mt-3 text-sm leading-relaxed text-muted-foreground"
                                >
                                    {paragraph}
                                </p>
                            ))}
                        </div>
                    ))}
                </div>
            </section>
        </>
    );
}
