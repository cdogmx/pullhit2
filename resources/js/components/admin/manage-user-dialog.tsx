import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import type { AdminUser } from '@/types';

type Tier = { value: string; label: string };

/** Admin: manage a single user — tier, admin flag, credits, subscription, ban. */
export function ManageUserDialog({
    user,
    tiers,
    open,
    onOpenChange,
}: {
    user: AdminUser | null;
    tiers: Tier[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                {/* Keyed by user so the form re-initialises from props on each open. */}
                {user && (
                    <ManageUserForm
                        key={user.id}
                        user={user}
                        tiers={tiers}
                        onOpenChange={onOpenChange}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function ManageUserForm({
    user,
    tiers,
    onOpenChange,
}: {
    user: AdminUser;
    tiers: Tier[];
    onOpenChange: (open: boolean) => void;
}) {
    const [tier, setTier] = useState(user.tier);
    const [isAdmin, setIsAdmin] = useState(user.is_admin);
    const [creditDelta, setCreditDelta] = useState('');
    const [banReason, setBanReason] = useState('');
    const [confirmBan, setConfirmBan] = useState(false);
    const [busy, setBusy] = useState(false);

    const opts = { preserveScroll: true };

    const saveAccount = () =>
        router.patch(
            `/admin/users/${user.id}`,
            { membership_tier: tier, is_admin: isAdmin },
            { ...opts, onSuccess: () => toast.success('User updated.') },
        );

    const applyCredits = () => {
        const delta = parseInt(creditDelta, 10);

        if (!delta) {
            return;
        }

        router.post(
            `/admin/users/${user.id}/credits`,
            { credits: delta },
            {
                ...opts,
                onSuccess: () => {
                    toast.success('Credits adjusted.');
                    setCreditDelta('');
                },
            },
        );
    };

    const cancelSub = () =>
        router.post(
            `/admin/users/${user.id}/cancel`,
            {},
            {
                ...opts,
                onSuccess: () => toast.success('Subscription cancelled.'),
            },
        );

    const ban = () => {
        setBusy(true);
        router.post(
            `/admin/users/${user.id}/ban`,
            { reason: banReason },
            {
                ...opts,
                onSuccess: () => {
                    toast.success('User banned.');
                    setConfirmBan(false);
                    onOpenChange(false);
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const unban = () =>
        router.post(
            `/admin/users/${user.id}/unban`,
            {},
            { ...opts, onSuccess: () => toast.success('User reinstated.') },
        );

    return (
        <>
            <DialogHeader>
                <DialogTitle>{user.name}</DialogTitle>
                <DialogDescription>
                    {user.email}
                    {user.username ? ` · @${user.username}` : ''}
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-4 py-2">
                {/* Membership + role */}
                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Membership tier</Label>
                        <Select value={tier} onValueChange={setTier}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {tiers.map((t) => (
                                    <SelectItem key={t.value} value={t.value}>
                                        {t.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={isAdmin}
                            onCheckedChange={(c) => setIsAdmin(c === true)}
                        />
                        Administrator
                    </label>
                    <Button size="sm" onClick={saveAccount}>
                        Save account
                    </Button>
                </div>

                <Separator />

                {/* Scan credits */}
                <div className="grid gap-1.5">
                    <Label className="text-xs">
                        Scan credits (current: {user.credits.toLocaleString()})
                    </Label>
                    <div className="flex gap-2">
                        <Input
                            type="number"
                            placeholder="+/- amount"
                            value={creditDelta}
                            onChange={(e) => setCreditDelta(e.target.value)}
                        />
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={applyCredits}
                            disabled={!creditDelta}
                        >
                            Apply
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Use a negative number to deduct. Won't go below zero.
                    </p>
                </div>

                {/* Subscription */}
                {user.has_subscription && (
                    <>
                        <Separator />
                        <div className="grid gap-1.5">
                            <Label className="text-xs">Subscription</Label>
                            {user.cancel_scheduled ? (
                                <p className="text-sm text-muted-foreground">
                                    Cancellation already scheduled.
                                </p>
                            ) : (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={cancelSub}
                                >
                                    Cancel at period end
                                </Button>
                            )}
                        </div>
                    </>
                )}

                <Separator />

                {/* Ban */}
                <div className="grid gap-1.5">
                    <Label className="text-xs text-red-600 dark:text-red-400">
                        Danger zone
                    </Label>
                    {user.banned_at ? (
                        <>
                            <p className="text-sm text-muted-foreground">
                                Banned
                                {user.banned_reason
                                    ? ` — ${user.banned_reason}`
                                    : ''}
                                .
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={unban}
                            >
                                Reinstate user
                            </Button>
                        </>
                    ) : (
                        <>
                            <Textarea
                                placeholder="Reason (optional)"
                                value={banReason}
                                onChange={(e) => setBanReason(e.target.value)}
                            />
                            <Button
                                variant="destructive"
                                size="sm"
                                onClick={() => setConfirmBan(true)}
                            >
                                Ban user
                            </Button>
                        </>
                    )}
                </div>
            </div>

            <ConfirmDialog
                open={confirmBan}
                onOpenChange={setConfirmBan}
                title={`Ban ${user.name}?`}
                description="They will be locked out immediately."
                confirmLabel="Ban user"
                destructive
                busy={busy}
                onConfirm={ban}
            />
        </>
    );
}
