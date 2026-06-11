import { Head } from '@inertiajs/react';

/**
 * Privacy Policy — public legal page. Chrome (header/footer) is provided by the
 * AppShell layout. Plain-language template; have it reviewed by counsel before
 * relying on it.
 */
const EFFECTIVE_DATE = 'June 11, 2026';

const sections: { heading: string; body: string[] }[] = [
    {
        heading: '1. Overview',
        body: [
            'This Privacy Policy explains what information CardFoo ("we", "us", or "our") collects, how we use it, and the choices you have. By using CardFoo (the "Service"), you agree to this policy.',
        ],
    },
    {
        heading: '2. Information we collect',
        body: [
            'Account information: your name, email address, and authentication details (including passkeys or two-factor settings) when you register.',
            'Collection and usage data: the items, conditions, costs, notes, and preferences you add, and how you interact with the Service.',
            'Card images: images you upload or capture for scanning and identification.',
            'Technical data: device, browser, IP address, and log information collected automatically to operate and secure the Service.',
        ],
    },
    {
        heading: '3. How we use information',
        body: [
            'We use information to provide and improve the Service, identify and value items, maintain your collection and account, process payments, prevent fraud and abuse, communicate with you, and comply with legal obligations.',
        ],
    },
    {
        heading: '4. Card images and AI processing',
        body: [
            'When you use scanning features, images may be sent to third-party AI providers to identify the item. We use these images to return a result and operate the feature; we do not sell them. Identification may be imperfect, and you confirm matches before they are saved.',
        ],
    },
    {
        heading: '5. Cookies and local storage',
        body: [
            'We use cookies and similar local storage to keep you signed in, remember preferences such as theme and sidebar state, and secure the Service. You can control cookies through your browser, though some features may not work without them. We do not use them to sell your personal information.',
        ],
    },
    {
        heading: '6. Service providers and third parties',
        body: [
            'We share information with vendors who help us run the Service, under agreements that limit their use of it — for example, hosting and infrastructure, payment processing, AI-based card identification, and market and catalog data providers. We may disclose information if required by law or to protect rights, safety, and the integrity of the Service.',
            'We do not sell your personal information.',
        ],
    },
    {
        heading: '7. Affiliate and outbound links',
        body: [
            'The Service contains links to third-party sites, some of which are affiliate links. Those sites have their own privacy practices, which we do not control. Review their policies before providing information.',
        ],
    },
    {
        heading: '8. Data retention',
        body: [
            'We keep information for as long as your account is active or as needed to provide the Service, then retain and delete it according to our legal and operational requirements. You may request deletion as described below.',
        ],
    },
    {
        heading: '9. Security',
        body: [
            'We use reasonable technical and organizational measures to protect your information. No method of transmission or storage is completely secure, so we cannot guarantee absolute security.',
        ],
    },
    {
        heading: '10. Your rights and choices',
        body: [
            'Depending on where you live, you may have rights to access, correct, export, or delete your personal information, and to object to or restrict certain processing. You can update much of your information in your account settings, or contact us to exercise these rights. We will not discriminate against you for doing so.',
        ],
    },
    {
        heading: '11. Children’s privacy',
        body: [
            'The Service is not directed to children under 13, and we do not knowingly collect personal information from them. If you believe a child has provided us information, contact us and we will delete it.',
        ],
    },
    {
        heading: '12. International users',
        body: [
            'We operate in the United States and may process and store information there or in other countries. By using the Service, you understand your information may be transferred to locations with different data-protection laws than your own.',
        ],
    },
    {
        heading: '13. Changes to this policy',
        body: [
            'We may update this policy from time to time. If we make material changes, we will take reasonable steps to notify you and will update the date above. Your continued use of the Service after changes take effect constitutes acceptance.',
        ],
    },
    {
        heading: '14. Contact',
        body: [
            'Questions or requests about your privacy? Email us at support@cardfoo.com.',
        ],
    },
];

export default function Privacy() {
    return (
        <>
            <Head title="Privacy Policy" />

            <section className="mx-auto w-full max-w-3xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20">
                <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                    Privacy Policy
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
