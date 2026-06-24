import { Form, Head, router, useForm, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { Loader2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
    username,
    isCollectionPublic,
    isWishlistPublic,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    username: string | null;
    isCollectionPublic: boolean;
    isWishlistPublic: boolean;
}) {
    const { auth } = usePage<PageProps>().props;
    const getInitials = useInitials();
    const avatarRef = useRef<HTMLInputElement>(null);
    const [avatarBusy, setAvatarBusy] = useState(false);
    const handle = auth.user.username || auth.user.name;

    const onAvatarFile = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];

        if (!file) {
            return;
        }

        router.post(
            '/settings/avatar',
            { avatar: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setAvatarBusy(true),
                onFinish: () => {
                    setAvatarBusy(false);

                    if (avatarRef.current) {
                        avatarRef.current.value = '';
                    }
                },
            },
        );
    };

    const removeAvatar = () =>
        router.delete('/settings/avatar', { preserveScroll: true });

    const collection = useForm({
        username: username ?? '',
        is_collection_public: isCollectionPublic,
        is_wishlist_public: isWishlistPublic,
    });

    const saveCollection = (e: React.FormEvent) => {
        e.preventDefault();
        collection.patch('/settings/collection', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Profile settings" />

            <h1 className="sr-only">Profile settings</h1>

            <div className="mb-6 space-y-4">
                <Heading
                    variant="small"
                    title="Avatar"
                    description="Your public profile picture, shown across the site."
                />
                <div className="flex items-center gap-4">
                    <Avatar className="size-16 rounded-xl">
                        <AvatarImage
                            src={auth.user.avatar ?? undefined}
                            alt={handle}
                        />
                        <AvatarFallback className="rounded-xl bg-primary/15 text-lg font-bold text-primary">
                            {getInitials(handle)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="flex gap-2">
                        <input
                            ref={avatarRef}
                            type="file"
                            accept="image/*"
                            className="hidden"
                            onChange={onAvatarFile}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => avatarRef.current?.click()}
                            disabled={avatarBusy}
                        >
                            {avatarBusy ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Upload className="size-4" />
                            )}
                            Upload
                        </Button>
                        {auth.user.avatar && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={removeAvatar}
                                disabled={avatarBusy}
                            >
                                Remove
                            </Button>
                        )}
                    </div>
                </div>
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profile"
                    description="Update your name and email address"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Full name"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="Email address"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="bio">Bio</Label>
                                <textarea
                                    id="bio"
                                    name="bio"
                                    rows={3}
                                    maxLength={300}
                                    defaultValue={auth.user.bio ?? ''}
                                    placeholder="A line or two about you and your collection."
                                    className="mt-1 block w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                />
                                <InputError className="mt-2" message={errors.bio} />
                            </div>

                            <div className="grid gap-2 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="location">Location</Label>
                                    <Input
                                        id="location"
                                        name="location"
                                        defaultValue={auth.user.location ?? ''}
                                        placeholder="City, Country"
                                    />
                                    <InputError message={errors.location} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="website">Website</Label>
                                    <Input
                                        id="website"
                                        name="website"
                                        defaultValue={auth.user.website ?? ''}
                                        placeholder="example.com"
                                    />
                                    <InputError message={errors.website} />
                                </div>
                            </div>

                            <div className="grid gap-2 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="x_handle">X (Twitter)</Label>
                                    <Input
                                        id="x_handle"
                                        name="x_handle"
                                        defaultValue={auth.user.x_handle ?? ''}
                                        placeholder="handle"
                                    />
                                    <InputError message={errors.x_handle} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="instagram_handle">
                                        Instagram
                                    </Label>
                                    <Input
                                        id="instagram_handle"
                                        name="instagram_handle"
                                        defaultValue={
                                            auth.user.instagram_handle ?? ''
                                        }
                                        placeholder="handle"
                                    />
                                    <InputError
                                        message={errors.instagram_handle}
                                    />
                                </div>
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            Your email address is unverified.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Click here to re-send the
                                                verification email.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                A new verification link has been
                                                sent to your email address.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Public sharing"
                    description="Share your collection and/or wishlist at a public URL."
                />

                <form onSubmit={saveCollection} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="username">Username</Label>
                        {username ? (
                            <>
                                <Input
                                    id="username"
                                    value={username}
                                    readOnly
                                    disabled
                                />
                                <p className="text-xs text-muted-foreground">
                                    Your username is permanent and can't be
                                    changed. Public URL:{' '}
                                    <span className="font-medium text-foreground">
                                        /collection/{username}
                                    </span>
                                </p>
                            </>
                        ) : (
                            <>
                                <Input
                                    id="username"
                                    value={collection.data.username}
                                    onChange={(e) =>
                                        collection.setData(
                                            'username',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="your-handle"
                                    autoComplete="off"
                                />
                                <InputError
                                    message={collection.errors.username}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Choose a permanent username — it can't be
                                    changed later.
                                </p>
                            </>
                        )}
                    </div>

                    <label className="flex items-center gap-2">
                        <Checkbox
                            checked={collection.data.is_collection_public}
                            onCheckedChange={(v) =>
                                collection.setData(
                                    'is_collection_public',
                                    Boolean(v),
                                )
                            }
                        />
                        <span className="text-sm">
                            Make my collection public
                        </span>
                    </label>

                    <label className="flex items-center gap-2">
                        <Checkbox
                            checked={collection.data.is_wishlist_public}
                            onCheckedChange={(v) =>
                                collection.setData(
                                    'is_wishlist_public',
                                    Boolean(v),
                                )
                            }
                        />
                        <span className="text-sm">
                            Make my wishlist public
                        </span>
                    </label>
                    <InputError
                        message={collection.errors.is_collection_public}
                    />

                    <Button disabled={collection.processing}>Save</Button>
                </form>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
