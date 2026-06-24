import { Head, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

/** One-time username picker shown after a social signup (no username yet). */
export default function ChooseUsername({ suggestion }: { suggestion: string }) {
    const form = useForm({ username: suggestion ?? '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/choose-username');
    };

    return (
        <>
            <Head title="Choose a username" />

            <form onSubmit={submit} className="flex flex-col gap-6">
                <div className="grid gap-2">
                    <Label htmlFor="username">Username</Label>
                    <Input
                        id="username"
                        name="username"
                        required
                        autoFocus
                        value={form.data.username}
                        onChange={(e) => form.setData('username', e.target.value)}
                        placeholder="username"
                        pattern="[A-Za-z0-9_\-]{3,30}"
                    />
                    <p className="text-xs text-muted-foreground">
                        Your public handle (collection &amp; wishlist URLs).
                        Letters, numbers, dashes and underscores; 3–30 characters.
                    </p>
                    <InputError message={form.errors.username} />
                </div>

                <Button
                    type="submit"
                    className="w-full"
                    disabled={form.processing}
                >
                    {form.processing && <Spinner />}
                    Continue
                </Button>
            </form>
        </>
    );
}

ChooseUsername.layout = {
    title: 'Choose a username',
    description: 'Pick a public handle to finish setting up your account',
};
