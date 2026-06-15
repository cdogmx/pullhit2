import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';

type NotificationType = {
    key: string;
    label: string;
    description: string;
    enabled: boolean;
};

export default function Notifications({ types }: { types: NotificationType[] }) {
    const form = useForm<{ preferences: Record<string, boolean> }>({
        preferences: Object.fromEntries(types.map((t) => [t.key, t.enabled])),
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/notifications', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Notification settings" />

            <h1 className="sr-only">Notification settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Notifications"
                    description="Choose which notifications you receive by email and in-app. Account and security emails (sign-in, password resets, receipts) are always sent."
                />

                <form onSubmit={submit} className="space-y-6">
                    {types.map((type) => (
                        <label
                            key={type.key}
                            className="flex items-start gap-3"
                        >
                            <Checkbox
                                className="mt-0.5"
                                checked={form.data.preferences[type.key]}
                                onCheckedChange={(v) =>
                                    form.setData('preferences', {
                                        ...form.data.preferences,
                                        [type.key]: Boolean(v),
                                    })
                                }
                            />
                            <span className="grid gap-0.5">
                                <span className="text-sm font-medium">
                                    {type.label}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {type.description}
                                </span>
                            </span>
                        </label>
                    ))}

                    <Button disabled={form.processing}>Save</Button>
                </form>
            </div>
        </>
    );
}
